{{-- The platform admin and external clients keep the original screens. Everyone else gets the new shell. --}}
@if (in_array(\Auth::user()->type, ['super admin', 'client'], true))
    @include('layouts.admin_classic')
@else
    @include('edata.layouts.shell')
@endif
