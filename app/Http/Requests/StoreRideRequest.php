<?php

namespace App\Http\Requests;

use App\Services\RecurringRideService;
use Illuminate\Contracts\Validation\Validator;
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

            // Leaf types only. The all-or-nothing shape of `recurrence` is
            // enforced in after(): required_without on count/until would also
            // fire for a plain one-off ride, which has no recurrence at all.
            'recurrence' => ['sometimes', 'nullable', 'array'],
            'recurrence.label' => ['nullable', 'string', 'max:120'],
            'recurrence.frequency' => ['nullable', Rule::in(['daily', 'weekly', 'monthly'])],
            'recurrence.interval' => ['nullable', 'integer', 'min:1', 'max:12'],
            'recurrence.count' => ['nullable', 'integer', 'min:2', 'max:'.RecurringRideService::MAX_OCCURRENCES],
            'recurrence.until' => ['nullable', 'date', 'after_or_equal:scheduled_pickup_at'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $recurrence = $this->input('recurrence');
            if (! is_array($recurrence) || $recurrence === []) {
                return; // one-off ride — nothing to check
            }

            // Only speak up about a field the declarative rules let through, so a
            // missing value reports once rather than twice.
            if (empty($recurrence['frequency']) && ! $validator->errors()->has('recurrence.frequency')) {
                $validator->errors()->add('recurrence.frequency', 'Choose how often this ride repeats.');
            }
            if (empty($recurrence['interval']) && ! $validator->errors()->has('recurrence.interval')) {
                $validator->errors()->add('recurrence.interval', 'Enter a repeat interval of 1 or more.');
            }
            if (empty($recurrence['count']) && empty($recurrence['until'])) {
                $validator->errors()->add('recurrence.count', 'Give the series a number of rides or an end date.');
            }
        }];
    }

    /** Normalised recurrence rule, or null when the ride is a one-off. */
    public function recurrenceRule(): ?array
    {
        $recurrence = $this->validated()['recurrence'] ?? null;
        if (! is_array($recurrence) || empty($recurrence['frequency'])) {
            return null;
        }

        return [
            'frequency' => $recurrence['frequency'],
            'interval' => (int) $recurrence['interval'],
            'count' => isset($recurrence['count']) ? (int) $recurrence['count'] : null,
            'until' => $recurrence['until'] ?? null,
            'label' => $recurrence['label'] ?? null,
        ];
    }
}