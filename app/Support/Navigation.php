<?php

namespace App\Support;

use App\Models\User;

/** Sidebar, command palette and keyboard shortcuts — one source, filtered by permission. */
class Navigation
{
    public static function sidebar(User $user): array
    {
        $sections = [
            ['label' => null, 'items' => [
                ['label' => __('İdarə paneli'), 'route' => 'dashboard', 'active' => 'dashboard', 'icon' => 'dashboard', 'can' => 'dashboard.view'],
                ['label' => __('Mənim işlərim'), 'route' => 'my-work', 'active' => 'my-work', 'icon' => 'calendar-check', 'can' => null],
            ]],
            ['label' => __('İş'), 'items' => [
                ['label' => __('Öhdəliklərim'), 'route' => 'obligations.index', 'active' => 'obligations.*', 'icon' => 'scale', 'can' => 'projects.view'],
                ['label' => __('Layihələr'), 'route' => 'projects.index', 'active' => 'projects.*|tasks.*', 'icon' => 'folder', 'can' => 'projects.view'],
                ['label' => 'CRM', 'route' => 'counterparties.index', 'active' => 'counterparties.*', 'icon' => 'users', 'can' => 'crm.view'],
                ['label' => __('Müqavilələr'), 'route' => 'contracts.index', 'active' => 'contracts.*', 'icon' => 'signature', 'can' => 'contracts.view'],
            ]],
            ['label' => __('Maliyyə və təchizat'), 'items' => [
                ['label' => __('Xərclər'), 'route' => 'expenses.index', 'active' => 'expenses.*', 'icon' => 'receipt', 'can' => 'expenses.view'],
                ['label' => __('Bank hesabları'), 'route' => 'bank.accounts.index', 'active' => 'bank.accounts.*', 'icon' => 'wallet', 'can' => 'bank.view'],
                ['label' => __('Valyuta alış-satışı'), 'route' => 'bank.exchanges.index', 'active' => 'bank.exchanges.*', 'icon' => 'transfer', 'can' => 'bank.view'],
                ['label' => __('Bank əməliyyatları'), 'route' => 'bank.transactions.index', 'active' => 'bank.transactions.*', 'icon' => 'bank', 'can' => 'bank.view'],
                ['label' => __('Valyuta məzənnələri'), 'route' => 'currency.index', 'active' => 'currency.*', 'icon' => 'coins', 'can' => 'currency.view'],
                ['label' => __('Məzənnə fərqi'), 'route' => 'fx-difference.index', 'active' => 'fx-difference.*', 'icon' => 'scale', 'can' => 'currency.view'],
            ]],
            ['label' => __('Analitika'), 'items' => [
                ['label' => __('Hesabatlar'), 'route' => 'analytics.show', 'params' => ['report' => array_key_first(config('glaust.analytics'))], 'active' => 'analytics.*|reports.*|profit.*', 'icon' => 'chart', 'can' => 'reports.view',
                    'children' => array_merge(
                        array_map(fn ($key, $a) => ['label' => __($a[0]), 'route' => 'analytics.show', 'params' => ['report' => $key], 'icon' => $a[1]], array_keys(config('glaust.analytics')), config('glaust.analytics')),
                        [['label' => __('Mənfəətin hesablanması'), 'route' => 'profit.index', 'params' => [], 'icon' => 'target', 'active' => 'profit.*'],
                         ['label' => __('Digər hesabatlar'), 'route' => 'reports.index', 'params' => [], 'icon' => 'list', 'active' => 'reports.*']],
                    )],
                ['label' => __('Importlar'), 'route' => 'imports.index', 'active' => 'imports.*', 'icon' => 'upload', 'can' => ['crm.import', 'projects.import', 'bank.import', 'logistics.import']],
            ]],
            ['label' => __('Sistem'), 'items' => [
                ['label' => __('Tənzimləmələr'), 'route' => 'settings.index', 'active' => 'settings.*', 'icon' => 'settings', 'can' => ['settings.view', 'users.view', 'logs.view']],
            ]],
        ];

        foreach ($sections as $i => $section) {
            $sections[$i]['items'] = array_values(array_filter($section['items'], fn ($item) => self::allowed($user, $item['can'])));
        }

        return array_values(array_filter($sections, fn ($s) => $s['items']));
    }

