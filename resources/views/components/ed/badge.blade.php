@props(['pair' => null, 'tone' => null, 'text' => null])
@php
    $label = $text ?? ($pair[0] ?? '');
    $color = $tone ?? ($pair[1] ?? 'neutral');
@endphp
<span {{ $attributes->merge(['class' => 'ed-badge tone-' . $color]) }}>{{ __($label) }}</span>
