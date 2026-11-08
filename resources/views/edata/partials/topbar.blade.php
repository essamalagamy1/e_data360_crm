@php
    $edMe = \Auth::user();
    $edEmployee = \App\Models\Employee::where('user_id', $edMe->id)->first();
    $edOpen = null;
    if ($edEmployee) {
        $edService = \App\Support\Edata\AttendanceService::for((int) $edMe->creatorId());
        $edOpen = $edService->openSession($edEmployee);
    }
    $edUnread = \App\Models\Notification::where('user_id', $edMe->id)->where('data', 'like', '%"title"%')->where('is_read', 0)->count();
@endphp

<header class="ed-top">
    <button type="button" class="ed-iconbtn ed-menu-toggle" id="edMenuToggle" aria-label="{{ __('فتح القائمة') }}"><i class="ti ti-menu-2"></i></button>
    <div class="ed-top__title"><bdi>@yield('page-title')</bdi></div>
    <div class="ed-top__space"></div>

    @if ($edEmployee)
        @if ($edOpen)
            <a class="ed-clockchip is-on" href="{{ route('edata.me.attendance') }}" data-clockchip data-server-epoch="{{ now('UTC')->getTimestamp() }}" data-open-since="{{ $edService->moment($edOpen, 'in')->getTimestamp() }}">
                <i class="ed-pulse"></i><span class="t">{{ __('تعمل منذ') }}</span> <b class="ed-ltr">00:00:00</b>
            </a>
        @else
            <a class="ed-clockchip" href="{{ route('edata.me.attendance') }}"><i class="ed-pulse"></i><span>{{ __('سجّل حضورك') }}</span></a>
        @endif
    @endif

    <div class="ed-pop" id="edBell" data-feed="{{ route('edata.notifications.feed') }}" data-empty="{{ __('لا توجد إشعارات حالياً.') }}" data-failed="{{ __('تعذر تحميل الإشعارات.') }}">
        <button type="button" class="ed-iconbtn" data-pop aria-expanded="false" aria-label="{{ __('الإشعارات') }}">
            <i class="ti ti-bell"></i>
            <span class="ed-iconbtn__dot ed-num" id="edBellDot" @if (!$edUnread) hidden @endif>{{ $edUnread > 99 ? '99+' : $edUnread }}</span>
        </button>
        <div class="ed-pop__panel ed-bell">
            <div class="ed-bell__head">
                <span>{{ __('الإشعارات') }}</span>
                <button type="button" id="edBellReadAll" data-url="{{ route('edata.notifications.read-all') }}">{{ __('تعليم الكل كمقروء') }}</button>
            </div>
            <div class="ed-bell__list" id="edBellList">
                <div style="padding:14px;display:grid;gap:8px">
                    <div class="ed-skeleton" style="height:14px"></div>
                    <div class="ed-skeleton" style="height:14px;width:70%"></div>
                </div>
            </div>
            <a class="ed-bell__foot" href="{{ route('edata.notifications.index') }}">{{ __('كل الإشعارات') }}</a>
        </div>
    </div>

    <div class="ed-pop">
        <button type="button" class="ed-iconbtn" data-pop aria-expanded="false" aria-label="{{ __('اللغة') }}"><i class="ti ti-language"></i></button>
        <div class="ed-pop__panel">
            <a class="ed-pop__item" href="{{ route('change.language', 'ar') }}">العربية</a>
            <a class="ed-pop__item" href="{{ route('change.language', 'en') }}">English</a>
        </div>
    </div>

    <div class="ed-pop">
        <button type="button" class="ed-userbtn" data-pop aria-expanded="false" aria-label="{{ __('حسابي') }}">
            <x-ed.avatar :user="$edMe" />
        </button>
        <div class="ed-pop__panel">
            <div style="padding:8px 10px 10px;border-bottom:1px solid var(--line);margin-bottom:4px">
                <b style="display:block;font-weight:700">{{ $edMe->name }}</b>
                <small style="color:var(--text-3)">{{ $edMe->email }}</small>
            </div>
            <a class="ed-pop__item" href="{{ route('edata.people.show', $edMe->id) }}"><i class="ti ti-id"></i>{{ __('صفحتي الشخصية') }}</a>
            <a class="ed-pop__item" href="{{ route('profile') }}"><i class="ti ti-settings"></i>{{ __('بيانات الحساب وكلمة المرور') }}</a>
            <form action="{{ route('logout') }}" method="POST">
                @csrf
                <button type="submit" class="ed-pop__item"><i class="ti ti-logout"></i>{{ __('تسجيل الخروج') }}</button>
            </form>
        </div>
    </div>
</header>
