<?php

namespace App\Http\Controllers\Edata;

use App\Exports\EdataTableExport;
use App\Models\AddTransactionLine;
use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\CustomerCreditNotes;
use App\Models\EdataAuditLog;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InvoiceProduct;
use App\Models\ProductService;
use App\Models\ProductServiceCategory;
use App\Models\StockReport;
use App\Models\Tax;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Utility;
use App\Support\Edata\Access;
use App\Support\Edata\Audit;
use App\Support\Edata\Fmt;
use App\Support\Edata\InvoiceLinks;
use App\Support\Edata\InvoiceMath;
use App\Support\Edata\Labels;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Invoices keep the accounting side effects of the original system (stock, customer
 * balance, bank balance, ledger lines), so existing reports stay correct.
 */
class InvoiceController extends BaseController
{
    private function filtered(Request $request)
    {
        $query = Access::invoices($this->me());

        if ($request->filled('q')) {
            $q = trim((string) $request->query('q'));
            $digits = preg_replace('/\D/', '', $q);
            $query->where(function ($w) use ($q, $digits) {
                $w->whereIn('customer_id', Access::customers($this->me())->where('name', 'like', '%' . $q . '%')->select('customers.id'))
                    ->orWhere('ref_number', 'like', '%' . $q . '%');
                if ($digits !== '') {
                    $w->orWhere('invoice_id', (int) $digits);
                }
            });
        }
        if ($request->filled('customer')) {
            $query->where('customer_id', (int) $request->query('customer'));
        }
        if ($request->filled('project')) {
            $query->where('project_id', (int) $request->query('project'));
        }
        if ($request->filled('state')) {
            InvoiceMath::applyStateFilter($query, (string) $request->query('state'));
        }
        if ($request->filled('from')) {
            $query->whereDate('issue_date', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('issue_date', '<=', $request->query('to'));
        }

        return $query;
    }

    public function index(Request $request)
    {
        $me = $this->me();
        Access::need($me, 'manage invoice');
        $company = $this->companyId();

        $invoices = $this->filtered($request)
            ->with(['customer:id,name', 'project:id,project_name'])
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate($this->perPage())
            ->withQueryString();

        InvoiceMath::attach($invoices->getCollection(), $company);

        $summary = InvoiceMath::summary(Access::invoices($me), $company);
        $zone = Fmt::timezone($company);
        $now = Carbon::now($zone);

        return view('edata.invoices.index', [
            'invoices' => $invoices,
            'summary' => $summary,
            'monthRevenue' => InvoiceMath::collectedBetween($company, $now->copy()->startOfMonth(), $now),
            'yearRevenue' => InvoiceMath::collectedBetween($company, $now->copy()->startOfYear(), $now),
            'customers' => Access::customers($me)->orderBy('name')->get(['id', 'name']),
            'projects' => Access::projects($me)->orderByDesc('id')->limit(300)->get(['id', 'project_name']),
            'company' => $company,
            'canCreate' => $me->can('create invoice'),
        ]);
    }

    public function export(Request $request)
    {
        Access::need($this->me(), 'manage invoice');
        $company = $this->companyId();

        $invoices = $this->filtered($request)->with(['customer:id,name', 'project:id,project_name'])->orderByDesc('issue_date')->get();
        InvoiceMath::attach($invoices, $company);

        $rows = $invoices->map(fn ($i) => [
            Fmt::invoiceNumber($i->invoice_id, $company),
            $i->customer->name ?? '',
            $i->project->project_name ?? '',
            Carbon::parse($i->issue_date)->toDateString(),
            Carbon::parse($i->due_date)->toDateString(),
            $i->calc['total'],
            $i->calc['paid'],
            max(0, $i->calc['due']),
            Labels::invoice($i->calc['state'])[0],
        ]);

        return Excel::download(new EdataTableExport(
            ['رقم الفاتورة', 'العميل', 'المشروع', 'تاريخ الإصدار', 'تاريخ الاستحقاق', 'الإجمالي', 'المدفوع', 'المتبقي', 'الحالة'],
            $rows
        ), 'invoices_' . date('Y-m-d') . '.xlsx');
    }

    public function create(Request $request)
    {
        Access::need($this->me(), 'create invoice');

        $invoice = new Invoice([
            'issue_date' => Carbon::today()->toDateString(),
            'due_date' => Carbon::today()->addDays(14)->toDateString(),
            'customer_id' => (int) $request->query('customer', 0),
        ]);
        $invoice->project_id = (int) $request->query('project', 0) ?: null;

        return view('edata.invoices.form', $this->formData($invoice));
    }

    public function store(Request $request)
    {
        $me = $this->me();
        Access::need($me, 'create invoice');
        $company = $this->companyId();
        $data = $this->validated($request);

        $invoice = DB::transaction(function () use ($data, $company, $me) {
            $last = (int) Invoice::where('created_by', $company)->lockForUpdate()->max('invoice_id');

            $invoice = new Invoice();
            $invoice->invoice_id = $last + 1;
            $invoice->status = 0;
            $invoice->created_by = $company;
            $invoice->customer_id = $data['customer_id'];
            $invoice->project_id = $data['project_id'] ?? null;
            $invoice->issue_date = $data['issue_date'];
            $invoice->due_date = $data['due_date'];
            $invoice->category_id = $data['category_id'];
            $invoice->ref_number = $data['ref_number'] ?? null;
            $invoice->notes = $data['notes'] ?? null;
            $invoice->save();

            $this->writeItems($invoice, $data['items'], $me);

            return $invoice;
        });

        return $this->done('تم إنشاء الفاتورة ' . Fmt::invoiceNumber($invoice->invoice_id, $company) . ' كمسودة.', route('edata.invoices.show', $invoice->id));
    }

    public function show(int $invoice)
    {
        $me = $this->me();
        Access::need($me, ['manage invoice', 'show invoice']);
        $company = $this->companyId();
        $record = Access::invoices($me)->with(['customer', 'project', 'items.product', 'payments'])->findOrFail($invoice);
        InvoiceMath::attach(collect([$record]), $company);

        $history = EdataAuditLog::where('company_id', $company)
            ->where(function ($q) use ($record) {
                $q->where(fn ($w) => $w->where('entity_type', 'invoice')->where('entity_id', $record->id))
                    ->orWhere(fn ($w) => $w->where('entity_type', 'payment')->whereIn('entity_id', $record->payments->pluck('id')));
            })
            ->orderByDesc('id')->limit(20)->get();

        return view('edata.invoices.show', [
            'invoice' => $record,
            'company' => $company,
            'history' => $history,
            'accounts' => BankAccount::where('created_by', $company)->get(['id', 'bank_name', 'holder_name', 'chart_account_id']),
            'accountNames' => BankAccount::where('created_by', $company)->get()->mapWithKeys(fn ($a) => [$a->id => trim($a->bank_name . ' ' . $a->holder_name)]),
            'taxes' => Tax::where('created_by', $company)->pluck('name', 'id'),
            'settings' => Fmt::settings($company),
            'canEdit' => $me->can('edit invoice'),
            'canPay' => $me->can('create payment invoice'),
            'canRemovePayment' => $me->can('delete payment invoice'),
            'canDelete' => $me->can('delete invoice'),
            'canSend' => $me->can('send invoice'),
            'link' => InvoiceLinks::url($record),
        ]);
    }

    public function printView(int $invoice)
    {
        $me = $this->me();
        Access::need($me, ['manage invoice', 'show invoice']);
        $company = $this->companyId();
        $record = Access::invoices($me)->with(['customer', 'project', 'items.product', 'payments'])->findOrFail($invoice);
        InvoiceMath::attach(collect([$record]), $company);

        return view('edata.invoices.print', [
            'invoice' => $record,
            'company' => $company,
            'settings' => Fmt::settings($company),
            'taxes' => Tax::where('created_by', $company)->get()->keyBy('id'),
            'accountNames' => BankAccount::where('created_by', $company)->get()->mapWithKeys(fn ($a) => [$a->id => trim($a->bank_name . ' ' . $a->holder_name)]),
            'link' => InvoiceLinks::url($record),
        ]);
    }

    /** For a link that reached the wrong person: the old one stops working at once. */
    public function relink(int $invoice)
    {
        $me = $this->me();
        Access::need($me, 'edit invoice');
        $record = Access::invoices($me)->findOrFail($invoice);

        if (!InvoiceLinks::isShareable($record)) {
            return $this->failed('اعتمد الفاتورة أولاً حتى يكون لها رابط.');
        }

        InvoiceLinks::rotate($record);
        Audit::record('updated', 'invoice', $record->id, Fmt::invoiceNumber($record->invoice_id, $this->companyId()), null, null, 'تغيير رابط الفاتورة');

        return $this->done('تم تغيير الرابط. الرابط السابق توقف عن العمل.');
    }

    public function edit(int $invoice)
    {
        Access::need($this->me(), 'edit invoice');
        $record = Access::invoices($this->me())->with('items', 'payments')->findOrFail($invoice);

        if ($record->cancelled_at) {
            return $this->failed('لا يمكن تعديل فاتورة ملغاة.');
        }

        return view('edata.invoices.form', $this->formData($record));
    }

    public function update(Request $request, int $invoice)
    {
        $me = $this->me();
        Access::need($me, 'edit invoice');
        $company = $this->companyId();
        $record = Access::invoices($me)->with('items', 'payments')->findOrFail($invoice);

        if ($record->cancelled_at) {
            return $this->failed('لا يمكن تعديل فاتورة ملغاة.');
        }

        $locked = $record->payments->isNotEmpty();

        if ($locked) {
            // Money was received against these lines: only the descriptive fields can change.
            $data = $request->validate([
                'project_id' => ['nullable', 'integer', Rule::in($this->projectIds())],
                'due_date' => 'required|date|after_or_equal:' . Carbon::parse($record->issue_date)->toDateString(),
                'ref_number' => 'nullable|string|max:120',
                'notes' => 'nullable|string|max:3000',
            ], [], $this->names());

            $record->project_id = $data['project_id'] ?? null;
            $record->due_date = $data['due_date'];
            $record->ref_number = $data['ref_number'] ?? null;
            $record->notes = $data['notes'] ?? null;
            $record->save();

            return $this->done('تم حفظ التعديلات. بنود الفاتورة مقفلة لأنها عليها دفعات.', route('edata.invoices.show', $record->id));
        }

        $data = $this->validated($request);

        DB::transaction(function () use ($record, $data, $me, $company) {
            $wasSent = (int) $record->status !== 0;

            // Undo the old lines first: stock, stock report and ledger.
            foreach ($record->items as $item) {
                if (ProductService::find($item->product_id)) {
                    Utility::total_quantity('plus', $item->quantity, $item->product_id);
                }
            }
            InvoiceProduct::where('invoice_id', $record->id)->delete();
            StockReport::where('type', 'invoice')->where('type_id', $record->id)->delete();
            AddTransactionLine::where('reference_id', $record->id)->where('reference', 'Invoice')->delete();

            $record->customer_id = $data['customer_id'];
            $record->project_id = $data['project_id'] ?? null;
            $record->issue_date = $data['issue_date'];
            $record->due_date = $data['due_date'];
            $record->category_id = $data['category_id'];
            $record->ref_number = $data['ref_number'] ?? null;
            $record->notes = $data['notes'] ?? null;
            $record->save();

            $this->writeItems($record, $data['items'], $me);

            if ($wasSent) {
                $this->writeLedger($record, $company);
            }
        });

        return $this->done('تم حفظ الفاتورة.', route('edata.invoices.show', $record->id));
    }

    public function markSent(int $invoice)
    {
        $me = $this->me();
        Access::need($me, 'send invoice');
        $company = $this->companyId();
        $record = Access::invoices($me)->findOrFail($invoice);

        if ($record->cancelled_at) {
            return $this->failed('هذه الفاتورة ملغاة.');
        }
        if ((int) $record->status !== 0) {
            return $this->failed('هذه الفاتورة مرسلة بالفعل.');
        }

        DB::transaction(function () use ($record, $company) {
            $record->status = 1;
            $record->send_date = Carbon::today()->toDateString();
            $record->save();
            $this->writeLedger($record, $company);
        });

        return $this->done('تم اعتماد الفاتورة كمرسلة.');
    }

    public function cancel(int $invoice)
    {
        $me = $this->me();
        Access::need($me, 'edit invoice');
        $record = Access::invoices($me)->with('items', 'payments')->findOrFail($invoice);

        if ($record->cancelled_at) {
            return $this->failed('هذه الفاتورة ملغاة بالفعل.');
        }
        if ($record->payments->isNotEmpty()) {
            return $this->failed('عليها دفعات مسجلة. احذف الدفعات أولاً ثم ألغِ الفاتورة.');
        }

        DB::transaction(function () use ($record) {
            foreach ($record->items as $item) {
                if (ProductService::find($item->product_id)) {
                    Utility::total_quantity('plus', $item->quantity, $item->product_id);
                }
            }
            StockReport::where('type', 'invoice')->where('type_id', $record->id)->delete();
            AddTransactionLine::where('reference_id', $record->id)->where('reference', 'Invoice')->delete();

            $record->cancelled_at = now();
            $record->save();
        });

        return $this->done('تم إلغاء الفاتورة. تبقى محفوظة في السجل ولا تدخل في الإجماليات.');
    }

    public function addPayment(Request $request, int $invoice)
    {
        $me = $this->me();
        Access::need($me, 'create payment invoice');
        $company = $this->companyId();
        $record = Access::invoices($me)->findOrFail($invoice);

        if ($record->cancelled_at) {
            return $this->failed('لا يمكن تسجيل دفعة على فاتورة ملغاة.');
        }

        $data = $request->validate([
            'date' => 'required|date',
            'amount' => 'required|numeric|gt:0',
            'account_id' => ['required', 'integer', Rule::exists('bank_accounts', 'id')->where('created_by', $company)],
            'reference' => 'nullable|string|max:120',
            'description' => 'nullable|string|max:1000',
            'add_receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ], [], [
            'date' => 'تاريخ الدفع',
            'amount' => 'المبلغ',
            'account_id' => 'الحساب',
            'reference' => 'المرجع',
            'description' => 'الوصف',
            'add_receipt' => 'الإيصال',
        ]);

        $bank = BankAccount::where('created_by', $company)->findOrFail($data['account_id']);
        if ((int) $bank->chart_account_id === 0) {
            return $this->failed('هذا الحساب البنكي غير مربوط بدليل الحسابات. اربطه أولاً من صفحة الحسابات البنكية.');
        }

        try {
            DB::transaction(function () use ($record, $data, $bank, $company, $request, $me) {
                // Locking the invoice means two people recording at once cannot overpay it.
                $locked = Invoice::whereKey($record->id)->lockForUpdate()->first();
                InvoiceMath::attach(collect([$locked]), $company);
                $due = $locked->calc['due'];
                unset($locked->calc);

                if ((float) $data['amount'] > $due) {
                    throw new \DomainException('المبلغ أكبر من المتبقي على الفاتورة (' . Fmt::money($due, $company) . ').');
                }

                $payment = new InvoicePayment();
                $payment->invoice_id = $locked->id;
                $payment->date = $data['date'];
                $payment->amount = $data['amount'];
                $payment->account_id = $data['account_id'];
                $payment->payment_method = 0;
                $payment->reference = $data['reference'] ?? null;
                $payment->description = $data['description'] ?? null;

                if ($request->hasFile('add_receipt')) {
                    $size = $request->file('add_receipt')->getSize();
                    if (Utility::updateStorageLimit($company, $size) == 1) {
                        $name = time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $request->file('add_receipt')->getClientOriginalName());
                        $request->file('add_receipt')->storeAs('uploads/payment', $name);
                        $payment->add_receipt = $name;
                    }
                }
                $payment->save();

                $remaining = round($due - (float) $data['amount'], 2);
                if ((int) $locked->status === 0) {
                    $locked->send_date = Carbon::today()->toDateString();
                    $this->writeLedger($locked, $company);
                }
                $locked->status = $remaining <= 0 ? 4 : 3;
                $locked->save();

                // Same ledger entries the original screen wrote.
                $transaction = clone $payment;
                $transaction->user_id = $locked->customer_id;
                $transaction->user_type = 'Customer';
                $transaction->type = 'Partial';
                $transaction->created_by = $me->id;
                $transaction->payment_id = $payment->id;
                $transaction->category = 'Invoice';
                $transaction->account = $data['account_id'];
                Transaction::addTransaction($transaction);

                Utility::updateUserBalance('customer', $locked->customer_id, $data['amount'], 'debit');
                Utility::bankAccountBalance($data['account_id'], $data['amount'], 'credit');

                Utility::addTransactionLines([
                    'account_id' => $bank->chart_account_id,
                    'transaction_type' => 'debit',
                    'transaction_amount' => $data['amount'],
                    'reference' => 'Invoice Payment',
                    'reference_id' => $locked->id,
                    'reference_sub_id' => $payment->id,
                    'date' => $payment->date,
                ]);

                $receivable = ChartOfAccount::where('name', 'Accounts Receivable')->where('created_by', $company)->first();
                Utility::addTransactionLines([
                    'account_id' => $receivable->id ?? 0,
                    'transaction_type' => 'credit',
                    'transaction_amount' => $data['amount'],
                    'reference' => 'Invoice Payment',
                    'reference_id' => $locked->id,
                    'reference_sub_id' => $payment->id,
                    'date' => $payment->date,
                ]);
            });
        } catch (\DomainException $e) {
            return $this->failed($e->getMessage());
        }

        return $this->done('تم تسجيل الدفعة بقيمة ' . Fmt::money($data['amount'], $company) . '.');
    }

