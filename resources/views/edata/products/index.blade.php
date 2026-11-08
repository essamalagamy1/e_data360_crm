@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', __('المنتجات والخدمات'))

@php use App\Support\Edata\Fmt; @endphp

@section('content')
<x-ed.head :title="__('المنتجات والخدمات')" :sub="__('السعر هنا افتراضي للفواتير الجديدة. الفواتير السابقة تحتفظ بأسعارها.')">
    @if ($canCreate)
        <a class="ed-btn ed-btn--primary" href="{{ route('edata.products.create') }}"><i class="ti ti-plus"></i>{{ __('إضافة منتج أو خدمة') }}</a>
    @endif
</x-ed.head>

<section class="ed-panel ed-reveal">
    <form class="ed-filters" method="GET" data-autosubmit>
        <div class="ed-search">
            <i class="ti ti-search" aria-hidden="true"></i>
            <input class="ed-input" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('ابحث بالاسم أو الرمز') }}" aria-label="{{ __('بحث') }}">
        </div>
        <select class="ed-select" name="category" aria-label="{{ __('التصنيف') }}">
            <option value="">{{ __('كل التصنيفات') }}</option>
            @foreach ($categories as $id => $name)<option value="{{ $id }}" @selected((int) request('category') === (int) $id)>{{ $name }}</option>@endforeach
        </select>
        <select class="ed-select" name="active" aria-label="{{ __('الحالة') }}">
            <option value="">{{ __('الكل') }}</option>
            <option value="1" @selected(request('active') === '1')>{{ __('مفعّل') }}</option>
            <option value="0" @selected(request('active') === '0')>{{ __('معطّل') }}</option>
        </select>
        @if (request()->hasAny(['q', 'category', 'active']))
            <a class="ed-btn ed-btn--ghost ed-btn--sm" href="{{ route('edata.products.index') }}">{{ __('مسح الفلاتر') }}</a>
        @endif
    </form>

    @if ($products->isEmpty())
        <x-ed.empty icon="ti-package" :title="__('لا توجد منتجات أو خدمات لعرضها.')">
            @if ($canCreate)<a class="ed-btn ed-btn--primary" href="{{ route('edata.products.create') }}">{{ __('إضافة منتج أو خدمة') }}</a>@endif
        </x-ed.empty>
    @else
        <div class="ed-table-wrap">
            <table class="ed-table">
                <thead><tr><th>{{ __('الاسم') }}</th><th>{{ __('التصنيف') }}</th><th>{{ __('النوع') }}</th><th>{{ __('السعر الافتراضي') }}</th><th>{{ __('في الفواتير') }}</th><th>{{ __('تاريخ الإضافة') }}</th><th>{{ __('الحالة') }}</th><th></th></tr></thead>
                <tbody>
                    @foreach ($products as $product)
                        <tr>
                            <td>
                                <span class="ed-table__title">{{ $product->name }}</span>
                                <span class="ed-table__sub">{{ \Illuminate\Support\Str::limit(strip_tags((string) $product->description), 60) }}</span>
                            </td>
                            <td>{{ $categories[$product->category_id] ?? '—' }}</td>
                            <td>{{ $product->type === 'product' ? __('منتج') : __('خدمة') }}</td>
                            <td class="ed-num is-tight">{{ Fmt::money($product->sale_price, $company) }}</td>
                            <td class="ed-num">{{ $usage[$product->id] ?? 0 }}</td>
                            <td class="is-tight">{{ \App\Support\Edata\Labels::date($product->created_at) }}</td>
                            <td>
                                @if ($product->is_active)<x-ed.badge tone="good" :text="__('مفعّل')" />@else<x-ed.badge tone="muted" :text="__('معطّل')" />@endif
                            </td>
                            <td class="is-end is-tight">
                                <span class="ed-actions">
                                    @if ($canEdit)
                                        <a class="ed-btn ed-btn--ghost ed-btn--sm" href="{{ route('edata.products.edit', $product->id) }}" aria-label="{{ __('تعديل') }}"><i class="ti ti-pencil"></i></a>
                                        <form method="POST" action="{{ route('edata.products.toggle', $product->id) }}">
                                            @csrf
                                            <button class="ed-btn ed-btn--ghost ed-btn--sm" type="submit" aria-label="{{ $product->is_active ? __('تعطيل') : __('تفعيل') }}" title="{{ $product->is_active ? __('تعطيل') : __('تفعيل') }}"><i class="ti {{ $product->is_active ? 'ti-toggle-right' : 'ti-toggle-left' }}"></i></button>
                                        </form>
                                    @endif
                                    @if ($canDelete && empty($usage[$product->id]))
                                        <form method="POST" action="{{ route('edata.products.destroy', $product->id) }}" data-confirm="{{ __('حذف') }} {{ $product->name }}؟">
                                            @csrf @method('DELETE')
                                            <button class="ed-btn ed-btn--ghost ed-btn--sm" type="submit" aria-label="{{ __('حذف') }}"><i class="ti ti-trash"></i></button>
                                        </form>
                                    @endif
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <x-ed.pager :items="$products" />
    @endif
</section>
@endsection
