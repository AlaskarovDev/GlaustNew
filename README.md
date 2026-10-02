# Glaust Management System

Azərbaycan şirkətləri üçün çox-şirkətli (multi-tenant) SaaS layihə idarəetmə sistemi: layihələr və tapşırıqlar, CRM (müştəri və təchizatçılar), müqavilələr, bank və logistika əməliyyatları, hesabatlar, istifadəçi və rol idarəetməsi. Bütün valyuta hesablamaları Azərbaycan Respublikası Mərkəzi Bankının (CBAR) rəsmi məzənnəsi ilə aparılır.

## Texnologiyalar

| | |
|---|---|
| Backend | PHP 8.3, Laravel 13 |
| Verilənlər bazası | MySQL 8 / MariaDB 10.6+ (testlər və ilkin canlı mühit: SQLite) |
| Frontend | Blade, Alpine.js, Tailwind CSS 4, ApexCharts, SortableJS (Vite ilə build) |
| Excel / PDF | PhpSpreadsheet, DomPDF (DejaVu Sans — ə, ğ, ı, ş düzgün çıxır) |
| 2FA | TOTP (Google/Microsoft Authenticator) |
| Şrift | IBM Plex Sans + Plex Mono (öz serverimizdən, `latin` + `latin-ext`) |

## Modullar

- **İdarə paneli** — KPI-lar, 12 aylıq pul axını, layihə statusları, büdcə və fakt, bank qalıqları (AZN ekvivalenti), logistika, TOP-5 kontragent, məzənnə dinamikası, son fəaliyyətlər. Vidjetlər gizlədilir və sıralanır.
- **Header** — canlı CBAR məzənnə lenti (bülleten tarixi və dəyişmə oxları ilə), «Bugünkü işlərim», xatırlatmalar (ertələ / oxundu), Ctrl+K komanda paneli, klaviatura qısa yolları (`?`), açıq/qaranlıq tema.
- **Layihələr** — kartlar/siyahı, mərhələlər, komanda, Kanban (drag & drop), siyahı, təqvim, Gantt, checklist, şərhlər, vaxt qeydi, fayllar, maliyyə tabı.
- **CRM** — vahid kontragent cədvəli (müştəri / təchizatçı / hər ikisi), VÖEN unikallığı, IBAN yoxlaması (mod-97), əlaqə şəxsləri, dövriyyə.
- **Müqavilələr** — yalnız CRM-dəki kontragentlə (FK + server yoxlaması), satış müştəri ilə, alış təchizatçı ilə; imza tarixinin CBAR məzənnəsi; ödəniş qrafiki; əlavə razılaşmalar; PDF çap; 30/7/1 gün əvvəl xatırlatma.
- **Bank** — hesablar, mədaxil/məxaric, hesablararası köçürmə və konvertasiya (iki əlaqəli tərəf), faktiki bank kursu + CBAR ilə kurs fərqi, çıxarış importu (təkrarlar atlanır).
- **Logistika** — yüklər, status zənciri və tarixçəsi, xərclər (CBAR ilə AZN), gecikmə xatırlatmaları.
- **Hesabatlar** — gəlir/xərc, bank hesabı üzrə hərəkət, kontragent dövriyyəsi və akt-üzləşmə, müqavilələrin icrası, layihə büdcəsi, logistika, kurs fərqi, istifadəçi fəaliyyəti. Hamısı Excel və PDF.
- **Tənzimləmələr** — istifadəçilər (dəvət, deaktiv, məcburi çıxış, kilidi açma), rollar (modul × əməliyyat matrisi), giriş-çıxış logları, aktiv sessiyalar, audit jurnalı, mail jurnalı, SMTP (test maili ilə), header valyutaları, xatırlatma qaydaları, nömrələmə.
- **Super Admin** (`/admin`) — şirkətlər, tariflər, abunələr, platforma logları.

## Mərkəzi Bank inteqrasiyası

Köhnə Glaust-dakı `Cbar_rates` məntiqi (`app/Services/Cbar`):

- Mənbə `https://cbar.az/currencies/dd.mm.yyyy.xml`, 10 s timeout, TLS yoxlaması.
- Məzənnə = `Value / Nominal` (RUB, JPY 100 vahid üçün dərc olunur), 8 onluq rəqəm, `DECIMAL(18,8)`.
- Keş: keçmiş tarixlər 30 gün, bugün/dünən 1 saat; uğursuz sorğu 5 dəqiqə yadda saxlanılır (CBAR çökəndə səhifələr 10 s gözləmir).
- **Saxta məzənnə yoxdur**: məzənnə tapılmasa, əməliyyat yadda saxlanmır və xəta göstərilir.
- Oxunmayan və ya gələcək tarix CBAR-a göndərilmir. **Yoxlanılıb:** CBAR gələcək tarix, bazar günü və hələ dərc olunmamış bugünkü tarix üçün də HTTP 200 + son bülleteni qaytarır. Ona görə bülletenin öz tarixi (`ValCurs@Date`) `bulletin_date` sütununda saxlanılır və header lentində göstərilir.

