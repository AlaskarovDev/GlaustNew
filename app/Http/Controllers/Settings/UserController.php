<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Mail\SystemMail;
use App\Models\Role;
use App\Models\User;
use App\Rules\TenantExists;
use App\Services\AuthLogger;
use App\Services\MailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Users are not globally tenant-scoped, so every lookup here goes through forTenant(). */
class UserController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $users = User::forTenant()->with('role')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")))
            ->when($request->filled('role_id'), fn ($w) => $w->where('role_id', $request->integer('role_id')))
            ->when($request->query('status') === 'inactive', fn ($w) => $w->where('is_active', false))
            ->when($request->query('status') === 'active', fn ($w) => $w->where('is_active', true))
            ->orderByDesc('is_active')->orderBy('name')->paginate(25)->withQueryString();

        $online = DB::table('sessions')->whereIn('user_id', $users->pluck('id'))->where('last_activity', '>=', now()->subMinutes(5)->getTimestamp())->pluck('user_id')->unique()->all();

        return view('settings.users.index', [
            'users' => $users, 'roles' => Role::orderBy('name')->pluck('name', 'id'), 'online' => $online,
            'limit' => tenant()->plan?->max_users, 'count' => User::forTenant()->where('is_active', true)->count(),
        ]);
    }

    public function create(): View|RedirectResponse
    {
        $this->authorize('users.create');
        if ($this->atLimit()) {
            return redirect()->route('settings.users.index')->with('error', 'Tarif üzrə istifadəçi limiti dolub ('.tenant()->plan->max_users.').');
        }

        return view('settings.users.form', ['user' => new User(['is_active' => true]), 'roles' => Role::orderBy('name')->pluck('name', 'id')]);
    }

    public function store(Request $request, MailService $mail): RedirectResponse
    {
        $this->authorize('users.create');
        abort_if($this->atLimit(), 403, 'İstifadəçi limiti dolub.');
        $data = $this->validated($request);

        $token = Str::random(64);
        $user = new User($data + ['password' => Str::random(40), 'is_active' => true]);
        $user->company_id = tenant()->id;
        $user->role_id = $data['role_id'];
        $user->invitation_token = hash('sha256', $token);
        $user->save();

        $sent = $this->sendInvite($user, $token, $mail);

        return redirect()->route('settings.users.index')->with($sent ? 'success' : 'warning', $sent
            ? "Dəvət {$user->email} ünvanına göndərildi."
            : 'İstifadəçi yaradıldı, amma dəvət maili göndərilmədi (Mail jurnalına baxın). Linki əl ilə ötürün: '.route('invitation.show', $token));
    }

    public function edit(int $user): View
    {
        $this->authorize('users.update');

        return view('settings.users.form', ['user' => $this->find($user), 'roles' => Role::orderBy('name')->pluck('name', 'id')]);
    }

    public function update(Request $request, int $user, AuthLogger $log): RedirectResponse
    {
        $this->authorize('users.update');
        $model = $this->find($user);
        $data = $this->validated($request, $model);
        $active = $request->boolean('is_active');

        if ($model->id === $request->user()->id && (! $active || (int) $data['role_id'] !== $model->role_id)) {
            return back()->withErrors(['role_id' => 'Öz rolunuzu dəyişə və ya özünüzü deaktiv edə bilməzsiniz.']);
        }
        if ($model->isCompanyAdmin() && (! $active || ! Role::find($data['role_id'])?->is_admin) && $this->adminCount() <= 1) {
            return back()->withErrors(['role_id' => 'Şirkətdə ən azı bir aktiv admin qalmalıdır.']);
        }

        $model->fill($data);
        $model->role_id = $data['role_id'];
        $model->is_active = $active;
        $model->save();

        if (! $active) {
            $log->forceLogoutEverywhere($model);
        }

        return redirect()->route('settings.users.index')->with('success', 'İstifadəçi yeniləndi.');
    }

    public function destroy(Request $request, int $user, AuthLogger $log): RedirectResponse
    {
        $this->authorize('users.delete');
        $model = $this->find($user);
        if ($model->id === $request->user()->id) {
            return back()->with('error', 'Özünüzü silə bilməzsiniz.');
        }
        if ($model->isCompanyAdmin() && $this->adminCount() <= 1) {
            return back()->with('error', 'Sonuncu admini silmək olmaz.');
        }
        // Deactivate rather than delete: history (tasks, audit, logs) keeps pointing at the person.
        $model->forceFill(['is_active' => false])->save();
        $log->forceLogoutEverywhere($model);

        return back()->with('success', $model->name.' deaktiv edildi. Tarixçə qorunur.');
    }

    public function resendInvite(int $user, MailService $mail): RedirectResponse
    {
        $model = $this->find($user);
        abort_unless($model->invitation_token, 404);
        $token = Str::random(64);
        $model->forceFill(['invitation_token' => hash('sha256', $token)])->save();
        $model->touch(); // restarts the 7-day validity window

        return $this->sendInvite($model, $token, $mail)
            ? back()->with('success', "Dəvət yenidən {$model->email} ünvanına göndərildi.")
            : back()->with('warning', 'Mail göndərilmədi. Linki əl ilə ötürün: '.route('invitation.show', $token));
    }

    public function forceLogout(int $user, AuthLogger $log): RedirectResponse
    {
        $n = $log->forceLogoutEverywhere($this->find($user));

        return back()->with('success', "{$n} sessiya bağlandı. «Məni xatırla» da etibarsız edildi.");
    }

    public function unlock(int $user): RedirectResponse
    {
        $this->find($user)->forceFill(['locked_until' => null, 'failed_attempts' => 0])->save();

        return back()->with('success', 'Hesabın kilidi açıldı.');
    }

    private function sendInvite(User $user, string $token, MailService $mail): bool
    {
        $company = tenant();

        return $mail->send($company, $user->email, new SystemMail(
            mailSubject: $company->name.' sizi TradeFlow-ə dəvət edir',
            heading: 'Komandaya xoş gəldiniz!',
            lines: [
                'Salam, '.$user->name.'!',
                auth()->user()->name.' sizi «'.$company->name.'» şirkətinin TradeFlow hesabına '.$user->role?->name.' rolu ilə dəvət edib.',
                'Aşağıdakı düymə ilə şifrənizi təyin edin. Link 7 gün etibarlıdır.',
            ],
            actionText: 'Dəvəti qəbul et',
            actionUrl: route('invitation.show', $token),
            companyName: $company->name,
        ), 'invitation', $user->id);
    }

    private function find(int $id): User
    {
        return User::forTenant()->findOrFail($id);
    }

    private function adminCount(): int
    {
        return User::forTenant()->where('is_active', true)->whereHas('role', fn ($q) => $q->where('is_admin', true))->count();
    }

    private function atLimit(): bool
    {
        $limit = tenant()->plan?->max_users;

        return $limit && User::forTenant()->where('is_active', true)->count() >= $limit;
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'position' => ['nullable', 'string', 'max:120'],
            'role_id' => ['required', 'integer', TenantExists::plain('roles')],
        ], ['email.unique' => 'Bu email ilə istifadəçi artıq var (bu və ya başqa şirkətdə).'], ['role_id' => 'Rol', 'position' => 'Vəzifə']);
    }
}
