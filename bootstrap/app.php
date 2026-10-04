<?php

use App\Http\Middleware\RunScheduleFromWeb;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetTenant;
use App\Http\Middleware\SuperAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Shared hosting sits behind the provider's CDN / proxy.
        $middleware->trustProxies(at: '*');
        $middleware->alias([
            'tenant' => SetTenant::class,
            'superadmin' => SuperAdmin::class,
        ]);
        $middleware->web(append: [\App\Http\Middleware\SetLocale::class, SecurityHeaders::class, RunScheduleFromWeb::class]);
        // The tenant must be bound BEFORE route-model binding resolves {project}, {contract}...:
        // tenant scopes are fail-closed, so binding first turns every show/edit page into a 404.
        $middleware->appendToPriorityList(\Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class, SetTenant::class);
        $middleware->appendToPriorityList(\Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class, SuperAdmin::class);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'ajax/*') || $request->expectsJson(),
        );
    })->create();

/*
 * Shared-hosting layout (written by deploy/agent.php), all inside public_html:
 *   public_html/            web root: index.php, .htaccess, build/
 *   public_html/_app/       this application (web access denied)
 *   public_html/_app_shared/.env, public_html/_app_storage/  — survive releases
 */
if (is_file(dirname(__DIR__).'/.hosting-layout')) {
    $root = dirname(__DIR__, 2);
    $app->usePublicPath($root);
    $app->useEnvironmentPath($root.'/_app_shared');
    $app->useStoragePath($root.'/_app_storage');
}

return $app;
