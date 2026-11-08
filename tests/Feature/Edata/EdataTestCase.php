<?php

namespace Tests\Feature\Edata;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceProduct;
use App\Models\ProductService;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Support\Edata\Access;
use App\Support\Edata\Fmt;
use App\Support\Edata\InvoiceMath;
use App\Support\Edata\TaskRules;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Runs against the edata_test database. Every test is rolled back afterwards.
 */
abstract class EdataTestCase extends TestCase
{
    use DatabaseTransactions;

    protected User $owner;
    protected int $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Access::flushCache();
        Fmt::flush();
        InvoiceMath::flush();
        TaskRules::flush();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->owner = User::where('type', 'company')->orderBy('id')->firstOrFail();
        $this->company = $this->owner->id;
        DB::table('plans')->update(['max_users' => -1, 'max_customers' => -1]);

        foreach ([
            'timezone' => 'Africa/Cairo', 'company_start_time' => '09:00', 'company_end_time' => '17:00',
            'edata_work_days' => '0,1,2,3,4', 'edata_grace_minutes' => '15', 'ip_restrict' => 'off', 'invoice_prefix' => '#INVO',
        ] as $name => $value) {
            DB::table('settings')->updateOrInsert(['name' => $name, 'created_by' => $this->company], ['value' => $value]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function staff(array $permissions = [], string $scope = 'own', ?string $name = null): User
    {
        $role = new Role();
        $role->name = 'role-' . Str::random(8);
        $role->guard_name = 'web';
        $role->created_by = $this->company;
        $role->save();
        $role->syncPermissions($permissions);

        foreach (['attendance', 'clients', 'projects', 'tasks', 'invoices'] as $module) {
            DB::table('edata_role_scopes')->insert(['role_id' => $role->id, 'module' => $module, 'scope' => $scope, 'created_at' => now(), 'updated_at' => now()]);
        }

        $user = new User();
        $user->name = $name ?? 'User ' . Str::random(5);
        $user->email = Str::random(10) . '@test.local';
        $user->password = Hash::make('secret-pass');
        $user->type = $role->name;
        $user->lang = 'ar';
        $user->created_by = $this->company;
        $user->email_verified_at = now();
        $user->is_enable_login = 1;
        $user->save();
        $user->assignRole($role);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Access::flushCache();

        return $user->fresh();
    }

    protected function employeeFor(User $user, ?string $joined = '2020-01-01'): Employee
    {
        return Employee::create([
            'user_id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'employee_id' => (string) random_int(1000, 99999), 'company_doj' => $joined, 'created_by' => $this->company,
        ]);
    }

    protected function project(array $attributes = [], array $team = []): Project
    {
        $project = new Project();
        $project->forceFill(array_merge([
            'project_name' => 'Project ' . Str::random(5), 'client_id' => 0, 'status' => 'in_progress', 'created_by' => $this->company,
        ], $attributes));
        $project->save();

        foreach ($team as $user) {
            ProjectUser::create(['project_id' => $project->id, 'user_id' => $user->id, 'invited_by' => $this->company]);
        }

        return $project;
    }

    protected function customer(array $attributes = []): Customer
    {
        $customer = new Customer();
        $customer->forceFill(array_merge([
            'customer_id' => random_int(1000, 999999), 'name' => 'Client ' . Str::random(5), 'email' => Str::random(8) . '@test.local',
            'created_by' => $this->company, 'lang' => 'ar', 'avatar' => '',
        ], $attributes));
        $customer->save();

        return $customer;
    }

    protected function product(float $price = 1000): ProductService
    {
        $product = new ProductService();
        $product->forceFill([
            'name' => 'Service ' . Str::random(5), 'sku' => Str::random(8), 'sale_price' => $price, 'purchase_price' => 0, 'quantity' => 0,
            'category_id' => 1, 'unit_id' => 1, 'type' => 'service', 'sale_chartaccount_id' => 0, 'expense_chartaccount_id' => 0, 'created_by' => $this->company,
        ]);
        $product->save();

        return $product;
    }

    protected function invoice(float $price = 1000, int $status = 1, array $attributes = []): Invoice
    {
        $invoice = new Invoice();
        $invoice->forceFill(array_merge([
            'invoice_id' => random_int(100000, 999999), 'customer_id' => $this->customer()->id,
            'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(10)->toDateString(),
            'category_id' => 1, 'status' => $status, 'created_by' => $this->company,
        ], $attributes));
        $invoice->save();

        $line = new InvoiceProduct();
        $line->forceFill(['invoice_id' => $invoice->id, 'product_id' => $this->product($price)->id, 'quantity' => 1, 'price' => $price, 'discount' => 0, 'tax' => null, 'description' => 'x']);
        $line->save();

        return $invoice;
    }

    protected function bank(): int
    {
        return DB::table('bank_accounts')->insertGetId([
            'holder_name' => 'Test', 'bank_name' => 'Test Bank', 'account_number' => '1', 'chart_account_id' => 1,
            'opening_balance' => 0, 'contact_number' => '0', 'bank_address' => 'x', 'created_by' => $this->company, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
