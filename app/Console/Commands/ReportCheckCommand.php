<?php

namespace App\Console\Commands;

use App\Models\BankAccount;
use App\Models\Company;
use App\Models\CurrencyExchange;
use App\Models\Deal;
use App\Models\Expense;
use App\Support\Reports\BalanceReport;
use App\Support\Reports\CashflowReport;
use App\Support\Reports\ProfitLossReport;
use App\Support\Reports\TradeReport;
use App\Support\Tenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Read-only: the figures behind the reports of one company — to compare them with the company's own Excel (MZ, cash
 * flow, balance) without opening the pages. Prints sums only; changes nothing. Without a company it lists them.
 */
class ReportCheckCommand extends Command
{
    protected $signature = 'glaust:report-check {company? : id or a part of the name} {--from=2024-12} {--to=2025-02} {--only= : cash|items|trades|expenses|mz|balance} {--label= : with --only=items: the cash flow line(s) whose name contains it} {--valuation=bank}';

    protected $description = 'Hesabatların rəqəmlərini çap edir (yalnız oxuyur)';

    public function handle(): int
    {
        $key = (string) $this->argument('company');
        if ($key === '') {
            Company::orderBy('id')->get(['id', 'name'])->each(fn ($c) => $this->line("#{$c->id} {$c->name}"));

            return self::SUCCESS;
        }
        $company = ctype_digit($key) ? Company::find($key) : Company::where('name', 'like', "%{$key}%")->first();
        if (! $company) {
            $this->error('Şirkət tapılmadı.');

            return self::FAILURE;
        }
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');
        $end = Carbon::createFromFormat('!Y-m', $to)->endOfMonth()->toDateString();

        $only = (string) $this->option('only');
        $want = fn (string $part) => $only === '' || $only === $part;
        app(Tenant::class)->runAs($company, function () use ($from, $to, $end, $want) {
            $n = fn ($v) => $v === null ? '—' : number_format((float) $v, 2, '.', ' ');

            if ($want('mz')) {
                $this->info("== MZ {$from} … {$to}");
                $pl = (new ProfitLossReport)->build($from, $to);
                foreach ($pl['lines'] as $code => $l) {
                    $this->line(str_pad($code, 9).str_pad(mb_substr($l['label'], 0, 40), 42).implode(' ', array_map(fn ($v) => str_pad($n($v), 14, ' ', STR_PAD_LEFT), $l['values'])).'  Σ '.$n($l['total']));
                }
                foreach (['in', 'out', 'result', 'tax'] as $k) {
                    $this->line(str_pad('= '.$k, 51).implode(' ', array_map(fn ($v) => str_pad($n($v), 14, ' ', STR_PAD_LEFT), $pl[$k])).'  Σ '.$n(array_sum($pl[$k])));
                }
            }
            if ($want('balance')) {
                $b = app(BalanceReport::class)->build($end);
                $this->info("== BALANCE {$end}");
                foreach (['assets', 'liabilities'] as $side) {
                    foreach ($b[$side] as $code => $l) {
                        $this->line(str_pad($code, 5).str_pad(mb_substr($l['label'], 0, 44), 46).str_pad($n($l['value']), 16, ' ', STR_PAD_LEFT));
                    }
                }
                $this->line('assets '.$n($b['assetsTotal']).' · liabilities '.$n($b['liabilitiesTotal']).' · gap '.$n($b['gap']));
            }
            if ($this->option('only') === 'items') {
                // every movement behind the cash flow lines whose name contains --label
                $d = (new CashflowReport(null, (string) $this->option('valuation')))->build($from, $to);
                $needle = mb_strtolower((string) $this->option('label'));
                foreach (array_merge($d['income'], $d['payments'], $d['other']) as $g) {
                    if ($needle !== '' && ! str_contains(mb_strtolower($g['label']), $needle)) {
                        continue;
                    }
                    $this->info('== '.$g['label']);
                    foreach ($g['details'] as $m => $items) {
                        foreach ($items as $i) {
                            $this->line($i['d'].' '.str_pad($n($i['v']), 14, ' ', STR_PAD_LEFT).'  '.mb_substr($i['t'], 0, 80).($i['c'] ? ' | '.$i['c'] : ''));
                        }
                    }
                }

                return;
            }
            if ($want('cash')) {
                $this->info("== CASH FLOW {$from} … {$to}");
                $d = (new CashflowReport(null, (string) $this->option('valuation')))->build($from, $to);
                $row = fn ($label, $vals) => $this->line(str_pad(mb_substr($label, 0, 34), 36).implode(' ', array_map(fn ($v) => str_pad($n($v), 15, ' ', STR_PAD_LEFT), array_values($vals))));
                $row('opening', $d['opening']);
                foreach ($d['income'] as $g) {
                    $row('+ '.$g['label'], $g['values']);
                }
                $row('= in', $d['in']);
                foreach ($d['payments'] as $g) {
                    $row('- '.$g['label'], $g['values']);
                }
                $row('= out', $d['out']);
                foreach ($d['other'] as $g) {
                    $row('± '.$g['label'], $g['values']);
                }
                $row('= net', $d['net']);
                $row('= closing', $d['closing']);
                foreach ($d['cash']['accounts'] ?? [] as $a) {
                    $row('  '.$a['label'].' '.$a['currency'], $a['values']);
                    $row('    native', $a['native']);
                }
                $row('  accounts total', $d['cash']['total'] ?? []);
            }
            if (! $want('trades') && ! $want('expenses')) {
                return;
            }
            if ($want('trades')) {

                $this->info("== TRADES (act ≤ {$end}): BM sale @act, BL purchase @act, BO logistics @act, 1C 214 / 219.3 (1 Jan → {$end})");
                $rows = app(TradeReport::class)->rows(Deal::query()->get(), $end);
                $sum = ['BM' => 0, 'BL' => 0, 'BO' => 0, 'C1_P' => 0, 'C1_N' => 0];
                foreach ($rows as $r) {
                    $act = $r['provisional'] ? 'ay sonu' : ($r['BI']?->format('Y-m-d') ?? '—');
                    $this->line(sprintf('%-12s %-10s %-10s BM %14s BL %14s BO %12s  214 %10s 219.3 %10s', $r['seller_no'], $r['deal']->code, $act,
                        $n($r['BM']), $n($r['BL']), $n($r['BO']), $n($r['C1_P']), $n($r['C1_N'])));
                    if (! $r['provisional'] && $r['BI'] && $r['BI']->format('Y-m-d') >= substr($end, 0, 4).'-01-01') {
                        foreach (['BM', 'BL', 'BO'] as $k) {
                            $sum[$k] += (float) $r[$k];
                        }
                    }
                    $sum['C1_P'] += (float) $r['C1_P'];
                    $sum['C1_N'] += (float) $r['C1_N'];
                }
                $this->line('acts in the year: '.json_encode(array_map(fn ($v) => round($v, 2), $sum)));
            }
            if (! $want('expenses')) {
                return;
            }

            $this->info('== EXPENSES by category and month (expense_date), AZN');
            Expense::with('category:id,name')->whereBetween('expense_date', [$from.'-01', $end])->get()
                ->groupBy(fn ($e) => ($e->category?->name ?? '—').' ['.$e->status.'/'.($e->payment_method ?? '-').']')
                ->each(fn ($g, $k) => $this->line(str_pad(mb_substr($k, 0, 50), 52).json_encode($g->groupBy(fn ($e) => $e->expense_date->format('Y-m'))
                    ->map(fn ($m) => round($m->sum(fn ($e) => (float) ($e->amount_azn ?? $e->amount)), 2)))));

            $this->info('== EXCHANGES (bank vs CBAR, AZN) by month');
            $this->line(json_encode(CurrencyExchange::whereBetween('exchange_date', [$from.'-01', $end])->get()
                ->groupBy(fn ($x) => $x->exchange_date->format('Y-m'))->map(fn ($g) => round($g->sum(fn ($x) => (float) $x->difference_azn), 2))));

            $this->info('== ACCOUNTS');
            BankAccount::withTrashed()->get()->each(fn ($a) => $this->line("#{$a->id} {$a->bank_name} · {$a->name} {$a->currency} opening {$a->opening_balance} {$a->opening_date?->format('Y-m-d')} iban ".($a->iban ? 'yes' : 'no').($a->trashed() ? ' (silinib)' : '')));
        });

        return self::SUCCESS;
    }
}
