@props(['label', 'name' => null, 'required' => false, 'hint' => null, 'full' => false, 'for' => null])
<div class="ed-field {{ $full ? 'ed-field--full' : '' }}">
    <label class="ed-label" @if ($for ?? $name) for="{{ $for ?? $name }}" @endif>{{ $label }}@if ($required)<em aria-hidden="true">*</em>@endif</label>
    {{ $slot }}
    @if ($name)
        @error($name)<span class="ed-error" role="alert">{{ $message }}</span>@enderror
    @endif
    @if ($hint)<span class="ed-hint">{{ $hint }}</span>@endif
</div>
