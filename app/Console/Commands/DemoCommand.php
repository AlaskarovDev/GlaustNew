<?php

namespace App\Console\Commands;

use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Counterparty;
use App\Models\CurrencyRate;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\Task;
use App\Models\User;
use App\Services\BankLedger;
use App\Services\Cbar\CurrencyRates;
use App\Services\CompanyProvisioner;
use App\Services\NumberGenerator;
use App\Services\ReminderService;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Creates demo companies full of realistic data so the dashboard is alive on first open.
 * Bank movements in foreign currencies are only created on dates for which a real CBAR
 * rate is stored — demo data never invents exchange rates (currency_rates is shared by all tenants).
 */
class DemoCommand extends Command
{
    protected $signature = 'glaust:demo {--companies=2} {--password=} {--days=90 : CBAR history to load first} {--deals-only : add demo deals (sövdələşmələr) to existing demo companies}';

    protected $description = 'Create demo companies with users, CRM, projects, contracts, bank and logistics data';

    private const COMPANY_NAMES = ['Xəzər Konsaltinq MMC', 'Göygöl İnşaat MMC', 'Qafqaz Trade MMC'];

    private const PEOPLE = [
        ['Leyla Məmmədova', 'Baş direktor'], ['Rəşad Əliyev', 'Layihə meneceri'], ['Günay Hüseynova', 'Baş mühasib'],
        ['Elvin Quliyev', 'Logistika meneceri'], ['Nərmin Həsənova', 'Satış mütəxəssisi'], ['Orxan İsmayılov', 'Mühəndis'],
    ];

    private const CUSTOMERS = ['«Bakı Enerji Sistemləri» ASC', '«Şirvan Aqro» MMC', '«Gəncə Tekstil» MMC', '«Sumqayıt Kimya Parkı» MMC', '«Naxçıvan Tikinti» MMC',
        '«Lənkəran Çay» MMC', '«Abşeron Logistik Mərkəzi» MMC', '«Quba Meyvə» MMC', '«Xaçmaz Qida» MMC', '«Mingəçevir Kabel» ASC', '«Şəki İpək» MMC', '«Caspian Retail» MMC'];

    private const SUPPLIERS = [['«Anadolu Makina» A.Ş.', 'Türkiyə', 'İstanbul'], ['«Georgian Steel» LLC', 'Gürcüstan', 'Tbilisi'], ['«Volga Trans» OOO', 'Rusiya', 'Həştərxan'],
        ['«Xəzər Daşımaları» MMC', 'Azərbaycan', 'Bakı'], ['«EuroPack» GmbH', 'Almaniya', 'Hamburq'], ['«Silk Way Cargo» MMC', 'Azərbaycan', 'Bakı'],
        ['«Kapital Ofis Təchizat» MMC', 'Azərbaycan', 'Bakı'], ['«Ural Metall» OOO', 'Rusiya', 'Yekaterinburq']];

    private const PROJECTS = ['Anbar kompleksinin tikintisi', 'ERP sisteminin tətbiqi', 'Soyuducu anbarın avadanlıqla təchizatı', 'Ofis binasının təmiri',
        'Günəş panellərinin quraşdırılması', 'Paylayıcı mərkəzin açılışı', 'İstehsal xəttinin modernləşdirilməsi', 'Regional satış şəbəkəsinin qurulması',
        'Su təmizləyici qurğunun montajı', 'Logistika proseslərinin optimallaşdırılması', 'Yeni məhsulun bazara çıxarılması', 'Kommersiya binası üçün fasad işləri'];

    private const TASKS = ['Texniki tapşırığı hazırlamaq', 'Smeta tərtib etmək', 'Təchizatçılardan təklif almaq', 'Müqavilə layihəsini razılaşdırmaq', 'Avadanlığın sifarişi',
        'Gömrük sənədlərini hazırlamaq', 'Sahədə ölçmə işləri', 'Layihə çertyojlarını təsdiqləmək', 'Montaj qrafikini tutmaq', 'Sifarişçiyə hesabat göndərmək',
        'Keyfiyyət yoxlaması', 'Təhvil-təslim aktını hazırlamaq', 'Ödəniş tapşırığını göndərmək', 'Heyətə təlim keçmək', 'Risklərin qiymətləndirilməsi'];

