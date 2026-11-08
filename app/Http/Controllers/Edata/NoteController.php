<?php

namespace App\Http\Controllers\Edata;

use App\Models\EdataNote;
use App\Support\Edata\Access;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NoteController extends BaseController
{
    public function store(Request $request)
    {
        $me = $this->me();

        $data = $request->validate([
            'notable_type' => ['required', Rule::in(['client', 'project'])],
            'notable_id' => 'required|integer',
            'body' => 'required|string|max:3000',
        ], [], ['body' => 'الملاحظة']);

        // Writing a note requires being able to open the record it belongs to.
        if ($data['notable_type'] === 'client') {
            Access::need($me, ['manage customer', 'show customer']);
            Access::customers($me)->findOrFail($data['notable_id']);
        } else {
            Access::need($me, ['manage project', 'view project']);
            Access::projects($me)->findOrFail($data['notable_id']);
        }

        EdataNote::create([
            'company_id' => $this->companyId(),
            'notable_type' => $data['notable_type'],
            'notable_id' => $data['notable_id'],
            'body' => $data['body'],
            'user_id' => $me->id,
        ]);

        return $this->done('تمت إضافة الملاحظة.');
    }

    public function destroy(int $note)
    {
        $me = $this->me();
        $row = EdataNote::where('company_id', $this->companyId())->findOrFail($note);

        abort_unless((int) $row->user_id === (int) $me->id || Access::isOwner($me), 403);

        $row->delete();

        return $this->done('تم حذف الملاحظة.');
    }
}
