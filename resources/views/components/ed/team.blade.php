@props(['users' => collect(), 'exclude' => null, 'max' => 5])
{{-- The people working on a project, as overlapping photos. The company account is
     added to every project automatically, so it is left out of the team shown. --}}
@php
    $team = collect($users)->reject(fn ($u) => $exclude && (int) $u->id === (int) $exclude)->values();
    $shown = $team->take($max);
    $more = $team->count() - $shown->count();
@endphp
@if ($team->isEmpty())
    <span {{ $attributes->merge(['class' => 'ed-team is-empty']) }}>{{ __('لم يُضف فريق بعد') }}</span>
@else
    <span {{ $attributes->merge(['class' => 'ed-team']) }} title="{{ $team->pluck('name')->implode('، ') }}" aria-label="{{ __('فريق المشروع') }}: {{ $team->pluck('name')->implode('، ') }}">
        <span class="ed-avatars">
            @foreach ($shown as $member)<x-ed.avatar :user="$member" size="sm" />@endforeach
            @if ($more > 0)<span class="ed-avatar ed-avatar--sm ed-team__more ed-num">+{{ $more }}</span>@endif
        </span>
        @if ($team->count() === 1)<span class="ed-team__name">{{ $team->first()->name }}</span>@endif
    </span>
@endif
