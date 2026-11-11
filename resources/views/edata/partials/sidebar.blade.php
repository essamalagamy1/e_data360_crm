@php
    use App\Support\Edata\Access;
    use Carbon\Carbon;

    $edMe = \Auth::user();
    $edCompany = (int) $edMe->creatorId();
    $edLogo = $edSettings['company_logo_dark'] ?? 'logo-dark.png';
    $edHasEmployee = \App\Models\Employee::where('user_id', $edMe->id)->exists();
    $edOverview = Access::fullOverview($edMe) || $edMe->can('manage invoice');

    $edOverdueTasks = Access::tasks($edMe)
        ->whereNull('cancelled_at')->where('is_complete', 0)
        ->whereNotIn('stage_id', \App\Support\Edata\TaskRules::doneStageIds($edCompany))
        ->whereNotNull('end_date')->whereDate('end_date', '<', Carbon::today())
        ->count();

    $edActive = function (...$patterns) {
        foreach ($patterns as $pattern) {
            if (request()->routeIs($pattern)) {
                return 'is-active';
            }
        }
        return '';
    };

    $edCanApprove = \App\Support\Edata\EmployeeServices::canApprove($edMe);
    $edCanDaily = \App\Support\Edata\EmployeeServices::allows($edMe, 'daily');
    $edPending = $edCanApprove && \Illuminate\Support\Facades\Schema::hasTable('edata_requests') ? \App\Support\Edata\EmployeeServices::pendingCount($edCompany) : 0;
    $edCanAnnounce = Access::isOwner($edMe) || $edMe->can('create announcement') || $edMe->can('manage announcement');
@endphp

<aside class="ed-side" id="edSide" aria-label="{{ __('القائمة الرئيسية') }}">
    <a class="ed-side__brand" href="{{ route('dashboard') }}">
        <img src="{{ $edLogoBase . '/' . $edLogo }}" alt="E-Data 360" onerror="this.remove()">
        <span class="ed-wordmark">{{ __('إدارة الأعمال') }}</span>
    </a>

    <nav class="ed-side__nav">
        @if ($edOverview)
            <a class="ed-nav {{ $edActive('dashboard') }}" href="{{ route('dashboard') }}"><i class="ti ti-layout-2"></i><span>{{ __('لوحة التحكم') }}</span></a>
        @endif
        <a class="ed-nav {{ $edActive('edata.me') }}" href="{{ route('edata.me') }}"><i class="ti ti-user"></i><span>{{ __('مساحتي') }}</span></a>
        @if ($edHasEmployee)
            <a class="ed-nav {{ $edActive('edata.me.attendance') }}" href="{{ route('edata.me.attendance') }}"><i class="ti ti-clock"></i><span>{{ __('حضوري') }}</span></a>
        @endif

        @if ($edMe->can('manage user') || $edMe->can('manage employee') || $edMe->can('manage attendance') || $edCanApprove || $edCanAnnounce || $edCanDaily)
            <div class="ed-side__group">{{ __('الفريق') }}</div>
            @if ($edMe->can('manage user') || $edMe->can('manage employee'))
                <a class="ed-nav {{ $edActive('edata.people.*') }}" href="{{ route('edata.people.index') }}"><i class="ti ti-users"></i><span>{{ __('الموظفون') }}</span></a>
            @endif
            @can('manage attendance')
                <a class="ed-nav {{ $edActive('edata.attendance.*') }}" href="{{ route('edata.attendance.index') }}"><i class="ti ti-calendar-time"></i><span>{{ __('الحضور والانصراف') }}</span></a>
            @endcan
            @if ($edCanDaily)
                <a class="ed-nav {{ $edActive('edata.daily.*') }}" href="{{ route('edata.daily.index') }}"><i class="ti ti-notes"></i><span>{{ __('التقارير اليومية') }}</span></a>
            @endif
            @if ($edCanApprove)
                <a class="ed-nav {{ $edActive('edata.requests.*') }}" href="{{ route('edata.requests.index') }}">
                    <i class="ti ti-inbox"></i><span>{{ __('الطلبات') }}</span>
                    @if ($edPending > 0)<b class="ed-nav__count" title="{{ __('طلبات تنتظر الرد') }}">{{ $edPending }}</b>@endif
                </a>
            @endif
            @if ($edCanAnnounce)
                <a class="ed-nav {{ $edActive('edata.announcements.*') }}" href="{{ route('edata.announcements.index') }}"><i class="ti ti-speakerphone"></i><span>{{ __('الإعلانات') }}</span></a>
            @endif
        @endif

        <div class="ed-side__group">{{ __('الأعمال') }}</div>
        @can('manage customer')
            <a class="ed-nav {{ $edActive('edata.clients.*') }}" href="{{ route('edata.clients.index') }}"><i class="ti ti-building-store"></i><span>{{ __('العملاء') }}</span></a>
        @endcan
        <a class="ed-nav {{ $edActive('projects.*') }}" href="{{ route('projects.index') }}"><i class="ti ti-briefcase"></i><span>{{ __('المشاريع') }}</span></a>
        <a class="ed-nav {{ $edActive('edata.tasks.*') }}" href="{{ route('edata.tasks.index') }}">
            <i class="ti ti-checkbox"></i><span>{{ __('المهام') }}</span>
            @if ($edOverdueTasks > 0)<b class="ed-nav__count" title="{{ __('مهام متأخرة') }}">{{ $edOverdueTasks }}</b>@endif
        </a>

        @if ($edMe->can('manage invoice') || $edMe->can('manage product & service'))
            <div class="ed-side__group">{{ __('المالية') }}</div>
            @can('manage invoice')
                <a class="ed-nav {{ $edActive('edata.invoices.*') }}" href="{{ route('edata.invoices.index') }}"><i class="ti ti-file-invoice"></i><span>{{ __('الفواتير') }}</span></a>
            @endcan
            @can('manage product & service')
                <a class="ed-nav {{ $edActive('edata.products.*') }}" href="{{ route('edata.products.index') }}"><i class="ti ti-package"></i><span>{{ __('المنتجات والخدمات') }}</span></a>
            @endcan
        @endif

        @if ($edMe->can('manage role') || $edMe->can('view audit log') || $edMe->can('manage company settings'))
            <div class="ed-side__group">{{ __('الإدارة') }}</div>
            @can('manage role')
                <a class="ed-nav {{ $edActive('roles.*') }}" href="{{ route('roles.index') }}"><i class="ti ti-shield-lock"></i><span>{{ __('الأدوار والصلاحيات') }}</span></a>
            @endcan
            @can('view audit log')
                <a class="ed-nav {{ $edActive('edata.audit.*') }}" href="{{ route('edata.audit.index') }}"><i class="ti ti-history"></i><span>{{ __('سجل الأنشطة') }}</span></a>
            @endcan
            @can('manage company settings')
                <a class="ed-nav {{ $edActive('settings') }}" href="{{ route('settings') }}"><i class="ti ti-settings"></i><span>{{ __('إعدادات النظام') }}</span></a>
            @endcan
        @endif

    </nav>

    <div class="ed-side__foot">
        <button type="button" class="ed-collapse" id="edCollapse" aria-label="{{ __('طي القائمة') }}">
            <i class="ti ti-layout-sidebar-right"></i><span>{{ __('طي القائمة') }}</span>
        </button>
    </div>
</aside>
