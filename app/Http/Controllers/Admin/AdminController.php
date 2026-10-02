<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\LoginLog;
use App\Models\MailLog;
use App\Models\Plan;
use App\Models\User;
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
            'expiring' => Company::where('subscription_status', 'trial')->whereBetween('trial_ends_at', [today()->toDateString(), today()->addDays(7)->toDateString()])->orderBy('trial_ends_at')->get(),
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
            'storage' => (int) Attachment::where('company_id', $company->id)->sum('size'),
            'plans' => Plan::orderBy('monthly_price')->get(),
            'logins' => LoginLog::where('company_id', $company->id)->latest('created_at')->limit(15)->get(),
        ]);
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

        return back()->with('success', 'Abunə yeniləndi.');
    }

    public function plans(): View
    {
        return view('admin.plans', ['plans' => Plan::withCount('companies')->orderBy('monthly_price')->get()]);
    }

    public function storePlan(Request $request): RedirectResponse
    {
        Plan::create($this->planData($request));

        return back()->with('success', 'Tarif yaradıldı.');
    }

    public function updatePlan(Request $request, Plan $plan): RedirectResponse
    {
        $plan->update($this->planData($request, $plan));

        return back()->with('success', 'Tarif yeniləndi.');
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
