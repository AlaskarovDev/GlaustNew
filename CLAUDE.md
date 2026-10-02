# Glaust MS — notes for coding agents

Laravel 13 / PHP 8.3 multi-tenant SaaS. UI language: Azerbaijani. Read README.md first.

## Rules that are easy to break

- Tenant models use `App\Support\Tenancy\BelongsToCompany`; the scope is fail-closed. In console code, jobs and tests wrap work in `app(Tenant::class)->runAs($company, fn () => ...)`.
- Never validate foreign ids with plain `exists:`; use `App\Rules\TenantExists::in('table')` (soft-delete aware) or `::plain()`.
- `User` is not globally scoped: query with `User::forTenant()`; never `User::find($requestId)`.
- Exchange rates come only from `App\Services\Cbar\CurrencyRates`. A missing rate throws `RateUnavailable` — surface it, never substitute another day's rate. CBAR answers future dates with the latest bulletin, so the service refuses future dates.
- AJAX endpoints answer HTTP 200 `{ok:false, message}` for business errors (hosting CDN strips 4xx bodies).
- CSP: no inline event handlers (`onclick=`); use Alpine (`@click`). Inline `<script>` needs `nonce="{{ Vite::cspNonce() }}"`. Chart configs go in `x-chart="{{ json_encode($cfg) }}"` built in `@php`, not multi-line `@json([...])` in attributes (breaks the Blade parser).
- `@use(...)` must be the first line of a view; inside component slots it compiles into an `if` block.
- Tailwind 4: `@apply` cannot use our own classes (e.g. `tabular`); use utilities (`tabular-nums`).

## Commands

- Tests: `php artisan test` (55 tests; `SmokeTest` renders every page with `glaust:demo` data).
- Local PHP on the author's machine: `C:\Users\Ruslan\tools\php83\php.exe`; Node 22: `C:\Users\Ruslan\AppData\Local\nvm\v22.22.0`.
- Deploy: push to `main` (see `.github/workflows/deploy.yml`, `deploy/`).