    public function removePayment(int $invoice, int $payment)
    {
        $me = $this->me();
        Access::need($me, 'delete payment invoice');
        $company = $this->companyId();
        $record = Access::invoices($me)->findOrFail($invoice);
        $row = InvoicePayment::where('invoice_id', $record->id)->findOrFail($payment);

        DB::transaction(function () use ($record, $row, $company) {
            AddTransactionLine::where('reference_id', $record->id)
                ->where('reference_sub_id', $row->id)
                ->where('reference', 'Invoice Payment')
                ->delete();
            Transaction::destroyTransaction($row->id, 'Partial', 'Customer');
            Utility::updateUserBalance('customer', $record->customer_id, $row->amount, 'credit');
            Utility::bankAccountBalance($row->account_id, $row->amount, 'debit');

            $row->delete();

            $fresh = Invoice::with(['items', 'payments'])->find($record->id);
            InvoiceMath::attach(collect([$fresh]), $company);
            $paid = $fresh->calc['paid'];
            $due = $fresh->calc['due'];
            unset($fresh->calc);
            $fresh->status = $due <= 0 ? 4 : ($paid > 0 ? 3 : 1);
            $fresh->save();
        });

        return $this->done('تم حذف الدفعة وإعادة حساب المتبقي.');
    }

    public function destroy(int $invoice)
    {
        $me = $this->me();
        Access::need($me, 'delete invoice');
        $record = Access::invoices($me)->with('items', 'payments')->findOrFail($invoice);

        if ((int) $record->status !== 0 || $record->payments->isNotEmpty()) {
            return $this->failed('يمكن حذف المسودات فقط. ألغِ الفاتورة بدلاً من حذفها حتى تبقى في السجل.');
        }
        if (CustomerCreditNotes::where('invoice', $record->id)->exists()) {
            return $this->failed('هذه الفاتورة عليها إشعار دائن، لذلك لا يمكن حذفها.');
        }

        DB::transaction(function () use ($record) {
            foreach ($record->items as $item) {
                if (ProductService::find($item->product_id)) {
                    Utility::total_quantity('plus', $item->quantity, $item->product_id);
                }
            }
            StockReport::where('type', 'invoice')->where('type_id', $record->id)->delete();
            InvoiceProduct::where('invoice_id', $record->id)->delete();
            $record->delete();
        });

        return $this->done('تم حذف المسودة.', route('edata.invoices.index'));
    }

