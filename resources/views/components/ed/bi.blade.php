@props(['ar', 'en'])
{{-- A label in both languages. Invoices travel to clients in either, so both always print. --}}
@php $edBiEn = app()->getLocale() === 'en'; @endphp
<span {{ $attributes->merge(['class' => 'inv-bi']) }}><span>{{ $edBiEn ? $en : $ar }}</span><small>{{ $edBiEn ? $ar : $en }}</small></span>
