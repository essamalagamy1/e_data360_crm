@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', $product->exists ? __('تعديل') : __('إضافة منتج أو خدمة'))

@section('content')
<x-ed.head :title="$product->exists ? $product->name : __('إضافة منتج أو خدمة')" :crumbs="[__('المنتجات والخدمات') => route('edata.products.index')]" />

<form class="ed-panel" method="POST" action="{{ $product->exists ? route('edata.products.update', $product->id) : route('edata.products.store') }}" style="max-width:820px">
    @csrf
    @if ($product->exists) @method('PUT') @endif
    <div class="ed-panel__body">
        <div class="ed-form">
            <x-ed.field :label="__('الاسم')" name="name" required full>
                <input class="ed-input @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $product->name) }}" required maxlength="191">
            </x-ed.field>
            <x-ed.field :label="__('النوع')" name="type" required>
                <select class="ed-select" id="type" name="type" required>
                    <option value="service" @selected(old('type', $product->type) === 'service')>{{ __('خدمة') }}</option>
                    <option value="product" @selected(old('type', $product->type) === 'product')>{{ __('منتج') }}</option>
                </select>
            </x-ed.field>
            <x-ed.field :label="__('السعر الافتراضي')" name="sale_price" required>
                <input class="ed-input @error('sale_price') is-invalid @enderror" type="number" min="0" step="0.01" id="sale_price" name="sale_price" value="{{ old('sale_price', $product->sale_price) }}" required dir="ltr">
            </x-ed.field>
            <x-ed.field :label="__('التصنيف')" name="category_id" required>
                <select class="ed-select @error('category_id') is-invalid @enderror" id="category_id" name="category_id" required>
                    <option value="">{{ __('اختر') }}</option>
                    @foreach ($allCategories as $c)
                        <option value="{{ $c->id }}" @selected((int) old('category_id', $product->category_id) === (int) $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            </x-ed.field>
            <x-ed.field :label="__('الوحدة')" name="unit_id" required>
                <select class="ed-select @error('unit_id') is-invalid @enderror" id="unit_id" name="unit_id" required>
                    <option value="">{{ __('اختر') }}</option>
                    @foreach ($units as $u)
                        <option value="{{ $u->id }}" @selected((int) old('unit_id', $product->unit_id) === (int) $u->id)>{{ $u->name }}</option>
                    @endforeach
                </select>
            </x-ed.field>
            <x-ed.field :label="__('حساب الإيراد')" name="sale_chartaccount_id" required :hint="__('الحساب الذي تُسجل عليه مبيعات هذا البند.')">
                <select class="ed-select @error('sale_chartaccount_id') is-invalid @enderror" id="sale_chartaccount_id" name="sale_chartaccount_id" required>
                    <option value="">{{ __('اختر') }}</option>
                    @foreach ($accounts as $a)
                        <option value="{{ $a->id }}" @selected((int) old('sale_chartaccount_id', $product->sale_chartaccount_id) === (int) $a->id)>{{ $a->code }} {{ $a->name }}</option>
                    @endforeach
                </select>
            </x-ed.field>
            <x-ed.field :label="__('الرمز')" name="sku" :hint="__('يُولد تلقائياً لو تركته فارغاً.')">
                <input class="ed-input" id="sku" name="sku" value="{{ old('sku', $product->sku) }}" dir="ltr">
            </x-ed.field>
            <x-ed.field :label="__('الوصف')" name="description" full>
                <textarea class="ed-textarea" id="description" name="description" rows="3">{{ old('description', $product->description) }}</textarea>
            </x-ed.field>
        </div>
    </div>
    <div class="ed-form__foot">
        <a class="ed-btn" href="{{ route('edata.products.index') }}">{{ __('إلغاء') }}</a>
        <button class="ed-btn ed-btn--primary" type="submit">{{ $product->exists ? __('حفظ التعديلات') : __('إضافة') }}</button>
    </div>
</form>
@endsection
