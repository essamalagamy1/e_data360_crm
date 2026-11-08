<?php

namespace App\Http\Controllers\Edata;

use App\Models\Notification;

class NotificationController extends BaseController
{
    private function mine()
    {
        // Rows written by the old system have no title in their data and were tied to
        // retired modules, so the centre shows only the ones it can link to.
        return Notification::where('user_id', $this->me()->id)->where('data', 'like', '%"title"%');
    }

    public function index()
    {
        $notifications = $this->mine()->orderByDesc('id')->paginate($this->perPage());

        return view('edata.notifications.index', [
            'notifications' => $notifications,
            'unread' => $this->mine()->where('is_read', 0)->count(),
        ]);
    }

    public function feed()
    {
        $items = $this->mine()->orderByDesc('id')->limit(12)->get()->map(function ($n) {
            $data = json_decode($n->data, true) ?: [];

            return [
                'id' => $n->id,
                'title' => $data['title'] ?? '',
                'body' => $data['body'] ?? '',
                'type' => $n->type,
                'read' => (bool) $n->is_read,
                'when' => $n->created_at?->diffForHumans(),
                'url' => route('edata.notifications.open', $n->id),
            ];
        });

        return response()->json([
            'unread' => $this->mine()->where('is_read', 0)->count(),
            'items' => $items,
        ]);
    }

    public function open(int $notification)
    {
        $row = $this->mine()->findOrFail($notification);
        $row->is_read = 1;
        $row->save();

        $url = (json_decode($row->data, true) ?: [])['url'] ?? null;

        // Only links inside this application are followed.
        if (is_string($url) && str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return redirect($url);
        }

        return redirect()->route('edata.notifications.index');
    }

    public function readAll()
    {
        $this->mine()->where('is_read', 0)->update(['is_read' => 1]);

        if (request()->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return $this->done('تم تعليم كل الإشعارات كمقروءة.');
    }
}
