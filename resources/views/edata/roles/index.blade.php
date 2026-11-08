@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', __('الأدوار والصلاحيات'))

@section('content')
<x-ed.head :title="__('الأدوار والصلاحيات')" :sub="__('كل موظف يرى ويعمل بحسب دوره، والتحقق يتم على الخادم وليس في الواجهة فقط.')">
    @if ($canCreate)
        <a class="ed-btn ed-btn--primary" href="{{ route('roles.create') }}"><i class="ti ti-plus"></i>{{ __('إضافة دور') }}</a>
    @endif
</x-ed.head>

<section class="ed-panel ed-reveal">
    @if ($roles->isEmpty())
        <x-ed.empty icon="ti-shield" :title="__('لا توجد أدوار بعد.')">
            @if ($canCreate)<a class="ed-btn ed-btn--primary" href="{{ route('roles.create') }}">{{ __('إضافة دور') }}</a>@endif
        </x-ed.empty>
    @else
        <div class="ed-table-wrap">
            <table class="ed-table">
                <thead><tr><th>{{ __('الدور') }}</th><th>{{ __('عدد الموظفين') }}</th><th>{{ __('عدد الصلاحيات') }}</th><th></th></tr></thead>
                <tbody>
                    @foreach ($roles as $role)
                        <tr>
                            <td><span class="ed-table__title">{{ $role->name }}</span></td>
                            <td class="ed-num">{{ $counts[$role->name] ?? 0 }}</td>
                            <td class="ed-num">{{ $role->permissions_count }}</td>
                            <td class="is-end is-tight">
                                <span class="ed-actions">
                                    @if ($canEdit)
                                        <a class="ed-btn ed-btn--sm" href="{{ route('roles.edit', $role->id) }}"><i class="ti ti-pencil"></i>{{ __('تعديل') }}</a>
                                    @endif
                                    @if ($canDelete && empty($counts[$role->name]))
                                        <form method="POST" action="{{ route('roles.destroy', $role->id) }}" data-confirm="{{ __('حذف هذا الدور؟') }}">
                                            @csrf @method('DELETE')
                                            <button class="ed-btn ed-btn--ghost ed-btn--sm" type="submit" aria-label="{{ __('حذف') }}"><i class="ti ti-trash"></i></button>
                                        </form>
                                    @endif
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endsection