    private function writeItems(Invoice $invoice, array $items, User $me): void
    {
        foreach ($items as $line) {
            $item = new InvoiceProduct();
            $item->invoice_id = $invoice->id;
            $item->product_id = (int) $line['product_id'];
            $item->quantity = (int) $line['quantity'];
            $item->price = $line['price'];
            $item->discount = $line['discount'] ?? 0;
            $item->tax = !empty($line['tax']) ? implode(',', array_map('intval', (array) $line['tax'])) : null;
            $item->description = $line['description'] ?? null;
            $item->save();

            Utility::total_quantity('minus', $item->quantity, $item->product_id);
            $text = $item->quantity . ' ' . __(' quantity sold in invoice') . ' ' . Fmt::invoiceNumber($invoice->invoice_id, (int) $invoice->created_by);
            Utility::addProductStock($item->product_id, $item->quantity, 'invoice', $text, $invoice->id);
        }
    }

    /** The receivable and sales entries the original "send" action wrote. */
    private function writeLedger(Invoice $invoice, int $company): void
    {
        $rates = InvoiceMath::rates($company);
        $receivable = ChartOfAccount::where('name', 'Accounts Receivable')->where('created_by', $company)->first();

        foreach (InvoiceProduct::where('invoice_id', $invoice->id)->get() as $item) {
            $product = ProductService::find($item->product_id);
            if (!$product) {
                continue;
            }

            $gross = (float) $item->price * (float) $item->quantity;
            $net = $gross - (float) $item->discount;
            $tax = 0.0;
            foreach (array_filter(explode(',', (string) $item->tax)) as $taxId) {
                $tax += (($rates[(int) $taxId] ?? 0) / 100) * $net;
            }
            $amount = $net + $tax;

            Utility::addTransactionLines([
                'account_id' => $product->sale_chartaccount_id,
                'transaction_type' => 'credit',
                'transaction_amount' => $amount,
                'reference' => 'Invoice',
                'reference_id' => $invoice->id,
                'reference_sub_id' => $product->id,
                'date' => $invoice->issue_date,
            ]);
            Utility::addTransactionLines([
                'account_id' => $receivable->id ?? 0,
                'transaction_type' => 'debit',
                'transaction_amount' => $amount,
                'reference' => 'Invoice',
                'reference_id' => $invoice->id,
                'reference_sub_id' => $product->id,
                'date' => $invoice->issue_date,
            ]);
        }
    }

