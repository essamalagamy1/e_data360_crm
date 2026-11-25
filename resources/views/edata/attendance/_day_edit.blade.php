{{-- Dialog that changes the state of a day without a clock-in. Opened by any [data-day-edit] button. --}}
@php
    $edDayTypes = \Illuminate\Support\Facades\DB::table('leave_types')->where('created_by', auth()->user()->creatorId())->orderBy('title')->get(['id', 'title']);
    $edDayRules = \App\Support\Edata\AttendanceService::for((int) auth()->user()->creatorId())->rules();
@endphp
<dialog class="ed-dialog" id="dlgDay">
    <form method="POST" action="{{ route('edata.attendance.day') }}">
        @csrf
        <input type="hidden" name="employee_id" id="dayEmployee">
        <input type="hidden" name="date" id="dayDate">
        <div class="ed-dialog__body">
            <h3>{{ __('تعديل حالة اليوم') }}</h3>
            <p id="dlgDayTitle"></p>
            <div class="ed-form" style="margin-top:12px">
                <x-ed.field :label="__('الحالة الجديدة')" required full for="dayState">
                    <select class="ed-select" name="state" id="dayState" required>
                        <option value="present">{{ __('حضور') }}</option>
                        <option value="leave">{{ __('إجازة (تُخصم من رصيده)') }}</option>
                        <option value="excused">{{ __('غياب بعذر (لا يُحسب غياباً)') }}</option>
                        <option value="day_off">{{ __('راحة') }}</option>
                        <option value="absent">{{ __('غياب') }}</option>
                        <option value="auto">{{ __('إلغاء التعديل والرجوع للحالة الأصلية') }}</option>
                    </select>
                </x-ed.field>
                <div class="ed-field" data-day-pane="present"><label class="ed-label" for="dayIn">{{ __('وقت الدخول') }}<em aria-hidden="true">*</em></label><input class="ed-input" type="time" name="clock_in" id="dayIn" value="{{ substr((string) $edDayRules['start'], 0, 5) }}"></div>
                <div class="ed-field" data-day-pane="present"><label class="ed-label" for="dayOut">{{ __('وقت الخروج') }}</label><input class="ed-input" type="time" name="clock_out" id="dayOut" value="{{ substr((string) $edDayRules['end'], 0, 5) }}"></div>
                <div class="ed-field ed-field--full" data-day-pane="leave" hidden><label class="ed-label" for="dayLeaveType">{{ __('نوع الإجازة') }}<em aria-hidden="true">*</em></label>
                    <select class="ed-select" name="leave_type_id" id="dayLeaveType">
                        @foreach ($edDayTypes as $type)<option value="{{ $type->id }}">{{ $type->title }}</option>@endforeach
                    </select>
                </div>
                <x-ed.field :label="__('سبب التعديل')" required full for="dayReason" :hint="__('يُحفظ في سجل الأنشطة، ويصل للموظف إشعار بالتعديل.')">
                    <textarea class="ed-textarea" name="reason" id="dayReason" rows="2" required minlength="3" maxlength="400"></textarea>
                </x-ed.field>
            </div>
        </div>
        <div class="ed-dialog__foot">
            <button type="button" class="ed-btn" onclick="this.closest('dialog').close()">{{ __('إلغاء') }}</button>
            <button type="submit" class="ed-btn ed-btn--primary">{{ __('حفظ التعديل') }}</button>
        </div>
    </form>
</dialog>

@push('edata-scripts')
<script>
(function () {
    var dialog = document.getElementById('dlgDay');
    var state = document.getElementById('dayState');
    function panes() {
        dialog.querySelectorAll('[data-day-pane]').forEach(function (pane) {
            var on = pane.dataset.dayPane === state.value;
            pane.hidden = !on;
            pane.querySelectorAll('input, select').forEach(function (input) { input.disabled = !on; });
        });
        document.getElementById('dayIn').required = state.value === 'present';
    }
    state.addEventListener('change', panes);
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-day-edit]');
        if (!button) { return; }
        document.getElementById('dayEmployee').value = button.dataset.employee;
        document.getElementById('dayDate').value = button.dataset.date;
        document.getElementById('dlgDayTitle').textContent = button.dataset.title;
        document.getElementById('dayReason').value = '';
        state.value = button.dataset.state === 'absent' ? 'present' : 'absent';
        panes();
        dialog.showModal();
    });
})();
</script>
@endpush
