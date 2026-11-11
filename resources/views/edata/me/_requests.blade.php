@php
    use App\Support\Edata\Labels;
    $reqKinds = ['leave' => __('إجازة'), 'permission' => __('إذن'), 'correction' => __('تصحيح حضور')];
    $reqStates = ['pending' => [__('قيد المراجعة'), 'warn'], 'approved' => [__('تمت الموافقة'), 'good'], 'rejected' => [__('مرفوض'), 'bad']];
    $oldKind = old('req_kind', 'leave');
@endphp
<section class="ed-panel" id="requests">
    <div class="ed-panel__head"><div><h2>{{ __('طلباتي') }}</h2><p>{{ __('إجازة، إذن تأخير أو خروج، أو تصحيح يوم حضور.') }}</p></div></div>

    <div class="ed-req">
        <div class="ed-chips" role="radiogroup" aria-label="{{ __('نوع الطلب') }}">
            @foreach ($reqKinds as $value => $label)
                <label class="ed-chip"><input type="radio" name="ed_req_kind" value="{{ $value }}" @checked($oldKind === $value)><span>{{ $label }}</span></label>
            @endforeach
        </div>

        <form method="POST" action="{{ route('edata.my.leave') }}" class="ed-form ed-req__pane" data-pane="leave">
            @csrf
            <input type="hidden" name="req_kind" value="leave">
            <x-ed.field :label="__('نوع الإجازة')" name="leave_type_id" required full>
                <select class="ed-select" id="leave_type_id" name="leave_type_id" required>
                    @foreach ($leaveBalances as $type)
                        <option value="{{ $type->id }}" @selected((int) old('leave_type_id') === (int) $type->id)>{{ $type->title }}@if ($type->left !== null) ({{ __('المتبقي') }}: {{ rtrim(rtrim(number_format($type->left, 1), '0'), '.') }} {{ __('يوم') }})@endif</option>
                    @endforeach
                </select>
            </x-ed.field>
            <x-ed.field :label="__('من')" name="start_date" required>
                <input class="ed-input" type="date" id="start_date" name="start_date" value="{{ old('start_date', $todayDate) }}" required>
            </x-ed.field>
            <x-ed.field :label="__('إلى')" name="end_date" required>
                <input class="ed-input" type="date" id="end_date" name="end_date" value="{{ old('end_date', $todayDate) }}" required>
            </x-ed.field>
            <x-ed.field :label="__('السبب')" name="reason" required full>
                <input class="ed-input" id="reason" name="reason" maxlength="250" value="{{ old('req_kind') === 'leave' ? old('reason') : '' }}" required>
            </x-ed.field>
            <div class="ed-field--full"><button type="submit" class="ed-btn ed-btn--primary ed-btn--sm"><i class="ti ti-send"></i>{{ __('إرسال الطلب') }}</button></div>
        </form>

        <form method="POST" action="{{ route('edata.my.permission') }}" class="ed-form ed-req__pane" data-pane="permission">
            @csrf
            <input type="hidden" name="req_kind" value="permission">
            <x-ed.field :label="__('التاريخ')" name="date" required full>
                <input class="ed-input" type="date" name="date" value="{{ old('req_kind') === 'permission' ? old('date') : $todayDate }}" required>
            </x-ed.field>
            <x-ed.field :label="__('من الساعة')" name="time_from" required>
                <input class="ed-input" type="time" name="time_from" value="{{ old('req_kind') === 'permission' ? old('time_from') : '' }}" required>
            </x-ed.field>
            <x-ed.field :label="__('إلى الساعة')" name="time_to" required>
                <input class="ed-input" type="time" name="time_to" value="{{ old('req_kind') === 'permission' ? old('time_to') : '' }}" required>
            </x-ed.field>
            <x-ed.field :label="__('السبب')" name="reason" required full :hint="__('عند الموافقة لا يُحسب تأخير في هذا اليوم.')">
                <input class="ed-input" name="reason" maxlength="400" value="{{ old('req_kind') === 'permission' ? old('reason') : '' }}" required>
            </x-ed.field>
            <div class="ed-field--full"><button type="submit" class="ed-btn ed-btn--primary ed-btn--sm"><i class="ti ti-send"></i>{{ __('إرسال الطلب') }}</button></div>
        </form>

        <form method="POST" action="{{ route('edata.my.correction') }}" class="ed-form ed-req__pane" data-pane="correction">
            @csrf
            <input type="hidden" name="req_kind" value="correction">
            <x-ed.field :label="__('اليوم المطلوب تصحيحه')" name="date" required full>
                <input class="ed-input" type="date" name="date" max="{{ $todayDate }}" value="{{ old('req_kind') === 'correction' ? old('date') : '' }}" required>
            </x-ed.field>
            <x-ed.field :label="__('وقت الدخول الصحيح')" name="time_from" required>
                <input class="ed-input" type="time" name="time_from" value="{{ old('req_kind') === 'correction' ? old('time_from') : '' }}" required>
            </x-ed.field>
            <x-ed.field :label="__('وقت الخروج الصحيح')" name="time_to">
                <input class="ed-input" type="time" name="time_to" value="{{ old('req_kind') === 'correction' ? old('time_to') : '' }}">
            </x-ed.field>
            <x-ed.field :label="__('السبب')" name="reason" required full :hint="__('مثال: نسيت تسجيل الانصراف. يُعدَّل اليوم بعد موافقة الإدارة ويُحفظ في السجل.')">
                <input class="ed-input" name="reason" maxlength="400" value="{{ old('req_kind') === 'correction' ? old('reason') : '' }}" required>
            </x-ed.field>
            <div class="ed-field--full"><button type="submit" class="ed-btn ed-btn--primary ed-btn--sm"><i class="ti ti-send"></i>{{ __('إرسال الطلب') }}</button></div>
        </form>
    </div>

    @php $limited = $leaveBalances->filter(fn ($t) => $t->left !== null); @endphp
    @if ($limited->isNotEmpty())
        <div class="ed-balances">
            @foreach ($limited as $type)
                <div class="ed-balance">
                    <span>{{ $type->title }}</span>
                    <b class="ed-num">{{ rtrim(rtrim(number_format($type->left, 1), '0'), '.') }}</b>
                    <small>{{ __('من') }} {{ $type->days }} {{ __('يوم متبقٍ هذا العام') }}</small>
                </div>
            @endforeach
        </div>
    @endif

    @if ($myRequests->isNotEmpty())
        <div class="ed-list ed-reqlist">
            @foreach ($myRequests as $req)
                <div class="ed-reqlist__row">
                    <div class="ed-list__main">
                        <span class="ed-list__title">{{ $reqKinds[$req->kind] ?? $req->kind }}، <span class="ed-num">{{ Labels::date($req->date, 'j M') }}@if ($req->until && $req->until !== $req->date) - {{ Labels::date($req->until, 'j M') }}@endif</span>@if ($req->from) <span class="ed-num">({{ substr($req->from, 0, 5) }}@if ($req->to) - {{ substr($req->to, 0, 5) }}@endif)</span>@endif</span>
                        <span class="ed-list__sub">{{ $req->reason }}@if ($req->note) — {{ __('رد الإدارة') }}: {{ $req->note }}@endif</span>
                    </div>
                    <x-ed.badge :pair="$reqStates[$req->status] ?? $reqStates['pending']" style="flex:none" />
                </div>
            @endforeach
        </div>
    @endif
</section>