## Yerli inkişaf

```bash
composer install
npm install && npm run build      # Node 22 (Vite 8 Node 20.19+ tələb edir)
cp .env.example .env && php artisan key:generate
# .env: DB_CONNECTION=sqlite (database/database.sqlite) və ya MySQL
php artisan migrate --seed
php artisan glaust:demo --companies=2 --password=Demo12345   # demo şirkətlər (CBAR tarixçəsini də yükləyir)
php artisan serve
```

Testlər: `php artisan test` (SQLite yaddaşda, CBAR HTTP-si `tests/Fixtures/cbar-sample.xml` real bülleteni ilə saxtalaşdırılır).

## Arxitektura qeydləri

- **Tenant izolyasiyası** — `App\Support\Tenancy`. Hər biznes modeli `BelongsToCompany` istifadə edir: global scope + yaradanda `company_id`. Scope *fail-closed*-dir: tenant konteksti yoxdursa, sorğu heç nə qaytarmır. Konsol əmrləri və job-lar `Tenant::runAs($company)` ilə işləyir. `exists:` validasiyası scope-dan keçmir, ona görə `App\Rules\TenantExists` istifadə olunur. `User` scope-suz modeldir (giriş tenant-dan əvvəl olur), həmişə `User::forTenant()` ilə sorğulanır.
- **İcazələr** — `module.action` (`config/glaust.php`), `Gate::before` → `Role::allows()`. Tarifdə olmayan modul admin üçün də bağlıdır.
- **Audit** — `Auditable` trait: yaratma/dəyişmə/silmə köhnə və yeni dəyərlərlə.
- **Siyahılar** — `App\Tables\*`: bir sorğu həm ekran, həm Excel, həm PDF üçün (export cari filtrləri nəzərə alır).
- **AJAX cavabları** — biznes xətaları HTTP 200 + `{ok:false}`: Hostinger CDN 4xx cavabların gövdəsini silir.
- **Planlayıcı** — `routes/console.php`. Cron yoxdursa, `RunScheduleFromWeb` işləri veb sorğusundan sonra eyni prosesdə icra edir (`proc_open` tələb etmir).

## Deploy (Hostinger shared, GitHub Actions)

`main`-ə push → test → build → `deploy/package.sh` (app zip + vendor zip + agent) → `deploy/upload.sh` (FTP, hər dəfə 2-3 fayl) → `_deploy.php` agenti (açma, `.env`, migrasiya, atomik dəyişmə) → `deploy/check.sh` (canlı yoxlamalar) → uğursuz olsa avtomatik rollback.

Serverdə (`public_html/`):

```
index.php, .htaccess, build/     veb kök
_app/                            tətbiq (vebdən bağlı)
_app_prev/                       əvvəlki versiya (rollback)
_app_shared/.env                 sirlər (vebdən bağlı)
_app_storage/                    loglar, fayllar, sessiyalar, SQLite (vebdən bağlı)
_releases/                       zip arxivləri (vebdən bağlı)
```

GitHub Secrets: `FTP_HOST`, `FTP_USER`, `FTP_PASSWORD`, `SITE_URL`, `DEPLOY_TOKEN`, `APP_KEY`, istəyə bağlı `DB_ENV` (MySQL sətirləri; yoxdursa SQLite), `MAIL_ENV` (SMTP sətirləri; yoxdursa `log`), `ADMIN_ENV` (`GLAUST_ADMIN_EMAIL` / `GLAUST_ADMIN_PASSWORD`).

Serverdə artisan əmri: Actions → «Test & deploy» → *Run workflow* → `artisan` = `glaust:demo`, `artisan_args` = `{"--companies":2}`.

### Cron (tövsiyə olunur)

hPanel → Advanced → Cron Jobs, hər dəqiqə:

```
/usr/bin/php /home/<user>/domains/<domain>/public_html/_app/artisan schedule:run
```

Cron qurulana qədər işlər veb trafiklə icra olunur (xatırlatmalar 5 dəqiqədən bir, xülasə, CBAR hər saat, növbə).
