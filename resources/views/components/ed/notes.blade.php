@props(['notes', 'type', 'id', 'canAdd' => true])
@if ($canAdd)
    <form method="POST" action="{{ route('edata.notes.store') }}" style="padding:14px 18px;border-bottom:1px solid var(--line);display:grid;gap:8px">
        @csrf
        <input type="hidden" name="notable_type" value="{{ $type }}">
        <input type="hidden" name="notable_id" value="{{ $id }}">
        <textarea class="ed-textarea" name="body" rows="2" required maxlength="3000" placeholder="{{ __('اكتب ملاحظة...') }}" aria-label="{{ __('ملاحظة جديدة') }}"></textarea>
        <div><button class="ed-btn ed-btn--primary ed-btn--sm" type="submit">{{ __('إضافة ملاحظة') }}</button></div>
    </form>
@endif
@forelse ($notes as $note)
    <div class="ed-note">
        <div class="ed-note__meta">
            <span>{{ $note->author->name ?? __('مستخدم') }}، {{ $note->created_at?->diffForHumans() }}</span>
            @if ((int) $note->user_id === (int) \Auth::id() || \App\Support\Edata\Access::isOwner(\Auth::user()))
                <form method="POST" action="{{ route('edata.notes.destroy', $note->id) }}" data-confirm="{{ __('حذف هذه الملاحظة؟') }}">
                    @csrf @method('DELETE')
                    <button class="ed-btn ed-btn--ghost ed-btn--sm" type="submit" aria-label="{{ __('حذف') }}"><i class="ti ti-trash"></i></button>
                </form>
            @endif
        </div>
        <div class="ed-note__body">{{ $note->body }}</div>
    </div>
@empty
    <x-ed.empty small icon="ti-notes" :title="__('لا توجد ملاحظات بعد.')" />
@endforelse
