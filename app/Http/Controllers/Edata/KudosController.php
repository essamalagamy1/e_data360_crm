<?php

namespace App\Http\Controllers\Edata;

use App\Models\EdataKudos;
use App\Models\User;
use App\Support\Edata\Access;
use App\Support\Edata\Moments;
use App\Support\Edata\Notifier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Colleagues thanking each other. Stays inside the system: nothing is sent outside it. */
class KudosController extends BaseController
{
    public function store(Request $request)
    {
        $me = $this->me();
        $company = $this->companyId();

        $data = $request->validate([
            'to_user_id' => ['required', 'integer', Rule::in(Moments::teammates($company, $me->id)->pluck('id')->all())],
            'message' => 'required|string|min:3|max:200',
        ], [], ['to_user_id' => 'الزميل', 'message' => 'كلمة الشكر']);

        $sentToday = EdataKudos::where('from_user_id', $me->id)->where('created_at', '>=', now()->startOfDay())->count();
        if ($sentToday >= (int) config('edata_moments.kudos_daily_limit', 10)) {
            return $this->failed('وصلت إلى الحد اليومي لكلمات الشكر. جرّب غداً.');
        }

        EdataKudos::create([
            'company_id' => $company,
            'from_user_id' => $me->id,
            'to_user_id' => (int) $data['to_user_id'],
            'message' => trim($data['message']),
        ]);

        Notifier::send((int) $data['to_user_id'], 'kudos', 'كلمة شكر من ' . $me->name, trim($data['message']), route('edata.me', [], false) . '#kudos');

        return back()->with('success', __('وصلت كلمة الشكر إلى :name.', ['name' => User::find($data['to_user_id'])->name ?? '']))->with('ed_cheer', 1);
    }

    /** The writer can take a note back, and so can the company owner. */
    public function destroy(int $kudos)
    {
        $me = $this->me();
        $row = EdataKudos::where('company_id', $this->companyId())->findOrFail($kudos);

        abort_unless((int) $row->from_user_id === (int) $me->id || Access::isOwner($me), 403);

        $row->delete();

        return $this->done('تم حذف كلمة الشكر.');
    }
}
