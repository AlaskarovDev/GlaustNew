<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Support\DealObligations;
use Illuminate\View\View;

/**
 * Öhdəliklərim: every deal's obligations added up, per currency (never converted), in four groups —
 * money we must pay, goods we must supply, money due to us, goods due to us — each broken down
 * by project and deal.
 */
class ObligationController extends Controller
{
    public const GROUPS = [
        'pay' => ['Ödəməli olduğum', 'Satıcılara və logistikaya ödəniş', 'arrow-up-right', 'danger'],
        'supply' => ['Məhsulla təmin etməli olduğum', 'Ödəniş edən alıcılara məhsul', 'package', 'brand'],
        'receive' => ['Mənə gəlməli ödənişlər', 'Alıcıların qalıq borcu', 'arrow-down-left', 'success'],
        'deliver' => ['Mənə təhvil verilməli məhsul', 'Ödəniş etdiyimiz satıcılardan', 'truck', 'saffron'],
    ];

    public function index(): View
    {
        $deals = Deal::with(['project', 'counterparty', 'supplier', 'invoices', 'salesDocuments', 'payments', 'supplierPayments', 'logisticsActs.payments', 'logisticsActs.counterparty'])
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('deal_date')->get();

        $groups = array_fill_keys(array_keys(self::GROUPS), ['total' => [], 'projects' => []]);
        foreach ($deals as $deal) {
            $ob = DealObligations::for($deal);
            $lines = [
                'pay' => array_filter([
                    $ob['seller']['due'] ? ['who' => $deal->supplier?->name, 'what' => 'Satıcıya ödəniş', 'amounts' => $ob['seller']['due']] : null,
                    $ob['logistics']['due'] ? ['who' => $ob['logistics']['company'] ?? 'Logistika şirkəti', 'what' => 'Logistika xərci'.($ob['logistics']['forecast'] ? ' (proqnoz)' : ''), 'amounts' => $ob['logistics']['due']] : null,
                ]),
                'supply' => $ob['buyer']['goods'] ? [['who' => $deal->counterparty?->name, 'what' => 'Ödədiyi məbləğ qədər məhsul', 'amounts' => $ob['buyer']['goods']]] : [],
                'receive' => $ob['buyer']['due'] ? [['who' => $deal->counterparty?->name, 'what' => 'Proforma üzrə qalıq', 'amounts' => $ob['buyer']['due']]] : [],
                'deliver' => $ob['seller']['goods'] ? [['who' => $deal->supplier?->name, 'what' => 'Ödədiyimiz məbləğ qədər məhsul', 'amounts' => $ob['seller']['goods']]] : [],
            ];
            foreach ($lines as $key => $items) {
                foreach ($items as $item) {
                    $pid = $deal->project_id;
                    $groups[$key]['projects'][$pid] ??= ['project' => $deal->project, 'total' => [], 'deals' => []];
                    $groups[$key]['projects'][$pid]['deals'][] = $item + ['deal' => $deal];
                    foreach ($item['amounts'] as $cur => $v) {
                        $groups[$key]['total'][$cur] = round(($groups[$key]['total'][$cur] ?? 0) + $v, 2);
                        $groups[$key]['projects'][$pid]['total'][$cur] = round(($groups[$key]['projects'][$pid]['total'][$cur] ?? 0) + $v, 2);
                    }
                }
            }
        }

        return view('obligations.index', ['groups' => $groups, 'meta' => self::GROUPS, 'dealCount' => $deals->count()]);
    }
}
