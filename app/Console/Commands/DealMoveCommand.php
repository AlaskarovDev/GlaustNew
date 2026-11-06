<?php

namespace App\Console\Commands;

use App\Models\BankTransaction;
use App\Models\Company;
use App\Models\CurrencyExchange;
use App\Models\Deal;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\SalesDocument;
use App\Models\User;
use App\Services\NumberGenerator;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Moves Trades (by their seller invoice №) into a project — created when missing, with the buyer, the seller and the
 * contracts of the last Trade moved and the members of the projects they leave. Everything booked on a Trade goes
 * with it (invoices, buyer documents, bank movements, currency exchanges, expenses); no money moves.
 * --dry prints what would be done and rolls everything back.
 */
class DealMoveCommand extends Command
{
    protected $signature = 'glaust:deal-move {company : id} {--deals= : seller invoice №s, comma separated} {--project= : the target project\'s name} {--dry}';

    protected $description = 'Trade-ləri (bütün qeydləri ilə) başqa layihəyə köçürür; layihə yoxdursa yaradılır';

    public function handle(NumberGenerator $numbers): int
    {
        $company = Company::find($this->argument('company'));
        $nos = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('deals')))));
        $name = trim((string) $this->option('project'));
        if (! $company || ! $nos || $name === '') {
            $this->error('Şirkət, --deals və ya --project yoxdur.');

            return self::FAILURE;
        }

        return app(Tenant::class)->runAs($company, function () use ($nos, $name, $numbers) {
            $deals = [];
            foreach ($nos as $no) {
                $deal = Invoice::with('deal')->where('type', 'supplier')->where('number', $no)->first()?->deal;
                if (! $deal) {
                    $this->error("{$no}: Trade tapılmadı.");

                    return self::FAILURE;
                }
                $deals[] = $deal;
            }

            try {
                return DB::transaction(function () use ($deals, $name, $numbers) {
                    $project = Project::where('name', $name)->first();
                    if (! $project) {
                        $last = collect($deals)->sortBy('deal_date')->last();
                        $project = Project::create(['code' => $numbers->next('project'), 'name' => $name, 'status' => 'active', 'priority' => 'medium', 'currency' => 'EUR',
                            'counterparty_id' => $last->counterparty_id, 'supplier_id' => $last->supplier_id,
                            'sale_contract_id' => $last->sale_contract_id, 'purchase_contract_id' => $last->purchase_contract_id,
                            'manager_id' => User::forTenant()->orderBy('id')->value('id'), 'start_date' => collect($deals)->min('deal_date')]);
                        $members = DB::table('project_user')->whereIn('project_id', collect($deals)->pluck('project_id')->unique())->pluck('user_id')->unique();
                        $project->members()->sync($members->all());
                        $this->line("Layihə yaradıldı: {$project->code} «{$name}»");
                    }
                    foreach ($deals as $deal) {
                        if ($deal->project_id === $project->id) {
                            $this->line("{$deal->code}: artıq «{$name}» layihəsindədir");

                            continue;
                        }
                        $to = ['project_id' => $project->id];
                        $n = [
                            'faktura' => Invoice::where('deal_id', $deal->id)->update($to),
                            'sənəd' => SalesDocument::where('deal_id', $deal->id)->update($to),
                            'bank' => BankTransaction::where('deal_id', $deal->id)->update($to),
                            'mübadilə' => CurrencyExchange::where('deal_id', $deal->id)->update($to),
                            'xərc' => Expense::where('deal_id', $deal->id)->update($to),
                        ];
                        $from = $deal->project?->name ?? '—';
                        $deal->update($to);
                        $this->line("{$deal->code} ({$deal->title}): «{$from}» → «{$name}» · ".collect($n)->map(fn ($c, $k) => "{$c} {$k}")->implode(', '));
                    }
                    if ($this->option('dry')) {
                        throw new \RuntimeException('dry');
                    }

                    return self::SUCCESS;
                });
            } catch (\RuntimeException $e) {
                if ($e->getMessage() !== 'dry') {
                    throw $e;
                }
                $this->info('Yoxlama: heç nə dəyişmədi.');

                return self::SUCCESS;
            }
        });
    }
}
