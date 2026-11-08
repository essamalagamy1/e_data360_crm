@props(['user' => null, 'name' => null, 'file' => null, 'size' => ''])
@php
    $displayName = $name ?? ($user->name ?? '');
    $avatarFile = $file ?? ($user->avatar ?? null);
    $class = 'ed-avatar' . ($size ? ' ed-avatar--' . $size : '');
    $hasFile = !empty($avatarFile) && $avatarFile !== 'avatar.png';
@endphp
@if ($hasFile)
    <img class="{{ $class }}" src="{{ \App\Models\Utility::get_file('uploads/avatar/') . $avatarFile }}" alt="{{ $displayName }}" loading="lazy"
         data-initials="{{ \App\Support\Edata\Labels::initials($displayName) }}"
         onerror="var s=document.createElement('span');s.className=this.className;s.textContent=this.dataset.initials;this.replaceWith(s)">
@else
    <span class="{{ $class }}" aria-hidden="true">{{ \App\Support\Edata\Labels::initials($displayName) }}</span>
@endif