    public function handle(CompanyProvisioner $provisioner, CurrencyRates $rates, Tenant $tenant): int
    {
        if ($this->option('deals-only')) {
            foreach (Company::whereIn('name', self::COMPANY_NAMES)->get() as $company) {
                $n = $tenant->runAs($company, fn () => $this->seedDeals());
                $this->info("{$company->name}: {$n} sövdələşmə");
            }

            return self::SUCCESS;
        }

        $this->info('CBAR məzənnələri yüklənir (son '.$this->option('days').' gün)…');
        $loaded = $rates->backfill((int) $this->option('days'));
        $this->line("  {$loaded} gün yükləndi/yeniləndi.");

        $password = $this->option('password') ?: Str::password(12, symbols: false);
        $plan = Plan::where('code', 'business')->first();

        for ($c = 0; $c < (int) $this->option('companies'); $c++) {
            $name = self::COMPANY_NAMES[$c % count(self::COMPANY_NAMES)];
            if (Company::where('name', $name)->exists()) {
                $this->warn("{$name} artıq var — atlanır.");

                continue;
            }
            $slug = Str::slug(strtr($name, ['ə' => 'e', 'Ə' => 'E', ' MMC' => '']));
            $admin = $provisioner->create(
                ['name' => $name, 'voen' => (string) (1400000000 + $c * 1111111 + 1), 'phone' => '+994 12 404 00 0'.$c],
                ['name' => self::PEOPLE[0][0], 'email' => "admin@{$slug}.demo", 'password' => $password, 'position' => self::PEOPLE[0][1]],
                $plan, 30,
            );
            $company = $admin->company;
            $company->forceFill(['address' => 'Bakı, Nərimanov r-nu, Təbriz küç. '.(10 + $c), 'bank_details' => 'Kapital Bank · AZ37AIIB38090019441234567890'])->save();

            $tenant->runAs($company, fn () => $this->fill($company, $slug, $password));
            $this->info("✓ {$name}: admin@{$slug}.demo");
        }

        $this->newLine();
        $this->warn("Demo şifrəsi (bütün demo istifadəçilər üçün): {$password}");

        return self::SUCCESS;
    }

