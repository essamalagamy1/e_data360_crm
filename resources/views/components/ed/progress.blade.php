@props(['value' => 0, 'late' => false])
@php
    $value = max(0, min(100, (int) $value));
    $state = $late ? 'is-bad' : ($value >= 100 ? 'is-good' : '');
@endphp
<div class="ed-progress-row" role="progressbar" aria-valuenow="{{ $value }}" aria-valuemin="0" aria-valuemax="100">
    <div class="ed-progress {{ $state }}" style="--v:{{ $value }}%"><i></i></div>
    <b class="ed-num">{{ $value }}%</b>
</div>
