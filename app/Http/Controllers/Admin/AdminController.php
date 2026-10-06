<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\LoginLog;
use App\Models\MailLog;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyProvisioner;
use Illuminate\Validation\Rules\Password;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Platform owner area. Runs with the tenant scope bypassed (SuperAdmin middleware). */
class AdminController extends Controller
{
    public function dashboard(): View
    {
        $companies = Company::with('plan')->get();

        return view('admin.dashboard', [
            'stats' => [
                'companies' => $companies->count(),
                'active' => $companies->where('subscription_status', 'active')->count(),
                'trial' => $companies->where('subscription_status', 'trial')->count(),
                'suspended' => $companies->where('subscription_status', 'suspended')->count(),
                'users' => User::whereNotNull('company_id')->count(),
                'mrr' => $companies->where('subscription_status', 'active')->sum(fn ($c) => (float) $c->plan?->monthly_price),
            ],
            'recent' => Company::with('plan')->withCount('users')->latest()->limit(8)->get(),
            'expiring' => Company::where('subscription_status', 'trial')->whereBetween('trial_ends_at', [today()->toDateString(), today()->addDays(7)->toDateString().' 23:59:59'])->orderBy('trial_ends_at')->get(),
            'failedLogins' => LoginLog::whereIn('event', ['failed', 'locked'])->where('created_at', '>=', now()->subDay())->count(),
            'failedMails' => MailLog::where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
        ]);
    }

    public function companies(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $companies = Company::with('plan')->withCount('users')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('voen', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")))
            ->when($request->filled('status'), fn ($w) => $w->where('subscription_status', $request->query('status')))
            ->latest()->paginate(30)->withQueryString();

        return view('admin.companies', compact('companies'));
    }

    public function company(Company $company): View
    {
        $company->load('plan');

        return view('admin.company', [
            'company' => $company,
            'users' => User::where('company_id', $company->id)->with('role')->orderBy('name')->get(),
            'roles' => Role::withoutGlobalScopes()->where('company_id', $company->id)->orderByDesc('is_admin')->orderBy('name')->get(['id', 'name']),
            'storage' => (int) Attachment::where('company_id', $company->id)->sum('size'),
            'plans' => Plan::orderBy('monthly_price')->get(),
            'logins' => LoginLog::where('company_id', $company->id)->latest('created_at')->limit(15)->get(),
        ]);
    }

    public function createCompany(): View
    {
        return view('admin.company-create', ['plans' => Plan::where('is_active', true)->orderBy('monthly_price')->get()]);
    }

    /** A new tenant without users — they are added on the company page. */
    public function storeCompany(Request $request, CompanyProvisioner $provisioner): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'voen' => ['nullable', 'string', 'max:10'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'plan_id' => ['nullable', 'exists:plans,id'],
            'subscription_status' => ['required', Rule::in(['trial', 'active'])],
        ], [], ['name' => __('Şirkətin adı')]);
        $company = $provisioner->createCompany($data, isset($data['plan_id']) ? Plan::find($data['plan_id']) : null, $data['subscription_status']);

        return redirect()->route('admin.companies.show', $company)->with('success', __('«:v1» yaradıldı. İndi istifadəçiləri əlavə edin.', ['v1' => $company->name]));
    }

    public function storeUser(Request $request, Company $company, CompanyProvisioner $provisioner): RedirectResponse
    {
        $data = $this->validatedUser($request, $company);
        $user = $provisioner->addUser($company, $data, (int) $data['role_id']);

        return back()->with('success', __(':v1 əlavə edildi (:v2).', ['v1' => $user->name, 'v2' => $user->email]));
    }

    public function updateUser(Request $request, Company $company, User $user): RedirectResponse
    {
        abort_unless($user->company_id === $company->id, 404);
        $data = $this->validatedUser($request, $company, $user);
        $user->fill(collect($data)->except(['password', 'role_id'])->all());
        $user->role_id = (int) $data['role_id'];
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();

        return back()->with('success', __(':v1 yeniləndi.', ['v1' => $user->name]).(! empty($data['password']) ? __(' Yeni şifrə təyin edildi.') : ''));
    }

    private function validatedUser(Request $request, Company $company, ?User $user = null): array
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);

        return $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', Password::defaults()],
            'phone' => ['nullable', 'string', 'max:40'],
            'position' => ['nullable', 'string', 'max:120'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('company_id', $company->id)],
        ], [], ['name' => 'Ad', 'email' => 'Email', 'password' => __('Şifrə'), 'phone' => __('Telefon'), 'position' => __('Vəzifə'), 'role_id' => __('Rol')])
            + ['is_active' => $user ? $request->boolean('is_active') : true];
    }

    public function updateCompany(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['nullable', 'exists:plans,id'],
            'subscription_status' => ['required', Rule::in(['trial', 'active', 'suspended'])],
            'trial_ends_at' => ['nullable', 'date'],
            'subscription_ends_at' => ['nullable', 'date'],
        ]);
        $company->update($data);

        return back()->with('success', __('Abunə yeniləndi.'));
    }

    public function plans(): View
    {
        return view('admin.plans', ['plans' => Plan::withCount('companies')->orderBy('monthly_price')->get()]);
    }

    public function storePlan(Request $request): RedirectResponse
    {
        Plan::create($this->planData($request));

        return back()->with('success', __('Tarif yaradıldı.'));
    }

    public function updatePlan(Request $request, Plan $plan): RedirectResponse
    {
        $plan->update($this->planData($request, $plan));

        return back()->with('success', __('Tarif yeniləndi.'));
    }

    public function logs(Request $request): View
    {
        return view('admin.logs', [
            'logins' => LoginLog::with('user')->when($request->query('event'), fn ($q, $e) => $q->where('event', $e))->latest('created_at')->paginate(40, ['*'], 'lp')->withQueryString(),
            'mails' => MailLog::where('status', 'failed')->latest('created_at')->limit(30)->get(),
            'companies' => Company::pluck('name', 'id'),
        ]);
    }

    private function planData(Request $request, ?Plan $plan = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'code' => ['required', 'alpha_dash', 'max:40', Rule::unique('plans', 'code')->ignore($plan?->id)],
            'max_users' => ['required', 'integer', 'min:1', 'max:10000'],
            'max_storage_mb' => ['required', 'integer', 'min:50'],
            'monthly_price' => ['required', 'numeric', 'min:0'],
            'modules' => ['array'],
            'modules.*' => [Rule::in(config('glaust.plan_modules'))],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['modules'] = array_values($data['modules'] ?? []);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
