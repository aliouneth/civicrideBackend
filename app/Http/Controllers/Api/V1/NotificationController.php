<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

/**
 * Per-user inbox. Notifications are written by services (assignment, ride
 * rejection) and read here by the recipient only — never by other users in the
 * same agency.
 */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $limit = min(max((int) $request->query('limit', 20), 1), 50);

        $notifications = Notification::where('user_id', $user->id)
            ->when($request->boolean('unread'), fn ($query) => $query->whereNull('read_at'))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $notifications,
            'unread_count' => Notification::where('user_id', $user->id)->whereNull('read_at')->count(),
        ]);
    }

    public function markRead(Request $request, Notification $notification)
    {
        if ($notification->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Only the recipient can mark a notification as read.'], 403);
        }

        $notification->update(['read_at' => now()]);

        return response()->json($notification->fresh());
    }

    public function markAllRead(Request $request)
    {
        $updated = Notification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['updated' => $updated]);
    }
}
