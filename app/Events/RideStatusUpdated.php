<?php

namespace App\Events;

use App\Models\Ride;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

// Phase-1 ships the event + channel auth; connect Reverb/Pusher in phase 2
// (roadmap §05). Driver UI polls until then.
class RideStatusUpdated implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(public Ride $ride) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("tenant.{$this->ride->tenant_id}.rides")];
    }

    public function broadcastWith(): array
    {
        return [
            'ride_id' => $this->ride->id,
            'status' => $this->ride->status->code,
            'scheduled_pickup_at' => $this->ride->scheduled_pickup_at->toIso8601String(),
        ];
    }
}
