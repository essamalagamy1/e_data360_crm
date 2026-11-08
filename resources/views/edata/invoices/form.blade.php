@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', $invoice->exists ? __('تعديل فاتورة') : __('فاتورة جديدة'))

@php
    use App\Support\Edata\Fmt;
    $issue = $invoice->issue_date ? \Carbon\Carbon::parse($invoice->issue_date)->toDateString() : '';
    $due = $invoice->due_date ? \Carbon\Carbon::parse($invoice->due_date)->toDateString() : '';
    $lines = old('items');
    if ($lines === null) {
        $lines = $invoice->exists
            ? $invoice->items->map(fn ($i) => ['product_id' => $i->product_id, 'description' => $i->description, 'quantity' => $i->quantity, 'price' => $i->price, 'discount' => $i->discount, 'tax' => array_filter(explode(',', (string) $i->tax))])->all()
            : [[]];
    }
    $productData = $products->map(fn ($p) => ['id' => $p->id, 'price' => (float) $p->sale_price, 'description' => $p->description, 'tax' => array_values(array_filter(explode(',', (string) $p->tax_id)))])->values();
    $taxData = $taxes->map(fn ($t) => ['id' => $t->id, 'rate' => (float) $t->rate])->values();
    $settings = Fmt::settings($company);
@endphp

@section('content')
<x-ed.head :title="$invoice->exists ? Fmt::invoiceNumber($invoice->invoice_id, $company) : __('فاتورة جديدة')" :crumbs="[__('الفواتير') => route('edata.invoices.index')]" />

@if ($locked)
    <div class="ed-alert tone-warn" style="margin-bottom:14px"><i class="ti ti-lock"></i><span>{{ __('هذه الفاتورة عليها دفعات مسجلة، لذلك بنودها وعميلها مقفلان. يمكنك تعديل الربط بالمشروع والاستحقاق والملاحظات.') }}</span></div>
@endif

@if ($products->isEmpty())
    <div class="ed-alert tone-warn" style="margin-bottom:14px"><i class="ti ti-package"></i><span>{{ __('لا توجد منتجات أو خدمات مفعّلة. أضف واحدة أولاً لتظهر في بنود الفاتورة.') }} <a class="ed-link" href="{{ route('edata.products.create') }}">{{ __('إضافة منتج أو خدمة') }}</a></span></div>
@endif

