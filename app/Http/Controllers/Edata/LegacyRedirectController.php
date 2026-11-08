<?php

namespace App\Http\Controllers\Edata;

use Illuminate\Support\Facades\Crypt;

/**
 * Old URLs that may be bookmarked or linked from screens not rebuilt yet.
 */
class LegacyRedirectController extends BaseController
{
    public function attendance()
    {
        return redirect()->route('edata.attendance.index');
    }

    public function people()
    {
        return redirect()->route('edata.people.index');
    }

    public function roles()
    {
        return redirect()->route('roles.index');
    }

    public function clients()
    {
        return redirect()->route('edata.clients.index');
    }

    public function client($customer)
    {
        return redirect()->route('edata.clients.show', $this->decode($customer));
    }

    public function projects()
    {
        return redirect()->route('projects.index');
    }

    public function project($project)
    {
        return redirect()->route('projects.show', (int) $project);
    }

    public function tasks()
    {
        return redirect()->route('edata.tasks.index', ['view' => 'board']);
    }

    public function invoices()
    {
        return redirect()->route('edata.invoices.index');
    }

    public function invoice($invoice)
    {
        return redirect()->route('edata.invoices.show', $this->decode($invoice));
    }

    public function invoiceEdit($invoice)
    {
        return redirect()->route('edata.invoices.edit', $this->decode($invoice));
    }

    public function products()
    {
        return redirect()->route('edata.products.index');
    }

    /** Old links carry an encrypted id, new ones a plain number. */
    private function decode($value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        try {
            return (int) Crypt::decrypt($value);
        } catch (\Throwable $e) {
            abort(404);
        }
    }
}
