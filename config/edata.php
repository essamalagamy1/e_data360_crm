<?php

/*
|--------------------------------------------------------------------------
| E-Data 360 Business Management
|--------------------------------------------------------------------------
| Central settings for the rebuilt management layer. Nothing here touches
| stored data: retired modules are hidden and closed, never dropped.
*/

return [

    'app_name' => 'E-Data 360 Business Management',

    /*
    | Modules the company does not use. Their menus disappear and their URLs
    | answer with a redirect to the dashboard. Tables and rows stay untouched,
    | so re-enabling a module is only a matter of removing its prefix here.
    */
    'retired_prefixes' => [
        // CRM System: leads, deals, forms, contracts
        'leads', 'lead_stages', 'deals', 'pipelines', 'stages', 'labels', 'sources',
        'form_builder', 'form_field', 'form_response', 'form', 'form_view_store',
        'contract', 'contractType', 'contract_type', 'contract_status', 'crm-dashboard',
        'reports-lead', 'reports-deal',
        // POS
        'pos', 'pos-dashboard', 'pos-print-setting', 'report/pos', 'barcode/pos', 'setting/pos',
        'reports-daily-pos', 'reports-monthly-pos', 'reports-pos-vs-purchase',
        // Support
        'support',
        // Zoom
        'zoom-meeting', 'zoom-meeting-calender',
        // Messenger
        'chats',
        // Notification templates
        'notification-templates', 'notification_templates', 'notification_template_lang',
    ],

    /*
    | Attendance rules. Each company can override these from the attendance
    | settings panel; the values below are only the starting point.
    */
    'attendance' => [
        'grace_minutes' => 15,
        // Carbon dayOfWeek: 0 = Sunday ... 6 = Saturday
        'work_days' => [0, 1, 2, 3, 4],
        // A session left open longer than this no longer blocks a new clock-in.
        'stale_after_hours' => 16,
    ],

    'tasks' => [
        // A task with no update for this many days appears under "needs attention".
        'stale_after_days' => 7,
        // Notify assignees this many days before the due date.
        'due_soon_days' => 2,
    ],

    'invoices' => [
        'due_soon_days' => 3,
    ],

    'per_page' => 20,

    /*
    | Permission matrix used by the roles screen. Every entry maps one of the
    | four actions to the permission names the rest of the system already uses.
    */
    'modules' => [
        'employees' => [
            'label' => 'الموظفون والمستخدمون',
            'view' => ['manage user', 'manage employee', 'view employee', 'show employee profile'],
            'create' => ['create user', 'create employee'],
            'edit' => ['edit user', 'edit employee'],
            'delete' => ['delete user', 'delete employee'],
            'scoped' => false,
        ],
        'attendance' => [
            'label' => 'الحضور والانصراف',
            'view' => ['manage attendance'],
            'create' => ['create attendance'],
            'edit' => ['edit attendance'],
            'delete' => ['delete attendance'],
            'scoped' => true,
        ],
        'clients' => [
            'label' => 'العملاء',
            'view' => ['manage customer', 'show customer'],
            'create' => ['create customer'],
            'edit' => ['edit customer'],
            'delete' => ['delete customer'],
            'scoped' => true,
        ],
        'projects' => [
            'label' => 'المشاريع',
            'view' => ['manage project', 'view project'],
            'create' => ['create project'],
            'edit' => ['edit project'],
            'delete' => ['delete project'],
            'scoped' => true,
        ],
        'tasks' => [
            'label' => 'المهام',
            'view' => ['manage project task', 'view project task'],
            'create' => ['create project task'],
            'edit' => ['edit project task'],
            'delete' => ['delete project task'],
            'scoped' => true,
        ],
        'invoices' => [
            'label' => 'الفواتير والمدفوعات',
            'view' => ['manage invoice', 'show invoice'],
            'create' => ['create invoice', 'create payment invoice', 'send invoice'],
            'edit' => ['edit invoice'],
            'delete' => ['delete invoice', 'delete payment invoice'],
            'scoped' => true,
        ],
        'products' => [
            'label' => 'المنتجات والخدمات',
            'view' => ['manage product & service'],
            'create' => ['create product & service'],
            'edit' => ['edit product & service'],
            'delete' => ['delete product & service'],
            'scoped' => false,
        ],
        'roles' => [
            'label' => 'الأدوار والصلاحيات',
            'view' => ['manage role'],
            'create' => ['create role'],
            'edit' => ['edit role'],
            'delete' => ['delete role'],
            'scoped' => false,
        ],
        'audit' => [
            'label' => 'سجل الأنشطة',
            'view' => ['view audit log'],
            'scoped' => false,
        ],
        'reports' => [
            'label' => 'التقارير المالية',
            'view' => ['view finance summary', 'manage report'],
            'scoped' => false,
        ],
    ],

    /*
    | Starting roles. They can all be edited or removed from the roles screen.
    | 'all' = whole company, 'team' = projects the user belongs to, 'own' = only
    | records assigned to the user.
    */
    'default_roles' => [
        'مدير' => [
            'actions' => [
                'employees' => ['view', 'create', 'edit'],
                'attendance' => ['view', 'create', 'edit'],
                'clients' => ['view', 'create', 'edit'],
                'projects' => ['view', 'create', 'edit'],
                'tasks' => ['view', 'create', 'edit', 'delete'],
                'invoices' => ['view'],
                'products' => ['view'],
                'audit' => ['view'],
                'reports' => ['view'],
            ],
            'scopes' => ['attendance' => 'all', 'clients' => 'all', 'projects' => 'all', 'tasks' => 'all', 'invoices' => 'all'],
        ],
        'مدير مشروع' => [
            'actions' => [
                'clients' => ['view'],
                'projects' => ['view', 'edit'],
                'tasks' => ['view', 'create', 'edit', 'delete'],
            ],
            'scopes' => ['attendance' => 'team', 'clients' => 'team', 'projects' => 'team', 'tasks' => 'team', 'invoices' => 'team'],
        ],
        'محاسب' => [
            'actions' => [
                'clients' => ['view', 'edit'],
                'invoices' => ['view', 'create', 'edit'],
                'products' => ['view'],
                'reports' => ['view'],
            ],
            'scopes' => ['attendance' => 'own', 'clients' => 'all', 'projects' => 'own', 'tasks' => 'own', 'invoices' => 'all'],
        ],
    ],

    // Scopes applied to the roles that already exist before this release.
    'existing_role_scopes' => [
        'employee_like' => ['attendance' => 'own', 'clients' => 'own', 'projects' => 'own', 'tasks' => 'own', 'invoices' => 'own'],
        'other' => ['attendance' => 'all', 'clients' => 'all', 'projects' => 'all', 'tasks' => 'all', 'invoices' => 'all'],
    ],

    // Labels used by the audit log.
    'audit_entities' => [
        'project' => 'مشروع',
        'task' => 'مهمة',
        'invoice' => 'فاتورة',
        'payment' => 'دفعة',
        'attendance' => 'حضور',
        'user' => 'مستخدم',
        'employee' => 'موظف',
        'client' => 'عميل',
        'product' => 'منتج أو خدمة',
        'role' => 'دور',
        'settings' => 'إعدادات',
    ],
];
