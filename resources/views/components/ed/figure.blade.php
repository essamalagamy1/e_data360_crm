@props(['label', 'value' => null, 'count' => null, 'money' => null, 'company' => null, 'tone' => null, 'hint' => null, 'href' => null])
@php
    $tag = $href ? 'a' : 'div';
    $toneClass = $tone ? 'tone-text-' . $tone : '';
    $symbol = '';
    $decimals = 0;
    if ($money !== null && $company) {
        $settings = \App\Support\Edata\Fmt::settings((int) $company);
        $symbol = $settings['site_currency_symbol'] ?? '';
        $decimals = (int) ($settings['decimal_number'] ?? 2);
    }
@endphp
<{{ $tag }} class="ed-figure" @if ($href) href="{{ $href }}" @endif>
    <span class="ed-figure__label">{{ $label }}</span>
    @if ($money !== null)
        <span class="ed-figure__value is-money ed-num {{ $toneClass }}" data-count="{{ (float) $money }}" data-decimals="{{ $decimals }}" data-suffix=" {{ $symbol }}">{{ \App\Support\Edata\Fmt::money($money, (int) $company) }}</span>
    @elseif ($count !== null)
        <span class="ed-figure__value ed-num {{ $toneClass }}" data-count="{{ (int) $count }}">{{ number_format((int) $count) }}</span>
    @else
        <span class="ed-figure__value ed-num {{ $toneClass }}">{{ $value }}</span>
    @endif
    @if ($hint)<span class="ed-figure__hint">{{ $hint }}</span>@endif
</{{ $tag }}>
