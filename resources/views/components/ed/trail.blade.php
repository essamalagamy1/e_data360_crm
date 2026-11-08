@props(['logs', 'withDiff' => true])
@php
    use App\Support\Edata\Labels;
    $zone = \App\Support\Edata\Fmt::timezone((int) \Auth::user()->creatorId());
    $entities = config('edata.audit_entities');
@endphp
@if ($logs->isEmpty())
    <x-ed.empty small icon="ti-history" :title="__('لا توجد أنشطة مسجلة بعد.')" />
@else
    <ul class="ed-trail">
        @foreach ($logs as $log)
            @php
                $when = \Carbon\Carbon::parse($log->created_at, 'UTC')->setTimezone($zone);
                $action = Labels::auditAction($log->action);
            @endphp
            <li>
                <x-ed.avatar :name="$log->user_name" size="sm" />
                <div class="ed-trail__body">
                    <div class="ed-trail__line">
                        <b>{{ $log->user_name }}</b>
                        <x-ed.badge :pair="$action" />
                        {{ __($entities[$log->entity_type] ?? $log->entity_type) }}:
                        <span>{{ $log->entity_label }}</span>
                    </div>
                    <div class="ed-trail__time">{{ $when->locale(app()->getLocale())->translatedFormat('j M Y، H:i') }}</div>
                    @if ($withDiff && $log->action !== 'created' && ($log->new_values || $log->old_values))
                        <div class="ed-trail__diff">
                            @foreach (array_keys(array_merge((array) $log->old_values, (array) $log->new_values)) as $field)
                                @php
                                    $old = data_get($log->old_values, $field);
                                    $new = data_get($log->new_values, $field);
                                    $show = fn ($v) => is_array($v) ? implode('، ', $v) : (string) $v;
                                @endphp
                                <div>
                                    {{ __(Labels::field($field)) }}:
                                    @if ($old !== null && $show($old) !== '')<s>{{ \Illuminate\Support\Str::limit($show($old), 60) }}</s>@endif
                                    @if ($new !== null && $show($new) !== '') <ins>{{ \Illuminate\Support\Str::limit($show($new), 60) }}</ins>@endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @if ($log->note)<div class="ed-trail__note">{{ __('السبب') }}: {{ $log->note }}</div>@endif
                </div>
            </li>
        @endforeach
    </ul>
@endif
