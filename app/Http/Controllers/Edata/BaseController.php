<?php

namespace App\Http\Controllers\Edata;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

abstract class BaseController extends Controller
{
    protected function me(): User
    {
        return Auth::user();
    }

    protected function companyId(): int
    {
        return (int) Auth::user()->creatorId();
    }

    protected function perPage(): int
    {
        return (int) config('edata.per_page', 20);
    }

    protected function done(string $message, ?string $url = null)
    {
        return ($url ? redirect($url) : back())->with('success', __($message));
    }

    protected function failed(string $message)
    {
        return back()->withInput()->with('error', __($message));
    }
}
