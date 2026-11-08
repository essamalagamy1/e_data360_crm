<?php

namespace App\Http\Controllers\Edata;

use App\Exports\EdataTableExport;
use App\Models\Customer;
use App\Models\EdataAuditLog;
use App\Models\EdataNote;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Project;
use App\Models\User;
use App\Support\Edata\Access;
use App\Support\Edata\InvoiceMath;
use App\Support\Edata\Labels;
use App\Support\Edata\ProjectStats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class ClientController extends BaseController
{
    private function listQuery(Request $request)
    {
        $query = Access::customers($this->me());

        if ($request->query('archived') === '1') {
            $query->whereNotNull('customers.archived_at');
        } else {
            $query->whereNull('customers.archived_at');
        }
        if ($request->filled('q')) {
            $q = '%' . $request->query('q') . '%';
            $query->where(fn ($w) => $w->where('customers.name', 'like', $q)
                ->orWhere('customers.email', 'like', $q)
                ->orWhere('customers.contact', 'like', $q)
                ->orWhere('customers.contact_person', 'like', $q));
        }
        if ($request->filled('status')) {
            $query->where('customers.crm_status', $request->query('status'));
        }
        if ($request->filled('country')) {
            $query->where('customers.billing_country', $request->query('country'));
        }
        if ($request->filled('industry')) {
            $query->where('customers.industry', $request->query('industry'));
        }

        return $query;
    }

    public function index(Request $request)
    {
        $me = $this->me();
        Access::need($me, 'manage customer');
        $company = $this->companyId();

        $clients = $this->listQuery($request)->orderBy('customers.name')->paginate($this->perPage())->withQueryString();
        $ids = $clients->getCollection()->pluck('id');

        // Money for this page only: enough for 159 clients and for many more.
        $money = [];
        $invoices = Access::invoices($me)->whereIn('customer_id', $ids)->whereNull('cancelled_at')->where('status', '!=', 0)->get();
        InvoiceMath::attach($invoices, $company);
        foreach ($invoices as $invoice) {
            $money[$invoice->customer_id]['total'] = ($money[$invoice->customer_id]['total'] ?? 0) + $invoice->calc['total'];
            $money[$invoice->customer_id]['due'] = ($money[$invoice->customer_id]['due'] ?? 0) + max(0, $invoice->calc['due']);
        }

        $projectCounts = Access::projects($me)->whereIn('customer_id', $ids)
            ->selectRaw('customer_id, COUNT(*) as total')->groupBy('customer_id')->pluck('total', 'customer_id');

        $all = Access::customers($me);

        return view('edata.clients.index', [
            'clients' => $clients,
            'money' => $money,
            'projectCounts' => $projectCounts,
            'company' => $company,
            'stats' => [
                'total' => (clone $all)->whereNull('archived_at')->count(),
                'active' => (clone $all)->whereNull('archived_at')->where('crm_status', 'active')->count(),
                'archived' => (clone $all)->whereNotNull('archived_at')->count(),
            ],
            'countries' => (clone $all)->whereNotNull('billing_country')->where('billing_country', '!=', '')->distinct()->orderBy('billing_country')->pluck('billing_country'),
            'industries' => (clone $all)->whereNotNull('industry')->where('industry', '!=', '')->distinct()->orderBy('industry')->pluck('industry'),
            'canCreate' => $me->can('create customer'),
            'canEdit' => $me->can('edit customer'),
        ]);
    }

    public function export(Request $request)
    {
        Access::need($this->me(), 'manage customer');

        $rows = $this->listQuery($request)->orderBy('customers.name')->get()->map(fn ($c) => [
            $c->name, $c->contact_person, $c->email, $c->contact, $c->billing_country, $c->billing_city,
            $c->industry, $c->source, Labels::client($c->crm_status)[0], optional($c->created_at)->format('Y-m-d'),
        ]);

        return Excel::download(new EdataTableExport(
            ['العميل', 'الشخص المسؤول', 'البريد الإلكتروني', 'الهاتف', 'الدولة', 'المدينة', 'نوع النشاط', 'المصدر', 'الحالة', 'تاريخ التسجيل'],
            $rows
        ), 'clients_' . date('Y-m-d') . '.xlsx');
    }

    public function create()
    {
        Access::need($this->me(), 'create customer');

        return view('edata.clients.form', ['client' => new Customer(), 'statuses' => Labels::CLIENT_STATUSES]);
    }

    public function store(Request $request)
    {
        $me = $this->me();
        Access::need($me, 'create customer');
        $company = $this->companyId();
        $data = $this->validated($request);

        $owner = User::find($company);
        $plan = $owner ? Plan::find($owner->plan) : null;
        if ($plan && $plan->max_customers != -1 && $me->countCustomers() >= $plan->max_customers) {
            return $this->failed('وصلت إلى الحد الأقصى للعملاء في الباقة الحالية.');
        }

        $client = DB::transaction(function () use ($data, $company) {
            $client = new Customer();
            $client->customer_id = ((int) Customer::where('created_by', $company)->lockForUpdate()->max('customer_id')) + 1;
            $client->created_by = $company;
            $client->lang = User::find($company)->lang ?? 'ar';
            $client->balance = 0;
            $this->fill($client, $data);
            $client->save();

            return $client;
        });

        return $this->done('تمت إضافة العميل ' . $client->name . '.', route('edata.clients.show', $client->id));
    }

    public function show(int $customer)
    {
        $me = $this->me();
        Access::need($me, ['manage customer', 'show customer']);
        $company = $this->companyId();
        $client = Access::customers($me)->findOrFail($customer);

        $invoiceQuery = Access::invoices($me)->where('customer_id', $client->id);
        $money = InvoiceMath::summary(clone $invoiceQuery, $company);

        $invoices = (clone $invoiceQuery)->orderByDesc('issue_date')->limit(50)->get();
        InvoiceMath::attach($invoices, $company);

        $projects = Access::projects($me)->where('customer_id', $client->id)->with('manager:id,name')->orderByDesc('id')->get();
        $stats = ProjectStats::forIds($projects->pluck('id'), $company);

        $tasks = Access::tasks($me)
            ->whereIn('project_id', $projects->pluck('id'))
            ->with('project:id,project_name')
            ->whereNull('cancelled_at')
            ->orderByRaw('is_complete ASC, end_date IS NULL, end_date ASC')
            ->limit(25)
            ->get();

        $notes = EdataNote::where('company_id', $company)
            ->where('notable_type', 'client')->where('notable_id', $client->id)
            ->with('author:id,name')->orderByDesc('id')->get();

        $activity = EdataAuditLog::where('company_id', $company)
            ->where(function ($q) use ($client, $invoices, $projects) {
                $q->where(fn ($w) => $w->where('entity_type', 'client')->where('entity_id', $client->id))
                    ->orWhere(fn ($w) => $w->where('entity_type', 'invoice')->whereIn('entity_id', $invoices->pluck('id')))
                    ->orWhere(fn ($w) => $w->where('entity_type', 'project')->whereIn('entity_id', $projects->pluck('id')));
            })
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        return view('edata.clients.show', [
            'client' => $client,
            'money' => $money,
            'invoices' => $invoices,
            'projects' => $projects,
            'projectStats' => $stats,
            'tasks' => $tasks,
            'notes' => $notes,
            'activity' => $activity,
            'company' => $company,
            'canEdit' => $me->can('edit customer'),
            'canNote' => $me->can('edit customer') || $me->can('manage customer'),
            'canInvoice' => $me->can('create invoice'),
            'canProject' => $me->can('create project'),
            'tab' => request('tab', 'overview'),
        ]);
    }

    public function edit(int $customer)
    {
        Access::need($this->me(), 'edit customer');

        return view('edata.clients.form', [
            'client' => Access::customers($this->me())->findOrFail($customer),
            'statuses' => Labels::CLIENT_STATUSES,
        ]);
    }

    public function update(Request $request, int $customer)
    {
        Access::need($this->me(), 'edit customer');
        $client = Access::customers($this->me())->findOrFail($customer);

        $this->fill($client, $this->validated($request, $client));
        $client->save();

        return $this->done('تم حفظ بيانات العميل.', route('edata.clients.show', $client->id));
    }

    public function archive(int $customer)
    {
        Access::need($this->me(), 'edit customer');
        $client = Access::customers($this->me())->findOrFail($customer);

        $client->archived_at = $client->archived_at ? null : now();
        $client->save();

        return $this->done($client->archived_at ? 'تمت أرشفة العميل. فواتيره ومشاريعه باقية كما هي.' : 'تمت استعادة العميل.');
    }

    /** Old delete route: a client with invoices or projects cannot be removed. */
    public function destroyLegacy($customer)
    {
        Access::need($this->me(), 'delete customer');
        $client = Access::customers($this->me())->findOrFail((int) $customer);
        $company = $this->companyId();

        $hasHistory = Invoice::where('created_by', $company)->where('customer_id', $client->id)->exists()
            || Project::where('created_by', $company)->where('customer_id', $client->id)->exists();

        if ($hasHistory) {
            return $this->failed('لهذا العميل فواتير أو مشاريع. أرشفه بدلاً من حذفه.');
        }

        $client->delete();

        return $this->done('تم حذف العميل.', route('edata.clients.index'));
    }

    private function validated(Request $request, ?Customer $client = null): array
    {
        $company = $this->companyId();

        return $request->validate([
            'name' => 'required|string|max:191',
            'contact_person' => 'nullable|string|max:191',
            'email' => ['nullable', 'email', 'max:191', Rule::unique('customers', 'email')->where('created_by', $company)->ignore($client?->id)],
            'contact' => ['nullable', 'string', 'max:30', 'regex:/^[0-9\s\-\+\(\)]*$/'],
            'billing_country' => 'nullable|string|max:120',
            'billing_city' => 'nullable|string|max:120',
            'billing_address' => 'nullable|string|max:500',
            'tax_number' => 'nullable|string|max:60',
            'industry' => 'nullable|string|max:120',
            'source' => 'nullable|string|max:120',
            'crm_status' => ['required', Rule::in(array_keys(Labels::CLIENT_STATUSES))],
            'notes' => 'nullable|string|max:3000',
        ], [], [
            'name' => 'اسم العميل أو الشركة',
            'contact_person' => 'الشخص المسؤول',
            'email' => 'البريد الإلكتروني',
            'contact' => 'رقم الهاتف',
            'billing_country' => 'الدولة',
            'billing_city' => 'المدينة',
            'billing_address' => 'العنوان',
            'tax_number' => 'الرقم الضريبي',
            'industry' => 'نوع النشاط',
            'source' => 'مصدر العميل',
            'crm_status' => 'حالة العميل',
            'notes' => 'ملاحظات',
        ]);
    }

    private function fill(Customer $client, array $data): void
    {
        foreach ($data as $field => $value) {
            $client->{$field} = $value;
        }
        $client->billing_name = $client->billing_name ?: $data['name'];
        $client->billing_phone = $client->billing_phone ?: ($data['contact'] ?? null);
    }
}
