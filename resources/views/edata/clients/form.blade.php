@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', $client->exists ? __('تعديل عميل') : __('إضافة عميل'))

@section('content')
<x-ed.head :title="$client->exists ? $client->name : __('إضافة عميل')" :crumbs="[__('العملاء') => route('edata.clients.index')]" />

<form class="ed-panel" method="POST" action="{{ $client->exists ? route('edata.clients.update', $client->id) : route('edata.clients.store') }}" style="max-width:920px">
    @csrf
    @if ($client->exists) @method('PUT') @endif
    <div class="ed-panel__body">
        <div class="ed-form">
            <x-ed.field :label="__('اسم العميل أو الشركة')" name="name" required>
                <input class="ed-input @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $client->name) }}" required maxlength="191">
            </x-ed.field>
            <x-ed.field :label="__('الشخص المسؤول')" name="contact_person">
                <input class="ed-input" id="contact_person" name="contact_person" value="{{ old('contact_person', $client->contact_person) }}">
            </x-ed.field>
            <x-ed.field :label="__('البريد الإلكتروني')" name="email">
                <input class="ed-input @error('email') is-invalid @enderror" type="email" id="email" name="email" value="{{ old('email', $client->email) }}" dir="ltr">
            </x-ed.field>
            <x-ed.field :label="__('رقم الهاتف')" name="contact">
                <input class="ed-input @error('contact') is-invalid @enderror" id="contact" name="contact" value="{{ old('contact', $client->contact) }}" dir="ltr">
            </x-ed.field>
            <x-ed.field :label="__('الدولة')" name="billing_country">
                <input class="ed-input" id="billing_country" name="billing_country" value="{{ old('billing_country', $client->billing_country) }}">
            </x-ed.field>
            <x-ed.field :label="__('المدينة')" name="billing_city">
                <input class="ed-input" id="billing_city" name="billing_city" value="{{ old('billing_city', $client->billing_city) }}">
            </x-ed.field>
            <x-ed.field :label="__('نوع النشاط')" name="industry">
                <input class="ed-input" id="industry" name="industry" value="{{ old('industry', $client->industry) }}">
            </x-ed.field>
            <x-ed.field :label="__('مصدر العميل')" name="source" :hint="__('مثل: تيك توك، إحالة، الموقع.')">
                <input class="ed-input" id="source" name="source" value="{{ old('source', $client->source) }}">
            </x-ed.field>
            <x-ed.field :label="__('حالة العميل')" name="crm_status" required>
                <select class="ed-select" id="crm_status" name="crm_status" required>
                    @foreach ($statuses as $value => $pair)
                        <option value="{{ $value }}" @selected(old('crm_status', $client->crm_status ?: 'active') === $value)>{{ __($pair[0]) }}</option>
                    @endforeach
                </select>
            </x-ed.field>
            <x-ed.field :label="__('الرقم الضريبي')" name="tax_number">
                <input class="ed-input" id="tax_number" name="tax_number" value="{{ old('tax_number', $client->tax_number) }}" dir="ltr">
            </x-ed.field>
            <x-ed.field :label="__('العنوان')" name="billing_address" full>
                <input class="ed-input" id="billing_address" name="billing_address" value="{{ old('billing_address', $client->billing_address) }}">
            </x-ed.field>
            <x-ed.field :label="__('ملاحظات')" name="notes" full>
                <textarea class="ed-textarea" id="notes" name="notes" rows="3">{{ old('notes', $client->notes) }}</textarea>
            </x-ed.field>
        </div>
    </div>
    <div class="ed-form__foot">
        <a class="ed-btn" href="{{ $client->exists ? route('edata.clients.show', $client->id) : route('edata.clients.index') }}">{{ __('إلغاء') }}</a>
        <button class="ed-btn ed-btn--primary" type="submit">{{ $client->exists ? __('حفظ التعديلات') : __('إضافة العميل') }}</button>
    </div>
</form>
@endsection