<form method="POST" action="{{ $invoice->exists ? route('edata.invoices.update', $invoice->id) : route('edata.invoices.store') }}" id="invoiceForm">
    @csrf
    @if ($invoice->exists) @method('PUT') @endif

    <div class="ed-stack">
        <section class="ed-panel">
            <div class="ed-panel__head"><h2>{{ __('بيانات الفاتورة') }}</h2></div>
            <div class="ed-panel__body">
                <div class="ed-form ed-form--3">
                    <x-ed.field :label="__('العميل')" name="customer_id" required>
                        <select class="ed-select @error('customer_id') is-invalid @enderror" id="customer_id" name="customer_id" required @disabled($locked)>
                            <option value="">{{ __('اختر عميلاً') }}</option>
                            @foreach ($customers as $c)
                                <option value="{{ $c->id }}" @selected((int) old('customer_id', $invoice->customer_id) === (int) $c->id)>{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </x-ed.field>
                    <x-ed.field :label="__('المشروع المرتبط')" name="project_id">
                        <select class="ed-select" id="project_id" name="project_id">
                            <option value="">{{ __('بدون مشروع') }}</option>
                            @foreach ($projects as $p)
                                <option value="{{ $p->id }}" @selected((int) old('project_id', $invoice->project_id) === (int) $p->id)>{{ $p->project_name }}</option>
                            @endforeach
                        </select>
                    </x-ed.field>
                    <x-ed.field :label="__('التصنيف')" name="category_id" required>
                        <select class="ed-select @error('category_id') is-invalid @enderror" id="category_id" name="category_id" required @disabled($locked)>
                            @foreach ($categories as $cat)
                                <option value="{{ $cat->id }}" @selected((int) old('category_id', $invoice->category_id) === (int) $cat->id)>{{ $cat->name }}</option>
                            @endforeach
                        </select>
                    </x-ed.field>
                    <x-ed.field :label="__('تاريخ الإصدار')" name="issue_date" required>
                        <input class="ed-input" type="date" id="issue_date" name="issue_date" value="{{ old('issue_date', $issue) }}" required @disabled($locked)>
                    </x-ed.field>
                    <x-ed.field :label="__('تاريخ الاستحقاق')" name="due_date" required>
                        <input class="ed-input @error('due_date') is-invalid @enderror" type="date" id="due_date" name="due_date" value="{{ old('due_date', $due) }}" required>
                    </x-ed.field>
                    <x-ed.field :label="__('رقم المرجع')" name="ref_number">
                        <input class="ed-input" id="ref_number" name="ref_number" value="{{ old('ref_number', $invoice->ref_number) }}">
                    </x-ed.field>
                </div>
            </div>
        </section>

        <section class="ed-panel">
            <div class="ed-panel__head"><h2>{{ __('البنود') }}</h2></div>
            @error('items')<div class="ed-error" style="padding:10px 18px 0">{{ $message }}</div>@enderror
            <div class="ed-table-wrap" style="padding:8px 12px">
                <table class="ed-lines">
                    <thead>
                        <tr>
                            <th style="min-width:210px">{{ __('المنتج أو الخدمة') }}</th>
                            <th style="min-width:180px">{{ __('الوصف') }}</th>
                            <th style="width:84px">{{ __('الكمية') }}</th>
                            <th style="width:110px">{{ __('السعر') }}</th>
                            <th style="width:100px">{{ __('الخصم') }}</th>
                            @if ($taxes->isNotEmpty())<th style="width:140px">{{ __('الضريبة') }}</th>@endif
                            <th style="width:110px">{{ __('الإجمالي') }}</th>
                            <th style="width:40px"></th>
                        </tr>
                    </thead>
                    <tbody id="invoiceLines">
                        @foreach ($lines as $index => $line)
                            <tr data-line>
                                <td>
                                    <select class="ed-select" name="items[{{ $index }}][product_id]" data-product required @disabled($locked) aria-label="{{ __('المنتج أو الخدمة') }}">
                                        <option value="">{{ __('اختر') }}</option>
                                        @foreach ($products as $p)
                                            <option value="{{ $p->id }}" @selected((int) ($line['product_id'] ?? 0) === (int) $p->id)>{{ $p->name }}</option>
                                        @endforeach
                                    </select>
                                    @error("items.$index.product_id")<span class="ed-error">{{ $message }}</span>@enderror
                                </td>
                                <td><input class="ed-input" name="items[{{ $index }}][description]" value="{{ $line['description'] ?? '' }}" data-desc @disabled($locked) aria-label="{{ __('الوصف') }}"></td>
                                <td><input class="ed-input" type="number" min="1" step="1" name="items[{{ $index }}][quantity]" value="{{ $line['quantity'] ?? 1 }}" data-qty required dir="ltr" @disabled($locked) aria-label="{{ __('الكمية') }}"></td>
                                <td><input class="ed-input" type="number" min="0" step="0.01" name="items[{{ $index }}][price]" value="{{ $line['price'] ?? 0 }}" data-price required dir="ltr" @disabled($locked) aria-label="{{ __('السعر') }}"></td>
                                <td><input class="ed-input" type="number" min="0" step="0.01" name="items[{{ $index }}][discount]" value="{{ $line['discount'] ?? 0 }}" data-discount dir="ltr" @disabled($locked) aria-label="{{ __('الخصم') }}"></td>
                                @if ($taxes->isNotEmpty())
                                    <td>
                                        <select class="ed-select" name="items[{{ $index }}][tax][]" data-tax @disabled($locked) aria-label="{{ __('الضريبة') }}">
                                            <option value="">{{ __('بدون') }}</option>
                                            @foreach ($taxes as $t)
                                                <option value="{{ $t->id }}" @selected(in_array($t->id, (array) ($line['tax'] ?? []))) >{{ $t->name }} {{ (float) $t->rate }}%</option>
                                            @endforeach
                                        </select>
                                    </td>
                                @endif
                                <td class="ed-num" data-total style="padding-top:14px;font-weight:600">0.00</td>
                                <td>@unless ($locked)<button type="button" class="ed-btn ed-btn--ghost ed-btn--sm" data-remove aria-label="{{ __('حذف البند') }}"><i class="ti ti-x"></i></button>@endunless</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @unless ($locked)
                <div style="padding:4px 18px 16px"><button type="button" class="ed-btn ed-btn--sm" id="addLine"><i class="ti ti-plus"></i>{{ __('إضافة بند') }}</button></div>
            @endunless
            <div style="padding:0 18px 18px">
                <div class="ed-sheet__totals">
                    <div><span>{{ __('المجموع') }}</span><b class="ed-num" id="sumSub">0.00</b></div>
                    <div><span>{{ __('الخصم') }}</span><b class="ed-num" id="sumDiscount">0.00</b></div>
                    <div><span>{{ __('الضريبة') }}</span><b class="ed-num" id="sumTax">0.00</b></div>
                    <div class="is-grand"><span>{{ __('الإجمالي') }}</span><span class="ed-num" id="sumTotal">0.00</span></div>
                </div>
            </div>
        </section>

        <section class="ed-panel">
            <div class="ed-panel__head"><h2>{{ __('ملاحظات') }}</h2></div>
            <div class="ed-panel__body">
                <textarea class="ed-textarea" name="notes" rows="3" aria-label="{{ __('ملاحظات') }}" placeholder="{{ __('تظهر للعميل في الفاتورة، مثل طريقة الدفع وبيانات التحويل.') }}">{{ old('notes', $invoice->notes) }}</textarea>
            </div>
            <div class="ed-form__foot">
                <a class="ed-btn" href="{{ $invoice->exists ? route('edata.invoices.show', $invoice->id) : route('edata.invoices.index') }}">{{ __('إلغاء') }}</a>
                <button class="ed-btn ed-btn--primary" type="submit">{{ $invoice->exists ? __('حفظ الفاتورة') : __('إنشاء الفاتورة') }}</button>
            </div>
        </section>
    </div>
</form>
@endsection

@push('edata-scripts')
<script>
(function () {
    var products = @json($productData);
    var taxes = @json($taxData);
    var decimals = {{ (int) ($settings['decimal_number'] ?? 2) }};
    var body = document.getElementById('invoiceLines');
    var byId = function (list, id) { return list.filter(function (x) { return String(x.id) === String(id); })[0]; };
    var fmt = function (n) { return n.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }); };

    function recalc() {
        var sub = 0, disc = 0, tax = 0;
        body.querySelectorAll('[data-line]').forEach(function (row) {
            var qty = parseFloat(row.querySelector('[data-qty]').value) || 0;
            var price = parseFloat(row.querySelector('[data-price]').value) || 0;
            var discount = parseFloat(row.querySelector('[data-discount]').value) || 0;
            var taxSelect = row.querySelector('[data-tax]');
            var gross = qty * price;
            var net = gross - discount;
            var rate = 0;
            if (taxSelect && taxSelect.value) { var t = byId(taxes, taxSelect.value); rate = t ? t.rate : 0; }
            var lineTax = net * rate / 100;
            sub += gross; disc += discount; tax += lineTax;
            row.querySelector('[data-total]').textContent = fmt(net + lineTax);
        });
        document.getElementById('sumSub').textContent = fmt(sub);
        document.getElementById('sumDiscount').textContent = fmt(disc);
        document.getElementById('sumTax').textContent = fmt(tax);
        document.getElementById('sumTotal').textContent = fmt(sub - disc + tax);
    }

    function renumber() {
        body.querySelectorAll('[data-line]').forEach(function (row, index) {
            row.querySelectorAll('[name]').forEach(function (field) {
                field.name = field.name.replace(/items\[\d+\]/, 'items[' + index + ']');
            });
        });
    }

    body.addEventListener('input', recalc);
    body.addEventListener('change', function (event) {
        if (event.target.matches('[data-product]')) {
            var product = byId(products, event.target.value);
            var row = event.target.closest('[data-line]');
            if (product) {
                row.querySelector('[data-price]').value = product.price;
                var desc = row.querySelector('[data-desc]');
                if (!desc.value) { desc.value = product.description || ''; }
                var taxSelect = row.querySelector('[data-tax]');
                if (taxSelect && product.tax.length) { taxSelect.value = product.tax[0]; }
            }
        }
        recalc();
    });
    body.addEventListener('click', function (event) {
        var remove = event.target.closest('[data-remove]');
        if (!remove) { return; }
        if (body.querySelectorAll('[data-line]').length === 1) {
            window.edToast(@json(__('الفاتورة تحتاج بنداً واحداً على الأقل.')), 'error');
            return;
        }
        remove.closest('[data-line]').remove();
        renumber();
        recalc();
    });
    var add = document.getElementById('addLine');
    if (add) {
        add.addEventListener('click', function () {
            var first = body.querySelector('[data-line]');
            var clone = first.cloneNode(true);
            clone.querySelectorAll('input').forEach(function (i) { i.value = i.matches('[data-qty]') ? 1 : (i.matches('[data-desc]') ? '' : 0); });
            clone.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });
            clone.querySelectorAll('.ed-error').forEach(function (e) { e.remove(); });
            body.appendChild(clone);
            renumber();
            recalc();
            clone.querySelector('[data-product]').focus();
        });
    }
    recalc();
})();
</script>
@endpush
