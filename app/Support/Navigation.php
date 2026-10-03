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
                ['label' => 'Layihələr', 'route' => 'projects.index', 'active' => 'projects.*|tasks.*', 'icon' => 'folder', 'can' => 'projects.view'],
                ['label' => 'CRM', 'route' => 'counterparties.index', 'active' => 'counterparties.*', 'icon' => 'users', 'can' => 'crm.view'],
                ['label' => 'Müqavilələr', 'route' => 'contracts.index', 'active' => 'contracts.*', 'icon' => 'signature', 'can' => 'contracts.view'],
            ]],
            ['label' => 'Maliyyə və təchizat', 'items' => [
                ['label' => 'Bank hesabları', 'route' => 'bank.accounts.index', 'active' => 'bank.accounts.*', 'icon' => 'wallet', 'can' => 'bank.view'],
                ['label' => 'Bank əməliyyatları', 'route' => 'bank.transactions.index', 'active' => 'bank.transactions.*', 'icon' => 'bank', 'can' => 'bank.view'],
                ['label' => 'Logistika', 'route' => 'shipments.index', 'active' => 'shipments.*', 'icon' => 'truck', 'can' => 'logistics.view'],
                ['label' => 'Valyuta məzənnələri', 'route' => 'currency.index', 'active' => 'currency.*', 'icon' => 'coins', 'can' => 'currency.view'],
            ]],
            ['label' => 'Analitika', 'items' => [
                ['label' => 'Hesabatlar', 'route' => 'reports.index', 'active' => 'reports.*', 'icon' => 'chart', 'can' => 'reports.view'],
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
            ['g c', 'CRM', 'counterparties.index', 'crm.view'],
            ['g q', 'Müqavilələr', 'contracts.index', 'contracts.view'],
            ['g b', 'Bank əməliyyatları', 'bank.transactions.index', 'bank.view'],
            ['g a', 'Bank hesabları', 'bank.accounts.index', 'bank.view'],
            ['g l', 'Logistika', 'shipments.index', 'logistics.view'],
            ['g h', 'Hesabatlar', 'reports.index', 'reports.view'],
            ['g v', 'Valyuta məzənnələri', 'currency.index', 'currency.view'],
            ['n p', 'Yeni layihə', 'projects.create', 'projects.create'],
            ['n t', 'Yeni tapşırıq', 'tasks.create', 'projects.create'],
            ['n m', 'Yeni müştəri', ['counterparties.create', ['type' => 'customer']], 'crm.create'],
            ['n s', 'Yeni təchizatçı', ['counterparties.create', ['type' => 'supplier']], 'crm.create'],
            ['n q', 'Yeni müqavilə', 'contracts.create', 'contracts.create'],
            ['n b', 'Bank əməliyyatı', 'bank.transactions.create', 'bank.create'],
            ['n y', 'Yeni yük', 'shipments.create', 'logistics.create'],
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
                $commands[] = ['label' => $item['label'], 'group' => 'Keçid', 'icon' => $item['icon'], 'url' => route($item['route']), 'keywords' => ''];
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
