<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'passenger_info.additional_riders' => ['nullable', 'integer', 'min:0', 'max:30'],
            'passenger_info.destination_phone' => ['nullable', 'string', 'max:32'],
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
            'trip_type' => ['sometimes', 'string', Rule::in(['one_way', 'roundtrip'])],
            'return_pickup_at' => ['nullable', 'date', 'after:scheduled_pickup_at'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            /** @var \App\Models\Ride|null $ride */
            $ride = $this->route('ride');
            if (! $ride) {
                return;
            }

            $payload = $this->all();

            // A return leg is steered from its outbound ride, not on its own.
            if ($ride->outboundRide()->exists()) {
                if (array_key_exists('trip_type', $payload)) {
                    $validator->errors()->add('trip_type', 'A return leg cannot change trip type — edit the outbound ride instead.');
                }
                if (array_key_exists('return_pickup_at', $payload) && $payload['return_pickup_at'] !== null) {
                    $validator->errors()->add('return_pickup_at', 'A return leg has no return of its own — set the time on the outbound ride.');
                }

                return;
            }

            if (($payload['trip_type'] ?? null) === 'one_way' && $ride->return_ride_id !== null) {
                $validator->errors()->add('trip_type', "This ride already has return trip #{$ride->return_ride_id} — cancel it before making the ride one-way.");
            }

            if (($payload['trip_type'] ?? null) === 'roundtrip'
                && empty($payload['return_pickup_at']) && ! $validator->errors()->has('return_pickup_at')) {
                $validator->errors()->add('return_pickup_at', 'A roundtrip ride needs a return time.');
            }

            if (($payload['trip_type'] ?? null) === 'one_way'
                && array_key_exists('return_pickup_at', $payload) && $payload['return_pickup_at'] !== null) {
                $validator->errors()->add('return_pickup_at', 'A return time needs a roundtrip ride.');
            }

            // Clearing the return time while the return leg exists would orphan it.
            if ($ride->return_ride_id !== null
                && array_key_exists('return_pickup_at', $payload) && $payload['return_pickup_at'] === null) {
                $validator->errors()->add('return_pickup_at', "This ride already has return trip #{$ride->return_ride_id} — cancel it before clearing the return time.");
            }
        }];
    }
}