    private function fill(Company $company, string $slug, string $password): void
    {
        mt_srand(crc32($slug));
        $today = CarbonImmutable::today();
        $roles = Role::pluck('id', 'key');

        // Users
        $users = collect([User::where('company_id', $company->id)->first()]);
        foreach (array_slice(self::PEOPLE, 1, 4) as $i => [$name, $position]) {
            $u = new User(['name' => $name, 'email' => Str::slug(explode(' ', $name)[0]).'@'.$slug.'.demo', 'password' => $password, 'position' => $position, 'is_active' => true, 'phone' => '+994 50 555 1'.$i.' '.$i.$i]);
            $u->company_id = $company->id;
            $u->role_id = $roles[['manager', 'accountant', 'logistician', 'employee'][$i]];
            $u->email_verified_at = now();
            $u->save();
            $users->push($u);
        }

        // CRM
        $customers = collect(self::CUSTOMERS)->map(fn ($n, $i) => Counterparty::create([
            'type' => $i % 7 === 6 ? 'both' : 'customer', 'entity_type' => 'legal', 'name' => $n,
            'voen' => (string) (1500000000 + crc32($slug.$n) % 99999999), 'country' => 'Azərbaycan',
            'city' => ['Bakı', 'Gəncə', 'Sumqayıt', 'Şəki', 'Lənkəran', 'Quba'][$i % 6], 'phone' => '+994 12 5'.str_pad((string) ($i * 37 % 100), 2, '0').' 20 '.str_pad((string) $i, 2, '0'),
            'email' => 'info@'.Str::slug(trim(preg_replace('/[«»]|MMC|ASC/u', '', $n))).'.az', 'tags' => $i % 3 === 0 ? 'VIP' : null,
        ]))->each(fn ($cp) => $cp->contacts()->create(['name' => ['Kamran', 'Səbinə', 'Tural', 'Aytən', 'Fərid'][$cp->id % 5].' '.['Abbasov', 'Kərimova', 'Nəbiyev', 'Rzayeva', 'Səfərov'][$cp->id % 5], 'position' => 'Satınalma meneceri', 'phone' => '+994 55 2'.($cp->id % 90 + 10).' 11 22']));

        $suppliers = collect(self::SUPPLIERS)->map(fn ($s, $i) => Counterparty::create([
            'type' => 'supplier', 'entity_type' => 'legal', 'name' => $s[0], 'country' => $s[1], 'city' => $s[2],
            'voen' => $s[1] === 'Azərbaycan' ? (string) (1700000000 + crc32($slug.$s[0]) % 99999999) : null,
            'email' => 'sales@'.Str::slug(trim(preg_replace('/[«»]|MMC|LLC|OOO|GmbH|A\.Ş\./u', '', $s[0]))).'.com',
        ]));

        // Bank accounts
        $accounts = collect([
            ['Əsas AZN hesabı', 'Kapital Bank', 'AZN', 185000],
            ['USD valyuta hesabı', 'PAŞA Bank', 'USD', 42000],
            ['EUR valyuta hesabı', 'ABB', 'EUR', 15000],
            ['Əmək haqqı hesabı', 'Bank Respublika', 'AZN', 30000],
        ])->map(fn ($a) => BankAccount::create(['name' => $a[0], 'bank_name' => $a[1], 'currency' => $a[2], 'opening_balance' => $a[3], 'opening_date' => $today->subDays(120)->toDateString(), 'is_active' => true]));

        // Projects + tasks
        $numbers = app(NumberGenerator::class);
        $projects = collect();
        foreach (array_slice(self::PROJECTS, 0, 10) as $i => $title) {
            $start = $today->subDays(mt_rand(10, 110));
            $status = ['active', 'active', 'active', 'planned', 'on_hold', 'completed', 'active', 'active', 'planned', 'completed'][$i];
            $p = Project::create([
                'code' => $numbers->next('project'), 'name' => $title, 'counterparty_id' => $customers[$i % $customers->count()]->id,
                'manager_id' => $users[1]->id, 'start_date' => $start->toDateString(), 'end_date' => $start->addDays(mt_rand(60, 200))->toDateString(),
                'status' => $status, 'priority' => ['medium', 'high', 'critical', 'low'][$i % 4],
                'budget' => mt_rand(4, 60) * 5000, 'currency' => $i % 4 === 2 ? 'USD' : 'AZN',
                'description' => 'Demo layihə: '.$title.'. Mərhələlər, tapşırıqlar və maliyyə göstəriciləri avtomatik yaradılıb.',
            ]);
            $p->members()->sync($users->random(3)->pluck('id')->push($users[1]->id)->unique()->all());
            $m1 = $p->milestones()->create(['name' => 'Hazırlıq', 'due_date' => $start->addDays(20)->toDateString(), 'completed_at' => $start->addDays(20)->isPast() ? $start->addDays(19) : null]);
            $m2 = $p->milestones()->create(['name' => 'İcra', 'due_date' => $start->addDays(70)->toDateString()]);
            $p->milestones()->create(['name' => 'Təhvil', 'due_date' => $p->end_date->toDateString()]);

            foreach (collect(self::TASKS)->shuffle()->take(mt_rand(4, 7)) as $k => $t) {
                $due = $today->addDays(mt_rand(-12, 25));
                $st = $status === 'completed' ? 'done' : ['todo', 'in_progress', 'review', 'done', 'todo'][mt_rand(0, 4)];
                Task::create([
                    'project_id' => $p->id, 'milestone_id' => $k < 2 ? $m1->id : $m2->id, 'title' => $t, 'status' => $st,
                    'priority' => ['low', 'medium', 'high', 'critical'][mt_rand(0, 3)], 'assignee_id' => $users->random()->id,
                    'created_by' => $users[1]->id, 'start_date' => $due->subDays(mt_rand(3, 15))->toDateString(), 'due_date' => $due->toDateString(),
                    'estimated_hours' => mt_rand(2, 40), 'position' => $k,
                ]);
            }
            $projects->push($p);
        }
        // Make sure the signed-in admin has something for "today".
        foreach (['Həftəlik idarə heyəti iclasına hazırlıq', 'Bank çıxarışlarını yoxlamaq', 'Yeni müqavilələri təsdiqləmək'] as $k => $t) {
            Task::create(['project_id' => $projects[$k]->id, 'title' => $t, 'status' => 'todo', 'priority' => ['high', 'medium', 'critical'][$k],
                'assignee_id' => $users[0]->id, 'due_date' => $k === 2 ? $today->subDay()->toDateString() : $today->toDateString()]);
        }

        // Contracts (15) — rates only from stored CBAR history
        $rates = app(CurrencyRates::class);
        $rateDates = CurrencyRate::where('currency_code', 'USD')->where('rate_date', '>=', $today->subDays(110)->toDateString())->pluck('rate_date')->map(fn ($d) => $d->format('Y-m-d'))->all();
        $contracts = collect();
        for ($i = 0; $i < 15; $i++) {
            $sale = $i % 3 !== 2;
            $cp = $sale ? $customers[$i % $customers->count()] : $suppliers[$i % $suppliers->count()];
            $currency = (! $sale && $i % 2 === 0 && $rateDates) ? 'USD' : 'AZN';
            $date = $currency === 'USD' ? CarbonImmutable::parse($rateDates[array_rand($rateDates)]) : $today->subDays(mt_rand(5, 100));
            $rate = $rates->tryRate($currency, $date);
            if ($rate === null) {
                [$currency, $rate] = ['AZN', 1.0];
            }
            $amount = mt_rand(8, 120) * 1000;
            $end = $i < 4 ? $today->addDays([3, 12, 25, 40][$i]) : $date->addDays(mt_rand(120, 365));
            $contract = Contract::create([
                'number' => $numbers->next('contract'), 'contract_date' => $date->toDateString(), 'counterparty_id' => $cp->id,
                'kind' => $sale ? 'sale' : 'purchase', 'subject' => $sale ? 'Xidmətlərin göstərilməsi — '.$projects[$i % 10]->name : 'Avadanlıq və materialların sövdələşməni',
                'amount' => $amount, 'currency' => $currency, 'cbar_rate' => $rate, 'rate_date' => $date->toDateString(), 'amount_azn' => round($amount * $rate, 2),
                'start_date' => $date->toDateString(), 'end_date' => $end->toDateString(), 'status' => $i === 14 ? 'draft' : ($i === 13 ? 'completed' : 'active'),
                'project_id' => $projects[$i % 10]->id, 'responsible_id' => $users[$sale ? 1 : 2]->id, 'payment_terms' => '30% avans, qalan hissə təhvildən sonra 15 gün ərzində',
                'auto_renew' => $i % 5 === 0,
            ]);
            $contract->payments()->create(['due_date' => $date->addDays(5)->toDateString(), 'amount' => round($amount * 0.3, 2), 'note' => 'Avans', 'paid_at' => $date->addDays(5)->isPast() ? $date->addDays(5) : null]);
            $contract->payments()->create(['due_date' => $today->addDays(mt_rand(-5, 20))->toDateString(), 'amount' => round($amount * 0.7, 2), 'note' => 'Yekun ödəniş']);
            $contracts->push($contract);
        }

        // Each project's buyer side takes its first sale contract, supplier side its first purchase contract.
        foreach ($contracts->where('status', '!=', 'draft')->sortBy('contract_date') as $c) {
            $p = $projects->firstWhere('id', $c->project_id);
            [$slot, $party] = $c->kind === 'sale' ? ['sale_contract_id', 'counterparty_id'] : ['purchase_contract_id', 'supplier_id'];
            if ($p && ! $p->{$slot} && (! $p->{$party} || $p->{$party} === $c->counterparty_id)) {
                $p->update([$slot => $c->id, $party => $c->counterparty_id]);
            }
        }

        // Bank transactions (~200)
        $ledger = app(BankLedger::class);
        $cats = \App\Models\Category::where('scope', 'bank')->pluck('id', 'name');
        $made = 0;
        for ($i = 0; $made < 200 && $i < 600; $i++) {
            $account = $accounts[mt_rand(0, 9) < 7 ? 0 : mt_rand(1, 3)];
            $date = $account->currency === 'AZN' ? $today->subDays(mt_rand(0, 100)) : ($rateDates ? CarbonImmutable::parse($rateDates[array_rand($rateDates)]) : null);
            if (! $date) {
                continue;
            }
            $in = mt_rand(0, 9) < 4;
            $contract = $contracts->filter(fn ($c) => $c->kind === ($in ? 'sale' : 'purchase') && $c->status !== 'draft')->random();
            $cat = $in ? ($contract->kind === 'sale' ? 'Satışdan daxilolma' : 'Avans') : ['Təchizatçıya ödəniş', 'Əmək haqqı', 'Vergi və rüsumlar', 'İcarə', 'Kommunal xərclər', 'Bank xidməti', 'Logistika xərci'][mt_rand(0, 6)];
            $linked = in_array($cat, ['Satışdan daxilolma', 'Avans', 'Təchizatçıya ödəniş'], true);
            try {
                $ledger->record($account, [
                    'direction' => $in ? 'in' : 'out', 'transaction_date' => $date->toDateString(),
                    'amount' => $account->currency === 'AZN' ? mt_rand(5, 450) * 100 : mt_rand(5, 120) * 100,
                    'counterparty_id' => $linked ? $contract->counterparty_id : null, 'contract_id' => $linked ? $contract->id : null,
                    'project_id' => $linked ? $contract->project_id : null, 'category_id' => $cats[$cat] ?? null,
                    'purpose' => $linked ? ($in ? 'Müqavilə '.$contract->number.' üzrə ödəniş' : 'Müqavilə '.$contract->number.' üzrə köçürmə') : $cat,
                    'reference' => (string) mt_rand(100000, 999999),
                ]);
                $made++;
            } catch (\Throwable) {
                // no CBAR rate for this date — skip rather than invent one
            }
        }
        // One conversion (USD -> AZN) at a bank rate slightly below CBAR, to populate the exchange report.
        if ($rateDates && ($usd = $rates->tryRate('USD', $rateDates[array_key_last($rateDates)]))) {
            $ledger->transfer($accounts[1], $accounts[0], $rateDates[array_key_last($rateDates)], 5000, round(5000 * ($usd - 0.004), 2), ['purpose' => 'USD satışı']);
        }

        // Shipments (30)
        $routes = [['İstanbul, Türkiyə', 'Bakı'], ['Tbilisi, Gürcüstan', 'Gəncə'], ['Həştərxan, Rusiya', 'Bakı limanı'], ['Hamburq, Almaniya', 'Bakı'], ['Bakı', 'Naxçıvan'], ['Ələt limanı', 'Sumqayıt'], ['Şanxay, Çin', 'Bakı']];
        for ($i = 0; $i < 30; $i++) {
            [$from, $to] = $routes[$i % count($routes)];
            $loading = $today->subDays(mt_rand(-5, 60));
            $status = Shipment::FLOW[$loading->isFuture() ? 0 : mt_rand(1, 5)];
            $s = Shipment::create([
                'number' => $numbers->next('shipment'), 'direction' => str_contains($to, 'Bakı') && ! str_contains($from, 'Bakı') ? 'import' : 'domestic',
                'origin' => $from, 'destination' => $to, 'carrier_id' => $suppliers[[1, 2, 3, 5][$i % 4]]->id,
                'transport_mode' => ['road', 'rail', 'sea', 'road', 'air'][$i % 5], 'vehicle' => mt_rand(10, 99).'-'.['AA', 'BC', 'ZZ', 'KM'][$i % 4].'-'.mt_rand(100, 999),
                'container_no' => $i % 3 === 0 ? 'MSCU'.mt_rand(1000000, 9999999) : null, 'document_no' => 'CMR-'.mt_rand(10000, 99999),
                'cargo_description' => ['Tikinti materialları', 'Elektrik avadanlığı', 'Ərzaq məhsulları', 'Metal konstruksiyalar', 'Ofis mebeli'][$i % 5],
                'weight_kg' => mt_rand(500, 22000), 'volume_m3' => mt_rand(5, 80), 'loading_date' => $loading->toDateString(),
                'eta' => $loading->addDays(mt_rand(4, 18))->toDateString(), 'status' => $status,
                'project_id' => $i % 2 ? $projects[$i % 10]->id : null, 'responsible_id' => $users[3]->id,
                'delivered_at' => $status === 'delivered' ? $loading->addDays(mt_rand(5, 20)) : null,
            ]);
            foreach (array_slice(Shipment::FLOW, 0, array_search($status, Shipment::FLOW, true) + 1) as $k => $st) {
                $s->history()->create(['status' => $st, 'changed_by' => $users[3]->id, 'created_at' => $loading->addDays($k * 2)]);
            }
            $cost = mt_rand(8, 60) * 100;
            $s->costs()->create(['cost_type' => 'freight', 'counterparty_id' => $s->carrier_id, 'cost_date' => $loading->isFuture() ? $today->toDateString() : $loading->toDateString(), 'amount' => $cost, 'currency' => 'AZN', 'cbar_rate' => 1, 'amount_azn' => $cost]);
            if ($i % 3 === 0) {
                $s->costs()->create(['cost_type' => 'customs', 'cost_date' => $today->toDateString(), 'amount' => $cost / 2, 'currency' => 'AZN', 'cbar_rate' => 1, 'amount_azn' => $cost / 2]);
            }
        }

        $this->seedDeals();
        app(ReminderService::class)->generate($company);
    }

