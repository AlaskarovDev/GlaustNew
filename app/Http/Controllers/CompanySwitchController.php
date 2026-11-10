<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Role;
use App\Services\AuthLogger;
use App\Services\CompanyProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * «Firma seçimi» in the header: a user who is a member of several companies works in one at a time — each with its own
 * data, panel, role and permissions. A company's admin may open a new company (the same subscription; they are its admin).
 */
class CompanySwitchController extends Controller
{
    public function switch(Request $request, Company $company, AuthLogger $log): RedirectResponse
    {
        $user = $request->user();
        if ($user->membershipIn($company->id) === null) {
            abort(404);
        }
        if ((int) $user->company_id !== $company->id) {
            $user->switchTo($company);
            $request->session()->forget(['analytics.period']);   // the other company's report period
            $request->session()->regenerate();
        }

        return redirect()->route('dashboard')->with('success', __('Şirkət: :v1', ['v1' => $company->name]));
    }

    public function store(Request $request, CompanyProvisioner $provisioner): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isCompanyAdmin() || $user->is_super_admin, 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'voen' => ['nullable', 'string', 'max:20'],
        ], [], ['name' => __('Şirkətin adı'), 'voen' => __('VÖEN')]);

        $now = tenant();
        $company = $provisioner->createCompany(['name' => $data['name'], 'voen' => $data['voen'] ?? null, 'email' => $user->email],
            $now?->plan, $now?->subscription_status ?? 'trial');
        // the same subscription as the company it is opened from
        $company->forceFill(['subscription_ends_at' => $now?->subscription_ends_at, 'trial_ends_at' => $now?->trial_ends_at ?? $company->trial_ends_at])->save();
        $admin = Role::withoutGlobalScopes()->where('company_id', $company->id)->where('key', 'admin')->firstOrFail();
        $user->setAccessIn($company->id, $admin->id);
        $user->switchTo($company);
        $request->session()->forget(['analytics.period']);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', __('Yeni şirkət yaradıldı: :v1. Məlumatları ayrıdır — bank hesablarını və layihələri bu şirkətdə əlavə edin.', ['v1' => $company->name]));
    }
}