    private function projectIds(): array
    {
        return Access::projects($this->me())->pluck('projects.id')->all();
    }

    private function formData(Invoice $invoice): array
    {
        $me = $this->me();
        $company = $this->companyId();
        $usedProducts = $invoice->exists ? $invoice->items->pluck('product_id')->all() : [];

        return [
            'invoice' => $invoice,
            'customers' => Access::customers($me)->where(fn ($q) => $q->whereNull('archived_at')->orWhere('id', $invoice->customer_id))->orderBy('name')->get(['id', 'name']),
            'projects' => Access::projects($me)->orderByDesc('id')->limit(400)->get(['id', 'project_name', 'customer_id']),
            'products' => ProductService::where('created_by', $company)
                ->where(fn ($q) => $q->where('is_active', 1)->orWhereIn('id', $usedProducts))
                ->orderBy('name')
                ->get(['id', 'name', 'sale_price', 'description', 'tax_id']),
            'categories' => ProductServiceCategory::where('created_by', $company)->where('type', 'income')->orderBy('name')->get(['id', 'name']),
            'taxes' => Tax::where('created_by', $company)->get(['id', 'name', 'rate']),
            'locked' => $invoice->exists && $invoice->payments->isNotEmpty(),
            'company' => $company,
        ];
    }

    private function validated(Request $request): array
    {
        $company = $this->companyId();

        return $request->validate([
            'customer_id' => ['required', 'integer', Rule::in(Access::customers($this->me())->pluck('customers.id')->all())],
            'project_id' => ['nullable', 'integer', Rule::in($this->projectIds())],
            'issue_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:issue_date',
            'category_id' => ['required', 'integer', Rule::exists('product_service_categories', 'id')->where('created_by', $company)],
            'ref_number' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:3000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => ['required', 'integer', Rule::exists('product_services', 'id')->where('created_by', $company)],
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.description' => 'nullable|string|max:1000',
            'items.*.tax' => 'nullable|array',
            'items.*.tax.*' => ['integer', Rule::exists('taxes', 'id')->where('created_by', $company)],
        ], [
            'items.required' => 'أضف بنداً واحداً على الأقل.',
            'items.min' => 'أضف بنداً واحداً على الأقل.',
        ], $this->names());
    }

    private function names(): array
    {
        return [
            'customer_id' => 'العميل',
            'project_id' => 'المشروع',
            'issue_date' => 'تاريخ الإصدار',
            'due_date' => 'تاريخ الاستحقاق',
            'category_id' => 'التصنيف',
            'ref_number' => 'رقم المرجع',
            'notes' => 'الملاحظات',
            'items.*.product_id' => 'المنتج أو الخدمة',
            'items.*.quantity' => 'الكمية',
            'items.*.price' => 'السعر',
            'items.*.discount' => 'الخصم',
        ];
    }
}
