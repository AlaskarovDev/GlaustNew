<?php

namespace App\Http\Middleware;

use App\Services\AuthLogger;
use App\Support\Tenancy\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the signed-in user's company as the tenant for this request and
 * enforces account state: inactive users are signed out, suspended or
 * expired companies only reach the subscription page.
 */
class SetTenant
{
    public function __construct(private Tenant $tenant, private AuthLogger $authLogger) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user->is_active) {
            $this->authLogger->logout($user, 'forced_logout');
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Hesabınız deaktiv edilib. Administratorla əlaqə saxlayın.']);
        }

        if ($user->company_id === null) {
            // Platform owner without a company: only the super-admin area applies.
            return $user->is_super_admin
                ? redirect()->route('admin.dashboard')
                : abort(403);
        }

        $company = $user->company()->with('plan')->first();
        $this->tenant->set($company);
        $user->setRelation('company', $company);

        if (! $company->isUsable() && ! $request->routeIs('subscription.*', 'logout')) {
            return redirect()->route('subscription.expired');
        }

        View::share('company', $company);

        return $next($request);
    }
}
