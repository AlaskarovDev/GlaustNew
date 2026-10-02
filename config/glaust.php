<?php

/*
 * Glaust MS domain configuration: modules, permissions, status vocabularies.
 * Labels are Azerbaijani UI strings; keys are stable identifiers stored in the DB.
 */

return [

    // Run the scheduler from web traffic when no real cron has been seen (shared hosting).
    'web_scheduler' => env('GLAUST_WEB_SCHEDULER', true),

    'registration_open' => env('GLAUST_REGISTRATION_OPEN', true),

    'cbar' => [
        'url' => env('CBAR_URL', 'https://cbar.az/currencies/%s.xml'),
        'timeout' => 10,
        'recent_ttl' => 3600,          // today/yesterday may still change
        'past_ttl' => 30 * 24 * 3600,  // published history never changes
    ],

    // Currencies offered in forms (AZN is the base, rate always 1).
    'currencies' => ['AZN', 'USD', 'EUR', 'RUB', 'TRY', 'GBP', 'GEL', 'CNY', 'AED', 'KZT', 'UAH', 'CHF', 'JPY'],
    'default_ticker' => ['USD', 'EUR', 'RUB', 'TRY', 'GBP', 'GEL'],

    // Module => permission actions available for it.
    'modules' => [
        'dashboard' => ['label' => 'İdarə paneli', 'actions' => ['view']],
        'projects' => ['label' => 'Layihələr', 'actions' => ['view', 'create', 'update', 'delete', 'export', 'import']],
        'crm' => ['label' => 'CRM', 'actions' => ['view', 'create', 'update', 'delete', 'export', 'import']],
        'contracts' => ['label' => 'Müqavilələr', 'actions' => ['view', 'create', 'update', 'delete', 'export']],
        'bank' => ['label' => 'Bank əməliyyatları', 'actions' => ['view', 'create', 'update', 'delete', 'export', 'import']],
        'logistics' => ['label' => 'Logistika', 'actions' => ['view', 'create', 'update', 'delete', 'export', 'import']],
        'reports' => ['label' => 'Hesabatlar', 'actions' => ['view', 'export']],
        'currency' => ['label' => 'Valyuta məzənnələri', 'actions' => ['view']],
        'settings' => ['label' => 'Tənzimləmələr', 'actions' => ['view', 'update']],
        'users' => ['label' => 'İstifadəçilər', 'actions' => ['view', 'create', 'update', 'delete']],
        'logs' => ['label' => 'Loglar və audit', 'actions' => ['view', 'export']],
    ],

    // Modules a plan can switch on/off (dashboard, currency, settings, users, logs are always on).
    'plan_modules' => ['projects', 'crm', 'contracts', 'bank', 'logistics', 'reports'],

    'actions' => [
        'view' => 'Baxış', 'create' => 'Yaratma', 'update' => 'Redaktə', 'delete' => 'Silmə',
        'export' => 'Export', 'import' => 'Import',
    ],

    'system_roles' => [
        'admin' => ['name' => 'Admin', 'is_admin' => true, 'permissions' => ['*']],
        'manager' => ['name' => 'Menecer', 'permissions' => [
            'dashboard.*', 'projects.*', 'crm.*', 'contracts.*', 'logistics.view', 'logistics.create', 'logistics.update',
            'reports.*', 'currency.view', 'bank.view',
        ]],
        'accountant' => ['name' => 'Mühasib', 'permissions' => [
            'dashboard.*', 'bank.*', 'contracts.view', 'contracts.export', 'crm.view', 'crm.create', 'crm.update', 'crm.export',
            'reports.*', 'currency.view', 'projects.view',
        ]],
        'logistician' => ['name' => 'Logist', 'permissions' => [
            'dashboard.*', 'logistics.*', 'crm.view', 'crm.create', 'contracts.view', 'projects.view', 'currency.view', 'reports.view',
        ]],
        'employee' => ['name' => 'İşçi', 'permissions' => [
            'dashboard.view', 'projects.view', 'crm.view', 'currency.view',
        ]],
    ],

    'statuses' => [
        'project' => [
            'planned' => ['Planlaşdırılıb', 'slate'],
            'active' => ['Aktiv', 'teal'],
            'on_hold' => ['Dayandırılıb', 'amber'],
            'completed' => ['Tamamlanıb', 'green'],
            'cancelled' => ['Ləğv edilib', 'rose'],
        ],
        'task' => [
            'todo' => ['Görüləcək', 'slate'],
            'in_progress' => ['İcrada', 'blue'],
            'review' => ['Yoxlamada', 'violet'],
            'done' => ['Tamamlandı', 'green'],
        ],
        'priority' => [
            'low' => ['Aşağı', 'slate'],
            'medium' => ['Orta', 'blue'],
            'high' => ['Yüksək', 'amber'],
            'critical' => ['Kritik', 'rose'],
        ],
        'contract' => [
            'draft' => ['Layihə', 'slate'],
            'signed' => ['İmzalanıb', 'blue'],
            'active' => ['İcrada', 'teal'],
            'completed' => ['Bitib', 'green'],
            'cancelled' => ['Ləğv', 'rose'],
        ],
        'shipment' => [
            'planned' => ['Planlaşdırılıb', 'slate'],
            'loading' => ['Yüklənir', 'violet'],
            'in_transit' => ['Yolda', 'blue'],
            'customs' => ['Gömrükdə', 'amber'],
            'arrived' => ['Çatdı', 'teal'],
            'delivered' => ['Təhvil verildi', 'green'],
        ],
        'subscription' => [
            'trial' => ['Sınaq', 'amber'],
            'active' => ['Aktiv', 'green'],
            'suspended' => ['Dayandırılıb', 'rose'],
        ],
    ],

    'counterparty_types' => ['customer' => 'Müştəri', 'supplier' => 'Təchizatçı', 'both' => 'Müştəri və təchizatçı'],
    'entity_types' => ['legal' => 'Hüquqi şəxs', 'individual' => 'Fiziki şəxs'],
    'contract_kinds' => ['sale' => 'Satış (müştəri ilə)', 'purchase' => 'Alış (təchizatçı ilə)'],
    'shipment_directions' => ['import' => 'İdxal', 'export' => 'İxrac', 'domestic' => 'Daxili'],
    'transport_modes' => ['road' => 'Avto', 'rail' => 'Dəmir yolu', 'sea' => 'Dəniz', 'air' => 'Hava'],
    'cost_types' => ['freight' => 'Fraxt', 'customs' => 'Gömrük', 'insurance' => 'Sığorta', 'storage' => 'Anbar', 'other' => 'Digər'],
    'transaction_kinds' => ['regular' => 'Adi', 'transfer' => 'Hesablararası köçürmə', 'conversion' => 'Konvertasiya'],

    'reminder_sources' => [
        'personal' => 'Şəxsi',
        'contract_end' => 'Müqavilənin bitməsi',
        'contract_payment' => 'Ödəniş tarixi',
        'task_due' => 'Tapşırığın son tarixi',
        'shipment_delay' => 'Gecikən yük',
    ],

    // Defaults for company settings (merged with companies.settings JSON).
    'company_defaults' => [
        'ticker_currencies' => ['USD', 'EUR', 'RUB', 'TRY', 'GBP', 'GEL'],
        'contract_reminder_days' => [30, 7, 1],
        'payment_reminder_days' => [3, 0],
        'task_reminder_days' => [1, 0],
        'digest_time' => '08:30',
        'numbering' => [
            'project' => 'PRJ-{Y}-{SEQ:4}',
            'contract' => 'MQ-{Y}-{SEQ:4}',
            'shipment' => 'YK-{Y}-{SEQ:4}',
        ],
    ],

    'upload' => [
        'max_kb' => 15360,
        'mimes' => 'pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,csv,txt,zip',
    ],
];
