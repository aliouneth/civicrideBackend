<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canDo('rides.create');
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
            'scheduled_pickup_at' => ['required', 'date', 'after:now'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:9'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'price_cents' => ['nullable', 'integer', 'min:0'],

            // Recurrence is all-or-nothing: naming a frequency demands a valid
            // interval and exactly one bound (a count or an end date).
            'recurrence' => ['sometimes', 'array'],
            'recurrence.frequency' => ['required_with:recurrence', Rule::in(['daily', 'weekly', 'monthly'])],
            'recurrence.interval' => ['required_with:recurrence', 'integer', 'min:1', 'max:12'],
            'recurrence.count' => ['required_without:recurrence.until', 'nullable', 'integer', 'min:2', 'max:30'],
            'recurrence.until' => ['required_without:recurrence.count', 'nullable', 'date', 'after_or_equal:scheduled_pickup_at'],
        ];
    }

    public function messages(): array
    {
        return [
            'recurrence.count.required_without' => 'Give the series a number of rides or an end date.',
            'recurrence.until.required_without' => 'Give the series a number of rides or an end date.',
            'recurrence.count.max' => 'A series is capped at '.RecurringRideService::MAX_OCCURRENCES.' rides.',
        ];
    }

    /** Normalised rule, or null when the ride is a one-off. */
    public function recurrenceRule(): ?array
    {
        $rule = $this->validated()['recurrence'] ?? null;

        return $rule ? [
            'frequency' => $rule['frequency'],
            'interval' => (int) $rule['interval'],
            'count' => isset($rule['count']) ? (int) $rule['count'] : null,
            'until' => $rule['until'] ?? null,
        ] : null;
    }
}