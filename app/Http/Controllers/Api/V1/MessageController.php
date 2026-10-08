<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Message;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function index(Request $request)
    {
        $messages = Message::with(['sender', 'recipient'])
            ->when($request->ride_id, fn ($q, $id) => $q->where('ride_id', $id))
            ->when($request->with_user_id, fn ($q, $id) => $q->where(function ($w) use ($id): void {
                $me = request()->user()->id;
                $w->where(fn ($a) => $a->where('sender_id', $me)->where('recipient_id', $id))
                  ->orWhere(fn ($b) => $b->where('sender_id', $id)->where('recipient_id', $me));
            }))
            ->orderBy('id')
            ->cursorPaginate(50);

        return response()->json($messages);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'recipient_id' => ['required', 'integer', 'exists:users,id'],
            'ride_id' => ['nullable', 'integer', 'exists:rides,id'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        // Recipient must belong to the same tenant (exists() alone can't check that).
        $recipient = \App\Models\User::findOrFail($data['recipient_id']);
        if ($recipient->tenant_id !== app('tenant')->id) {
            return response()->json(['message' => 'Recipient belongs to a different agency.'], 422);
        }

        $message = Message::create([
            'sender_id' => $request->user()->id,
            'recipient_id' => $recipient->id,
            'ride_id' => $data['ride_id'] ?? null,
            'body' => $data['body'],
        ]);

        return response()->json($message->fresh(['sender', 'recipient']), 201);
    }

    public function markRead(Message $message)
    {
        if ($message->recipient_id !== request()->user()->id) {
            return response()->json(['message' => 'Only the recipient can mark as read.'], 403);
        }

        $message->markRead();

        return response()->json($message->fresh());
    }
}
