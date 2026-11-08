<?php

namespace App\Providers;

use App\Models\AttendanceEmployee;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\ProductService;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectUser;
use App\Models\User;
use App\Observers\EdataAuditObserver;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class EdataServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // English for the rebuilt screens, kept apart from the system's large language file.
        // Their source text is Arabic, so the Arabic interface needs no translation file.
        $this->loadJsonTranslationsFrom(resource_path('lang/edata'));

        foreach ([
            Project::class, ProjectTask::class, ProjectUser::class, Invoice::class, InvoicePayment::class,
            AttendanceEmployee::class, User::class, Employee::class, Customer::class,
            ProductService::class, Role::class,
        ] as $model) {
            $model::observe(EdataAuditObserver::class);
        }
    }
}
