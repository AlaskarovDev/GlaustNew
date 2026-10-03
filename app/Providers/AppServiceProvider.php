<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Tenancy\Tenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Tenant::class);
    }

    public function boot(): void
    {
        // "module.action" abilities come from the user's role; everything else falls through to policies.
        Gate::before(function (User $user, string $ability) {
            if ($user->is_super_admin && $user->company_id === null) {
                return null;
            }

            return str_contains($ability, '.') ? $user->hasPermission($ability) : null;
        });

        Relation::enforceMorphMap([
            'user' => \App\Models\User::class,
            'company' => \App\Models\Company::class,
            'role' => \App\Models\Role::class,
            'counterparty' => \App\Models\Counterparty::class,
            'project' => \App\Models\Project::class,
            'task' => \App\Models\Task::class,
            'contract' => \App\Models\Contract::class,
            'bank_account' => \App\Models\BankAccount::class,
            'bank_transaction' => \App\Models\BankTransaction::class,
            'shipment' => \App\Models\Shipment::class,
            'shipment_cost' => \App\Models\ShipmentCost::class,
            'deal' => \App\Models\Deal::class,
            'invoice' => \App\Models\Invoice::class,
        ]);

        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);
        RateLimiter::for('ajax', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        Paginator::defaultView('components.pagination');
        \Carbon\Carbon::setLocale(config('app.locale'));
        \Carbon\CarbonImmutable::setLocale(config('app.locale'));

        Vite::useCspNonce();

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