    /**
     * Sövdələşmələr for projects that have both contracts: one lot each, with a seller's
     * proforma like the company's real working sheet (EUR, CBAR rate of a stored day).
     */
    private function seedDeals(): int
    {
        $rates = app(CurrencyRates::class);
        $numbers = app(NumberGenerator::class);
        $items = [
            ['TD-Weiss Migrastar Gr.1/S/IPA', '32151900', 3200, 4.89], ['Supra EB Cyan Folie FCM', '32151900', 3200, 11.08],
            ['Supra EB Gelb Folie FCM', '32151900', 7400, 11.08], ['Supra EB Schwarz Folie FCM', '32151100', 800, 11.08],
            ['Supra EB Magenta Folie FCM', '32151900', 1600, 11.08], ['Supra EB PANTONE® Transparentweiss', '32151900', 400, 14.62],
            ['Supra EB PANTONE® Reflexblau', '32151900', 800, 22.4], ['Supra EB Warmrot', '32151900', 200, 15.65],
            ['HERMA PE weiss tc (852) 62Gpt / 517', '39199080', 800, 6.67],
        ];
        $made = 0;
        $projects = \App\Models\Project::whereNotNull('sale_contract_id')->doesntHave('deals')->limit(3)->get();
        $spare = \App\Models\Contract::where('kind', 'purchase')->where('status', '!=', 'draft')->orderBy('id')->get();
        foreach ($projects as $k => $p) {
            // Older demo data left purchase contracts unattached: give the project one.
            if (! $p->purchase_contract_id && ($c = $spare->get($k))) {
                $p->update(['purchase_contract_id' => $c->id, 'supplier_id' => $c->counterparty_id]);
            }
            if (! $p->purchase_contract_id) {
                continue;
            }
            $deal = \App\Models\Deal::create([
                'project_id' => $p->id, 'code' => $numbers->next('deal'), 'title' => 'Boya və etiket partiyası #'.($k + 1),
                'deal_date' => today()->subDays(10 + $k * 7), 'currency' => 'EUR', 'status' => 'invoiced',
                'counterparty_id' => $p->counterparty_id, 'sale_contract_id' => $p->sale_contract_id,
                'supplier_id' => $p->supplier_id, 'purchase_contract_id' => $p->purchase_contract_id,
            ]);
            $date = today()->subDays(8 + $k * 7);
            $rate = $rates->tryRate('EUR', $date);
            $lines = [];
            foreach ($items as $i => [$desc, $hs, $qty, $price]) {
                $lines[] = ['line_no' => $i + 1, 'description' => $desc, 'hs_code' => $hs, 'quantity' => $qty, 'uom' => 'kg', 'unit_price' => $price, 'total' => round($qty * $price, 2)];
            }
            $total = array_sum(array_column($lines, 'total'));
            $inv = $deal->invoices()->create([
                'project_id' => $p->id, 'type' => 'supplier', 'number' => (string) (221619 + $k * 37), 'invoice_date' => $date,
                'counterparty_id' => $p->supplier_id, 'contract_id' => $p->purchase_contract_id, 'currency' => 'EUR',
                'total' => $total, 'cbar_rate' => $rate, 'total_azn' => $rate ? round($total * $rate, 2) : null, 'status' => 'confirmed',
                'source_file' => 'proforma-demo.xlsx',
            ]);
            $inv->items()->createMany($lines);
            $made++;
        }

        return $made;
    }
}
