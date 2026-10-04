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
                ['label' => 'İdarə paneli', 'route' => 'dashboard', 'active' => 'dashboard', 'icon' => 'dashboard', 'can' => 'dashboard.view'],
                ['label' => 'Mənim işlərim', 'route' => 'my-work', 'active' => 'my-work', 'icon' => 'calendar-check', 'can' => null],
            ]],
            ['label' => 'İş', 'items' => [
                ['label' => 'Öhdəliklərim', 'route' => 'obligations.index', 'active' => 'obligations.*', 'icon' => 'scale', 'can' => 'projects.view'],
                ['label' => 'Layihələr', 'route' => 'projects.index', 'active' => 'projects.*|tasks.*', 'icon' => 'folder', 'can' => 'projects.view'],
                ['label' => 'CRM', 'route' => 'counterparties.index', 'active' => 'counterparties.*', 'icon' => 'users', 'can' => 'crm.view'],
                ['label' => 'Müqavilələr', 'route' => 'contracts.index', 'active' => 'contracts.*', 'icon' => 'signature', 'can' => 'contracts.view'],
            ]],
            ['label' => 'Maliyyə və təchizat', 'items' => [
                ['label' => 'Xərclər', 'route' => 'expenses.index', 'active' => 'expenses.*', 'icon' => 'receipt', 'can' => 'expenses.view'],
                ['label' => 'Bank hesabları', 'route' => 'bank.accounts.index', 'active' => 'bank.accounts.*', 'icon' => 'wallet', 'can' => 'bank.view'],
                ['label' => 'Valyuta alış-satışı', 'route' => 'bank.exchanges.index', 'active' => 'bank.exchanges.*', 'icon' => 'transfer', 'can' => 'bank.view'],
                ['label' => 'Bank əməliyyatları', 'route' => 'bank.transactions.index', 'active' => 'bank.transactions.*', 'icon' => 'bank', 'can' => 'bank.view'],
                ['label' => 'Valyuta məzənnələri', 'route' => 'currency.index', 'active' => 'currency.*', 'icon' => 'coins', 'can' => 'currency.view'],
            ]],
            ['label' => 'Analitika', 'items' => [
                ['label' => 'Hesabatlar', 'route' => 'analytics.show', 'params' => ['report' => array_key_first(config('glaust.analytics'))], 'active' => 'analytics.*|reports.*', 'icon' => 'chart', 'can' => 'reports.view',
                    'children' => array_merge(
                        array_map(fn ($key, $a) => ['label' => $a[0], 'route' => 'analytics.show', 'params' => ['report' => $key], 'icon' => $a[1]], array_keys(config('glaust.analytics')), config('glaust.analytics')),
                        [['label' => 'Digər hesabatlar', 'route' => 'reports.index', 'params' => [], 'icon' => 'list', 'active' => 'reports.*']],
                    )],
                ['label' => 'Importlar', 'route' => 'imports.index', 'active' => 'imports.*', 'icon' => 'upload', 'can' => ['crm.import', 'projects.import', 'bank.import', 'logistics.import']],
            ]],
            ['label' => 'Sistem', 'items' => [
                ['label' => 'Tənzimləmələr', 'route' => 'settings.index', 'active' => 'settings.*', 'icon' => 'settings', 'can' => ['settings.view', 'users.view', 'logs.view']],
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
            ['g d', 'İdarə paneli', 'dashboard', 'dashboard.view'],
            ['g m', 'Mənim işlərim', 'my-work', null],
            ['g p', 'Layihələr', 'projects.index', 'projects.view'],
            ['g o', 'Öhdəliklərim', 'obligations.index', 'projects.view'],
            ['g c', 'CRM', 'counterparties.index', 'crm.view'],
            ['g q', 'Müqavilələr', 'contracts.index', 'contracts.view'],
            ['g b', 'Bank əməliyyatları', 'bank.transactions.index', 'bank.view'],
            ['g a', 'Bank hesabları', 'bank.accounts.index', 'bank.view'],
            ['g x', 'Xərclər', 'expenses.index', 'expenses.view'],
            ['n e', 'Yeni xərc', 'expenses.create', 'expenses.create'],
            ['g h', 'Hesabatlar', 'reports.index', 'reports.view'],
            ['g v', 'Valyuta məzənnələri', 'currency.index', 'currency.view'],
            ['n p', 'Yeni layihə', 'projects.create', 'projects.create'],
            ['n t', 'Yeni tapşırıq', 'tasks.create', 'projects.create'],
            ['n m', 'Yeni müştəri', ['counterparties.create', ['type' => 'customer']], 'crm.create'],
            ['n s', 'Yeni təchizatçı', ['counterparties.create', ['type' => 'supplier']], 'crm.create'],
            ['n q', 'Yeni müqavilə', 'contracts.create', 'contracts.create'],
            ['n b', 'Bank əməliyyatı', 'bank.transactions.create', 'bank.create'],
            ['n x', 'Xatırlatma', 'my-work', null],
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
                    $commands[] = ['label' => $link['label'], 'group' => isset($item['children']) ? $item['label'] : 'Keçid', 'icon' => $link['icon'], 'url' => route($link['route'], $link['params'] ?? []), 'keywords' => isset($item['children']) ? 'hesabat' : ''];
                }
            }
        }
        foreach (self::shortcuts($user) as $s) {
            if (str_starts_with($s['keys'], 'n ')) {
                $commands[] = ['label' => $s['label'], 'group' => 'Yarat', 'icon' => 'plus', 'url' => $s['url'], 'keywords' => 'yeni əlavə et yarat', 'keys' => $s['keys']];
            }
        }
        if (self::allowed($user, 'crm.import')) {
            $commands[] = ['label' => 'Excel import', 'group' => 'Yarat', 'icon' => 'upload', 'url' => route('imports.index'), 'keywords' => 'excel yüklə'];
        }
        $commands[] = ['label' => 'Profil və təhlükəsizlik', 'group' => 'Hesab', 'icon' => 'user', 'url' => route('profile.edit'), 'keywords' => 'şifrə 2fa'];

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
