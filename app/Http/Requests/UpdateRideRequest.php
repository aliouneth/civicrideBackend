<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Correcting an existing booking. Deliberately narrower than StoreRideRequest:
 * status, series membership and assignments are not editable here (they go
 * through the lifecycle and assignment endpoints, which keep audit rows).
 */
class UpdateRideRequest extends FormRequest
{
    /** A ride may only be corrected while it has not started moving. */
    public const EDITABLE_STATUSES = ['pending', 'assigned'];

    public function authorize(): bool
    {
        return $this->user()->canDo('rides.update');
    }

    public function rules(): array
    {
        return [
            'passenger_info.name' => ['required', 'string', 'max:255'],
            'passenger_info.phone' => ['required', 'string', 'max:32'],
            'passenger_info.passengers' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'passenger_info.wheelchair' => ['sometimes', 'boolean'],
            'passenger_info.child_seat' => ['sometimes', 'boolean'],
            'pickup_address' => ['required', 'string', 'max:500'],
            'pickup_lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['required', 'numeric', 'between:-180,180'],
            'dropoff_address' => ['required', 'string', 'max:500'],
            'dropoff_lat' => ['required', 'numeric', 'between:-90,90'],
            'dropoff_lng' => ['required', 'numeric', 'between:-180,180'],
            // Unlike create, a correction may legitimately move a late ride, so
            // only the date type is enforced here.
            'scheduled_pickup_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'price_cents' => ['nullable', 'integer', 'min:0'],
        ];
    }
}