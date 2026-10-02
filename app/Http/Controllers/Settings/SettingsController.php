<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Mail\SystemMail;
use App\Models\Category;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(Request $request): View
    {
        $u = $request->user();
        $sections = array_values(array_filter([
            $u->can('settings.view') ? ['company', 'building', 'Şirkət', 'Ad, VÖEN, rekvizitlər, loqo'] : null,
            $u->can('users.view') ? ['users.index', 'users', 'İstifadəçilər', 'Dəvət, rollar, aktivlik, məcburi çıxış'] : null,
            $u->can('users.view') ? ['roles.index', 'shield', 'Rollar və icazələr', 'Modul üzrə baxış, yaratma, redaktə, silmə, export, import'] : null,
            $u->can('settings.view') ? ['general', 'settings', 'Ümumi ayarlar', 'Header valyutaları, xatırlatma qaydaları, nömrələmə'] : null,
            $u->can('settings.view') ? ['mail', 'mail', 'Mail (SMTP)', 'Şirkətin mail serveri və test məktubu'] : null,
            $u->can('settings.view') ? ['categories', 'layers', 'Kateqoriyalar', 'Bank əməliyyatlarının kateqoriyaları'] : null,
            $u->can('logs.view') ? ['logs.logins', 'log-out', 'Giriş-çıxış logları', 'Kim, nə vaxt, haradan daxil olub'] : null,
            $u->can('logs.view') ? ['logs.sessions', 'monitor', 'Aktiv sessiyalar', 'Açıq sessiyalar və uzaqdan bağlama'] : null,
            $u->can('logs.view') ? ['logs.audit', 'history', 'Audit jurnalı', 'Hər yaratma, dəyişmə və silmə'] : null,
            $u->can('logs.view') ? ['logs.mail', 'send', 'Mail jurnalı', 'Göndərilmiş məktublar və xətalar'] : null,
        ]));
        abort_if(! $sections, 403);

        return view('settings.index', ['sections' => $sections, 'company' => tenant()->load('plan'), 'usersCount' => User::forTenant()->count()]);
    }

    public function company(): View
    {
        return view('settings.company', ['company' => tenant()]);
    }

    public function updateCompany(Request $request): RedirectResponse
    {
        $company = tenant();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'voen' => ['nullable', 'digits:10'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'bank_details' => ['nullable', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
        ], [], ['bank_details' => 'Bank rekvizitləri', 'logo' => 'Loqo']);

        if ($request->hasFile('logo')) {
            if ($company->logo_path) {
                Storage::disk('local')->delete($company->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('logos/'.$company->id, 'local');
        }
        unset($data['logo']);
        $company->update($data);

        return back()->with('success', 'Şirkət məlumatları yeniləndi.');
    }

    public function general(): View
    {
        return view('settings.general', ['company' => tenant()]);
    }

    public function updateGeneral(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ticker_currencies' => ['required', 'array', 'min:1', 'max:12'],
            'ticker_currencies.*' => [Rule::in(array_diff(config('glaust.currencies'), ['AZN']))],
            'contract_reminder_days' => ['nullable', 'string', 'max:40', 'regex:/^\s*\d{1,3}(\s*,\s*\d{1,3})*\s*$/'],
            'payment_reminder_days' => ['nullable', 'string', 'max:40', 'regex:/^\s*\d{1,3}(\s*,\s*\d{1,3})*\s*$/'],
            'task_reminder_days' => ['nullable', 'string', 'max:40', 'regex:/^\s*\d{1,3}(\s*,\s*\d{1,3})*\s*$/'],
            'digest_time' => ['required', 'date_format:H:i'],
            'numbering.project' => ['required', 'string', 'max:40'],
            'numbering.contract' => ['required', 'string', 'max:40'],
            'numbering.shipment' => ['required', 'string', 'max:40'],
        ], ['*.regex' => 'Günləri vergüllə yazın, məs: 30, 7, 1'], [
            'ticker_currencies' => 'Header valyutaları', 'contract_reminder_days' => 'Müqavilə xatırlatmaları',
            'payment_reminder_days' => 'Ödəniş xatırlatmaları', 'task_reminder_days' => 'Tapşırıq xatırlatmaları', 'digest_time' => 'Xülasə vaxtı',
        ]);

        $days = fn (?string $s) => collect(explode(',', (string) $s))->map(fn ($d) => trim($d))->filter(fn ($d) => $d !== '')
            ->map(fn ($d) => min(365, (int) $d))->unique()->sortDesc()->values()->all();
        $company = tenant();
        $company->putSetting('ticker_currencies', array_values($data['ticker_currencies']));
        $company->putSetting('contract_reminder_days', $days($data['contract_reminder_days'] ?? ''));
        $company->putSetting('payment_reminder_days', $days($data['payment_reminder_days'] ?? ''));
        $company->putSetting('task_reminder_days', $days($data['task_reminder_days'] ?? ''));
        $company->putSetting('digest_time', $data['digest_time']);
        $company->putSetting('numbering', $data['numbering']);
        $company->save();

        return back()->with('success', 'Ayarlar yadda saxlanıldı.');
    }

    public function mail(): View
    {
        return view('settings.mail', ['company' => tenant()]);
    }

    public function updateMail(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'smtp_host' => ['nullable', 'string', 'max:190'],
            'smtp_port' => ['nullable', 'integer', 'between:1,65535'],
            'smtp_username' => ['nullable', 'string', 'max:190'],
            'smtp_password' => ['nullable', 'string', 'max:190'],
            'smtp_encryption' => ['nullable', Rule::in(['tls', 'ssl'])],
            'smtp_from_address' => ['nullable', 'required_with:smtp_host', 'email', 'max:190'],
            'smtp_from_name' => ['nullable', 'string', 'max:190'],
        ], [], ['smtp_host' => 'SMTP server', 'smtp_from_address' => 'Göndərən email']);

        // An empty password field keeps the stored one.
        if (blank($data['smtp_password'] ?? null)) {
            unset($data['smtp_password']);
        }
        tenant()->update($data);

        return back()->with('success', 'Mail ayarları yadda saxlanıldı. «Test mail göndər» ilə yoxlayın.');
    }

    public function testMail(Request $request, MailService $mail): RedirectResponse
    {
        $company = tenant();
        if (! $company->hasOwnSmtp()) {
            return back()->with('error', 'Əvvəlcə SMTP server və göndərən emaili yadda saxlayın.');
        }
        $to = $request->validate(['to' => ['required', 'email']], [], ['to' => 'Alıcı'])['to'];

        $error = $mail->test($company, $to, new SystemMail(
            mailSubject: 'Test məktubu — Glaust MS',
            heading: 'SMTP ayarları işləyir',
            lines: ['Bu məktub '.$company->name.' şirkətinin mail ayarlarını yoxlamaq üçün göndərilib.', 'Server: '.$company->smtp_host.':'.$company->smtp_port.' · '.now()->format('d.m.Y H:i')],
            companyName: $company->name,
        ));

        return $error
            ? back()->with('error', 'Göndərilmədi: '.$error)
            : back()->with('success', "Server məktubu qəbul etdi. {$to} qutusunu (və spam qovluğunu) yoxlayın — qəbul olunması çatdırılma demək deyil.");
    }

    public function categories(): View
    {
        return view('settings.categories', ['categories' => Category::withCount('transactions')->where('scope', 'bank')->orderBy('name')->get()]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('categories')->where('company_id', tenant()->id)->where('scope', 'bank')],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], ['name.unique' => 'Bu adda kateqoriya var.'], ['name' => 'Ad']);
        Category::create($data + ['scope' => 'bank']);

        return back()->with('success', 'Kateqoriya əlavə edildi.');
    }

    public function destroyCategory(Category $category): RedirectResponse
    {
        $category->delete();

        return back()->with('success', 'Kateqoriya silindi (əməliyyatlar kateqoriyasız qaldı).');
    }
}
