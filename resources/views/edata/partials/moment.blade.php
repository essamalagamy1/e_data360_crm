{{-- The screen after clock-in or clock-out. Comes from a one-time session flash, so a reload never replays it. --}}
@php $moment = session('ed_moment'); @endphp
@if (is_array($moment))
    <div class="ed-moment is-{{ $moment['kind'] }}" id="edMoment" role="dialog" aria-modal="true" aria-labelledby="edMomentTitle" data-seconds="{{ (int) ($moment['seconds'] ?? 10) }}">
        <div class="ed-moment__sky" aria-hidden="true">
            <i class="ed-sky__stars"></i>
            <i class="ed-moment__rays"></i>
            <i class="ed-moment__sun"></i>
            <i class="ed-moment__land"></i>
            @for ($i = 0; $i < 14; $i++)<i class="ed-moment__mote"></i>@endfor
        </div>

        <div class="ed-moment__body">
            <svg class="ed-moment__write" viewBox="0 0 1000 220" role="img" aria-labelledby="edMomentTitle" data-write>
                <title id="edMomentTitle">{{ $moment['title'] }}</title>
                <text x="500" y="150" text-anchor="middle" font-size="130">{{ $moment['title'] }}</text>
            </svg>

            @if (!empty($moment['lead']))<p class="ed-moment__lead">{{ $moment['lead'] }}</p>@endif
            @if (!empty($moment['dua']))<p class="ed-moment__dua" dir="rtl">{{ $moment['dua'] }}</p>@endif

            @if (!empty($moment['facts']))
                <dl class="ed-moment__facts">
                    @foreach ($moment['facts'] as $fact)
                        <div><dt>{{ $fact['label'] }}</dt><dd class="ed-num">{{ $fact['value'] }}</dd></div>
                    @endforeach
                </dl>
            @endif

            @if (!empty($moment['focus']))<p class="ed-moment__focus"><i class="ti ti-target"></i><bdi>{{ $moment['focus'] }}</bdi></p>@endif
            @if (!empty($moment['note']))<p class="ed-moment__focus"><i class="ti ti-heart"></i><span>{{ $moment['note'] }}</span></p>@endif
            @if (!empty($moment['farewell']))<p class="ed-moment__farewell">{{ $moment['farewell'] }}</p>@endif

            <button type="button" class="ed-moment__go" data-moment-close>{{ $moment['button'] }}</button>
        </div>

        <i class="ed-moment__timer" aria-hidden="true"></i>
    </div>
@endif
