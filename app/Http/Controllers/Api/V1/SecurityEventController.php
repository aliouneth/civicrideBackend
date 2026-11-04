<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Read-only view of the security audit trail (HIPAA §164.312(b),
 * SOC 2 CC7.2). Gated by the security.view permission; rows are scoped to the
 * request tenant by the SecurityEvent global scope (platform admins send no
 * tenant claim, so they see the whole trail).
 */
class SecurityEventController extends Controller
{
    public function index(Request $request)
    {
        abort_if(! $request->user()->canDo('security.view'), 403);

        $data = $request->validate([
            'event' => ['sometimes', 'string', 'max:64'],
            'actor_user_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $query = SecurityEvent::with('actor:id,name,email')->orderByDesc('id');

        if (isset($data['event'])) {
            $query->where('event', $data['event']);
        }

        if (isset($data['actor_user_id'])) {
            $query->where('actor_user_id', $data['actor_user_id']);
        }

        if (isset($data['from'])) {
            $query->where('created_at', '>=', $data['from']);
        }

        if (isset($data['to'])) {
            $query->where('created_at', '<', Carbon::parse($data['to'])->addDay());
        }

        return response()->json($query->paginate(25));
    }
}
