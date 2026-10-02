<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_super_admin, 404);

        // The platform area sees every tenant; it never runs with a tenant bound.
        return app(Tenant::class)->withoutTenant(fn () => $next($request));
    }
}