    /** Shortcuts: [keys, label, route, permission]. */
    public static function shortcuts(User $user): array
    {
        $all = [
            ['g d', __('İdarə paneli'), 'dashboard', 'dashboard.view'],
            ['g m', __('Mənim işlərim'), 'my-work', null],
            ['g p', __('Layihələr'), 'projects.index', 'projects.view'],
            ['g o', __('Öhdəliklərim'), 'obligations.index', 'projects.view'],
            ['g c', 'CRM', 'counterparties.index', 'crm.view'],
            ['g q', __('Müqavilələr'), 'contracts.index', 'contracts.view'],
            ['g b', __('Bank əməliyyatları'), 'bank.transactions.index', 'bank.view'],
            ['g a', __('Bank hesabları'), 'bank.accounts.index', 'bank.view'],
            ['g x', __('Xərclər'), 'expenses.index', 'expenses.view'],
            ['n e', __('Yeni xərc'), 'expenses.create', 'expenses.create'],
            ['g h', __('Hesabatlar'), 'reports.index', 'reports.view'],
            ['g v', __('Valyuta məzənnələri'), 'currency.index', 'currency.view'],
            ['n p', __('Yeni layihə'), 'projects.create', 'projects.create'],
            ['n t', __('Yeni tapşırıq'), 'tasks.create', 'projects.create'],
            ['n m', __('Yeni müştəri'), ['counterparties.create', ['type' => 'customer']], 'crm.create'],
            ['n s', __('Yeni təchizatçı'), ['counterparties.create', ['type' => 'supplier']], 'crm.create'],
            ['n q', __('Yeni müqavilə'), 'contracts.create', 'contracts.create'],
            ['n b', __('Bank əməliyyatı'), 'bank.transactions.create', 'bank.create'],
            ['n x', __('Xatırlatma'), 'my-work', null],
        ];

        $out = [];
        foreach ($all as [$keys, $label, $route, $can]) {
            if (! self::allowed($user, $can)) {
                continue;
            }
            [$name, $params] = is_array($route) ? $route : [$route, []];
            $out[] = ['keys' => $keys, 'label' => $label, 'url' => route($name, $params)];
        }

        return $out;
    }

    public static function commands(User $user): array
    {
        $commands = [];
        foreach (self::sidebar($user) as $section) {
            foreach ($section['items'] as $item) {
                foreach ($item['children'] ?? [$item] as $link) {
                    $commands[] = ['label' => $link['label'], 'group' => isset($item['children']) ? $item['label'] : __('Keçid'), 'icon' => $link['icon'], 'url' => route($link['route'], $link['params'] ?? []), 'keywords' => isset($item['children']) ? 'hesabat' : ''];
                }
            }
        }
        foreach (self::shortcuts($user) as $s) {
            if (str_starts_with($s['keys'], 'n ')) {
                $commands[] = ['label' => $s['label'], 'group' => __('Yarat'), 'icon' => 'plus', 'url' => $s['url'], 'keywords' => __('yeni əlavə et yarat'), 'keys' => $s['keys']];
            }
        }
        if (self::allowed($user, 'crm.import')) {
            $commands[] = ['label' => __('Excel import'), 'group' => __('Yarat'), 'icon' => 'upload', 'url' => route('imports.index'), 'keywords' => __('excel yüklə')];
        }
        $commands[] = ['label' => __('Profil və təhlükəsizlik'), 'group' => __('Hesab'), 'icon' => 'user', 'url' => route('profile.edit'), 'keywords' => __('şifrə 2fa')];

        return $commands;
    }

    public static function allowed(User $user, string|array|null $can): bool
    {
        if ($can === null) {
            return true;
        }
        foreach ((array) $can as $ability) {
            if ($user->hasPermission($ability)) {
                return true;
            }
        }

        return false;
    }
}
