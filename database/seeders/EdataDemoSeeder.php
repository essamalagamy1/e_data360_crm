<?php

namespace Database\Seeders;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * TEST DATA ONLY. Fills a local database at the same volume as the live system so
 * screens and queries can be judged at real scale. Every name is visibly fake and
 * this class refuses to run outside a local or testing environment.
 */
class EdataDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new RuntimeException('EdataDemoSeeder runs only in local or testing environments.');
        }

        mt_srand(360);

        $company = User::where('type', 'company')->orderBy('id')->firstOrFail();
        $companyId = $company->id;
        $now = Carbon::now();

        $this->settings($companyId);

        DB::table('users')->where('id', $companyId)->update(['name' => 'E-Data 360', 'lang' => 'ar', 'plan' => 1]);
        DB::table('plans')->update(['max_users' => -1, 'max_customers' => -1, 'max_venders' => -1]);

        // Departments and job titles
        $branchId = DB::table('branches')->insertGetId(['name' => 'الرياض', 'created_by' => $companyId, 'created_at' => $now, 'updated_at' => $now]);
        $departments = [];
        foreach (['تحليل البيانات', 'التطوير', 'التسويق', 'الإدارة'] as $name) {
            $departments[$name] = DB::table('departments')->insertGetId(['branch_id' => $branchId, 'name' => $name, 'created_by' => $companyId, 'created_at' => $now, 'updated_at' => $now]);
        }
        $titles = [];
        foreach (['محلل بيانات' => 'تحليل البيانات', 'مطور Power BI' => 'تحليل البيانات', 'مطور برمجيات' => 'التطوير', 'مصمم' => 'التسويق', 'مدير عمليات' => 'الإدارة', 'محاسب' => 'الإدارة'] as $title => $department) {
            $titles[$title] = DB::table('designations')->insertGetId(['department_id' => $departments[$department], 'name' => $title, 'created_by' => $companyId, 'created_at' => $now, 'updated_at' => $now]);
        }

        // Staff: one account per kind of role, plus employees
        $employeeRole = $this->role('الموظف', $companyId, ['show hrm dashboard', 'manage project', 'view project', 'manage project task', 'view project task'], 'own');
        $people = [
            ['موظف تجريبي 1', 'employee1@test.local', 'الموظف', 'محلل بيانات', 'تحليل البيانات'],
            ['موظف تجريبي 2', 'employee2@test.local', 'الموظف', 'محلل بيانات', 'تحليل البيانات'],
            ['موظف تجريبي 3', 'employee3@test.local', 'الموظف', 'مطور Power BI', 'تحليل البيانات'],
            ['موظف تجريبي 4', 'employee4@test.local', 'الموظف', 'مصمم', 'التسويق'],
            ['مدير تجريبي', 'manager@test.local', 'مدير', 'مدير عمليات', 'الإدارة'],
            ['مدير مشروع تجريبي', 'pm@test.local', 'مدير مشروع', 'مطور برمجيات', 'التطوير'],
            ['محاسب تجريبي', 'accountant@test.local', 'محاسب', 'محاسب', 'الإدارة'],
        ];

        $password = Hash::make('Test@12345');
        $staff = [];
        $employees = [];
        foreach ($people as $index => [$name, $email, $roleName, $title, $department]) {
            $user = new User();
            $user->name = $name;
            $user->email = $email;
            $user->password = $password;
            $user->type = $roleName;
            $user->lang = 'ar';
            $user->created_by = $companyId;
            $user->email_verified_at = $now;
            $user->is_enable_login = 1;
            $user->save();
            $user->assignRole(Role::where('name', $roleName)->first());
            $staff[] = $user->id;

            $employees[$user->id] = DB::table('employees')->insertGetId([
                'user_id' => $user->id, 'name' => $name, 'email' => $email, 'phone' => '+96650000000' . $index,
                'employee_id' => (string) ($index + 1), 'branch_id' => $branchId, 'department_id' => $departments[$department],
                'designation_id' => $titles[$title], 'company_doj' => $now->copy()->subMonths(14)->toDateString(),
                'created_by' => $companyId, 'employment_status' => 'active', 'created_at' => $now->copy()->subMonths(14), 'updated_at' => $now,
            ]);
        }

        // Clients
        $industries = ['تعليم', 'صحة', 'تجزئة', 'طاقة', 'عقارات', 'مطاعم', 'حكومي', 'لوجستيات'];
        $cities = ['الرياض', 'جدة', 'الدمام', 'مكة', 'الخبر', 'أبها'];
        $sources = ['تيك توك', 'إحالة', 'الموقع', 'إنستجرام'];
        $customerIds = [];
        for ($i = 1; $i <= 159; $i++) {
            $created = $now->copy()->subDays(mt_rand(5, 340));
            $customerIds[] = DB::table('customers')->insertGetId([
                'customer_id' => $i, 'name' => 'عميل تجريبي ' . $i, 'contact_person' => 'مسؤول ' . $i,
                'email' => 'client' . $i . '@test.local', 'contact' => '+9665' . sprintf('%08d', mt_rand(0, 99999999)),
                'billing_country' => 'السعودية', 'billing_city' => $cities[array_rand($cities)],
                'industry' => $industries[array_rand($industries)], 'source' => $sources[array_rand($sources)],
                'crm_status' => $i % 19 === 0 ? 'paused' : 'active', 'created_by' => $companyId, 'lang' => 'ar', 'balance' => 0,
                'avatar' => '', 'created_at' => $created, 'updated_at' => $created,
            ]);
        }

        // Products: uses the categories, units and chart of accounts the base seed created
        $categoryId = DB::table('product_service_categories')->insertGetId(['name' => 'خدمات تحليل البيانات', 'type' => 'income', 'chart_account_id' => 0, 'color' => '#00C2C6', 'created_by' => $companyId, 'created_at' => $now, 'updated_at' => $now]);
        $productCategoryId = DB::table('product_service_categories')->insertGetId(['name' => 'الخدمات', 'type' => 'product & service', 'chart_account_id' => 0, 'color' => '#72B00B', 'created_by' => $companyId, 'created_at' => $now, 'updated_at' => $now]);
        $unitId = DB::table('product_service_units')->insertGetId(['name' => 'خدمة', 'created_by' => $companyId, 'created_at' => $now, 'updated_at' => $now]);
        $saleAccount = (int) DB::table('chart_of_accounts')->where('created_by', $companyId)->where('name', 'like', '%Sales%')->value('id')
            ?: (int) DB::table('chart_of_accounts')->where('created_by', $companyId)->value('id');
        $products = [];
        foreach ([['داشبورد Excel', 320], ['داشبورد Power BI', 350], ['باقة تحليل شهرية', 1500], ['تقرير PowerPoint', 450]] as $index => [$name, $price]) {
            $products[$name] = DB::table('product_services')->insertGetId([
                'name' => $name, 'sku' => 'SRV-' . ($index + 1), 'sale_price' => $price, 'purchase_price' => 0, 'quantity' => 0,
                'category_id' => $productCategoryId, 'unit_id' => $unitId, 'type' => 'service', 'sale_chartaccount_id' => $saleAccount,
                'expense_chartaccount_id' => 0, 'description' => 'خدمة تجريبية', 'created_by' => $companyId, 'is_active' => 1,
                'created_at' => $now->copy()->subYear(), 'updated_at' => $now,
            ]);
        }
        $productIds = array_values($products);
        $productPrices = [320, 350, 1500, 450];

        // Bank accounts tied to the chart of accounts, so payments can be recorded
        $chartAccount = (int) DB::table('chart_of_accounts')->where('created_by', $companyId)->where('name', 'like', '%Bank%')->value('id') ?: $saleAccount;
        $bankId = DB::table('bank_accounts')->insertGetId([
            'holder_name' => 'E-Data 360', 'bank_name' => 'بنك تجريبي', 'account_number' => '000000000', 'chart_account_id' => $chartAccount,
            'opening_balance' => 0, 'contact_number' => '0', 'bank_address' => 'الرياض', 'created_by' => $companyId, 'created_at' => $now, 'updated_at' => $now,
        ]);

        // Projects and tasks, at the live volume
        $stages = DB::table('task_stages')->where('created_by', $companyId)->orderBy('order')->pluck('id')->all();
        $doneStage = end($stages);
        $projectStatuses = array_merge(array_fill(0, 394, 'complete'), array_fill(0, 13, 'in_progress'), array_fill(0, 12, 'on_hold'), array_fill(0, 19, 'canceled'));
        shuffle($projectStatuses);
        $topics = ['لوحة مبيعات', 'تقرير موارد بشرية', 'تحليل مخزون', 'مؤشرات أداء', 'تحليل عملاء', 'لوحة مالية'];
        $priorities = ['critical', 'high', 'medium', 'low'];

        $taskRows = [];
        $projectIds = [];
        $taskTotal = 0;
        foreach ($projectStatuses as $index => $status) {
            $start = $now->copy()->subDays(mt_rand(1, 340));
            $end = $start->copy()->addDays(mt_rand(3, 25));
            if (in_array($status, ['in_progress', 'on_hold'], true)) {
                $start = $now->copy()->subDays(mt_rand(1, 20));
                $end = $now->copy()->addDays(mt_rand(-6, 30));
            }
            $managerId = $staff[array_rand($staff)];
            $projectId = DB::table('projects')->insertGetId([
                'project_name' => $topics[$index % 6] . ' تجريبي ' . ($index + 1),
                'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'budget' => [0, 350, 800, 1500, 4000][mt_rand(0, 4)],
                'client_id' => 0, 'customer_id' => mt_rand(0, 4) ? $customerIds[array_rand($customerIds)] : null, 'manager_id' => $managerId,
                'description' => 'مشروع تجريبي لاختبار النظام.', 'status' => $status, 'estimated_hrs' => '0', 'created_by' => $companyId,
                'copylinksetting' => '{}', 'created_at' => $start, 'updated_at' => $start,
            ]);
            $projectIds[] = $projectId;

            $team = array_unique([$companyId, $managerId, $staff[array_rand($staff)], $staff[array_rand($staff)]]);
            foreach ($team as $userId) {
                DB::table('project_users')->insert(['project_id' => $projectId, 'user_id' => $userId, 'invited_by' => $companyId, 'created_at' => $start, 'updated_at' => $start]);
            }

            $taskCount = $index < 216 ? 5 : 4;
            for ($t = 1; $t <= $taskCount && $taskTotal < 1968; $t++) {
                $finished = $status === 'complete' || (in_array($status, ['in_progress', 'on_hold'], true) && mt_rand(0, 2) === 0);
                $stage = $finished ? $doneStage : $stages[mt_rand(0, max(0, count($stages) - 2))];
                $stamp = $start->copy()->addDays(mt_rand(0, 3));
                $updated = $finished ? $end->copy()->subDays(mt_rand(0, 2)) : $stamp;
                $assignees = array_slice($team, 1, mt_rand(1, 2));
                $taskRows[] = [
                    'name' => 'مهمة ' . $t . ' في المشروع ' . ($index + 1), 'description' => 'وصف تجريبي', 'estimated_hrs' => mt_rand(1, 16),
                    'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'priority' => $priorities[mt_rand(0, 3)],
                    'priority_color' => 'primary', 'assign_to' => implode(',', $assignees), 'project_id' => $projectId, 'milestone_id' => 0,
                    'stage_id' => $stage, 'order' => $t, 'created_by' => $companyId, 'is_favourite' => 0, 'is_complete' => $finished ? 1 : 0,
                    'marked_at' => $finished ? $updated->toDateString() : null, 'progress' => $finished ? '100' : (string) (mt_rand(0, 8) * 10),
                    'created_at' => $stamp, 'updated_at' => $updated,
                ];
                $taskTotal++;
            }
            if (count($taskRows) >= 400) {
                DB::table('project_tasks')->insert($taskRows);
                $taskRows = [];
            }
        }
        if ($taskRows) {
            DB::table('project_tasks')->insert($taskRows);
        }

        // Invoices: most sent and unpaid, as seen on the live system
        for ($i = 1; $i <= 327; $i++) {
            $issue = $now->copy()->subDays(mt_rand(0, 330));
            $customerId = $customerIds[array_rand($customerIds)];
            $invoiceId = DB::table('invoices')->insertGetId([
                'invoice_id' => $i, 'customer_id' => $customerId, 'project_id' => mt_rand(0, 3) === 0 ? $projectIds[array_rand($projectIds)] : null,
                'issue_date' => $issue->toDateString(), 'due_date' => $issue->copy()->addDays(14)->toDateString(), 'send_date' => $issue->toDateString(),
                'category_id' => $categoryId, 'status' => 1, 'created_by' => $companyId, 'created_at' => $issue, 'updated_at' => $issue,
            ]);
            $pick = mt_rand(0, 3);
            $price = $productPrices[$pick];
            DB::table('invoice_products')->insert([
                'invoice_id' => $invoiceId, 'product_id' => $productIds[$pick], 'quantity' => 1, 'price' => $price, 'discount' => 0,
                'description' => 'خدمة تجريبية', 'created_at' => $issue, 'updated_at' => $issue,
            ]);

            // About one in five has money against it.
            $roll = mt_rand(1, 10);
            if ($roll <= 2) {
                $paid = $roll === 1 ? $price : round($price / 2, 2);
                DB::table('invoice_payments')->insert([
                    'invoice_id' => $invoiceId, 'date' => $issue->copy()->addDays(mt_rand(1, 10))->toDateString(), 'amount' => $paid,
                    'account_id' => $bankId, 'payment_method' => 0, 'reference' => 'TRX' . $i, 'created_at' => $issue, 'updated_at' => $issue,
                ]);
                DB::table('invoices')->where('id', $invoiceId)->update(['status' => $roll === 1 ? 4 : 3]);
            }
        }

        // Attendance: the last 45 days, including lateness, absence and forgotten clock-outs
        foreach ($employees as $userId => $employeeId) {
            for ($d = 45; $d >= 1; $d--) {
                $day = $now->copy()->subDays($d);
                if (in_array($day->dayOfWeek, [5, 6], true) || mt_rand(1, 100) <= 6) {
                    continue;
                }
                $inMinute = 9 * 60 + mt_rand(-15, 12) + (mt_rand(1, 100) <= 16 ? mt_rand(20, 70) : 0);
                $outMinute = 17 * 60 + mt_rand(-20, 25) + (mt_rand(1, 100) <= 12 ? mt_rand(40, 120) : 0);
                $forgot = mt_rand(1, 100) <= 3;
                $clockIn = sprintf('%02d:%02d:00', intdiv($inMinute, 60), $inMinute % 60);
                $clockOut = $forgot ? '00:00:00' : sprintf('%02d:%02d:00', intdiv($outMinute, 60), $outMinute % 60);
                $in = Carbon::parse($day->toDateString() . ' ' . $clockIn, 'Africa/Cairo');
                $out = $forgot ? null : Carbon::parse($day->toDateString() . ' ' . $clockOut, 'Africa/Cairo');

                DB::table('attendance_employees')->insert([
                    'employee_id' => $employeeId, 'date' => $day->toDateString(), 'status' => 'Present', 'clock_in' => $clockIn, 'clock_out' => $clockOut,
                    'clock_in_at' => $in->copy()->utc()->format('Y-m-d H:i:s'), 'clock_out_at' => $out?->copy()->utc()->format('Y-m-d H:i:s'),
                    'worked_seconds' => $out ? $in->diffInSeconds($out, true) : null, 'source' => 'legacy',
                    'late' => '00:00:00', 'early_leaving' => '00:00:00', 'overtime' => '00:00:00', 'total_rest' => '00:00:00',
                    'created_by' => $companyId, 'created_at' => $in, 'updated_at' => $in,
                ]);
            }
        }

        $this->command?->info('Test data ready. Log in with manager@test.local, pm@test.local, accountant@test.local or employee1@test.local.');
    }

    private function settings(int $companyId): void
    {
        $values = [
            'timezone' => 'Africa/Cairo', 'company_start_time' => '09:00', 'company_end_time' => '17:00',
            'site_currency' => 'SAR', 'site_currency_symbol' => 'SR', 'site_currency_symbol_position' => 'post', 'decimal_number' => '1',
            'invoice_prefix' => '#INVO', 'company_name' => 'E-Data 360', 'company_city' => 'الرياض', 'company_country' => 'السعودية',
            'company_email' => 'work@e-data360.com', 'company_telephone' => '+966553970641', 'default_language' => 'ar',
        ];
        foreach ($values as $name => $value) {
            DB::table('settings')->updateOrInsert(['name' => $name, 'created_by' => $companyId], ['value' => $value]);
        }
    }

    private function role(string $name, int $companyId, array $permissions, string $scope): Role
    {
        $role = Role::where('name', $name)->first();
        if (!$role) {
            $role = new Role();
            $role->name = $name;
            $role->guard_name = 'web';
            $role->created_by = $companyId;
            $role->save();
        }
        $role->syncPermissions($permissions);

        foreach (['attendance', 'clients', 'projects', 'tasks', 'invoices'] as $module) {
            DB::table('edata_role_scopes')->updateOrInsert(['role_id' => $role->id, 'module' => $module], ['scope' => $scope, 'updated_at' => now(), 'created_at' => now()]);
        }

        return $role;
    }
}
