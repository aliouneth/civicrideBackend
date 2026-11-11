<?php

namespace App\Http\Requests;

use App\Services\RecurringRideService;
use App\Support\UserTimezone;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class StoreRideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canDo('rides.create');
    }

    /**
     * A datetime-local value is the requester's wall clock: convert it to the
     * UTC instant every ride is stored as. Explicit offsets pass through.
     */
    protected function prepareForValidation(): void
    {
        $timezone = UserTimezone::fromRequest($this);
        $normalized = [];

        foreach (['scheduled_pickup_at', 'return_pickup_at'] as $field) {
            if ($this->filled($field)) {
                $normalized[$field] = UserTimezone::toUtc($this->input($field), $timezone);
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function rules(): array
    {
        // The earliest bookable pickup is today's midnight on the requester's
        // clock, converted to UTC for comparison against stored instants.
        $timezone = UserTimezone::fromRequest($this);
        [$dayStart] = UserTimezone::dayBounds($timezone);
        $dayStart = $dayStart->toDateTimeString();

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
            'scheduled_pickup_at' => ['required', 'date', 'after_or_equal:'.$dayStart],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:9'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'price_cents' => ['nullable', 'integer', 'min:0'],

            // Roundtrip: a return time is required; split_return decides whether
            // the return becomes its own ride (swapped route) or stays on this one.
            'trip_type' => ['sometimes', 'string', Rule::in(['one_way', 'roundtrip'])],
            'return_pickup_at' => ['nullable', 'date', 'after:scheduled_pickup_at', 'required_if:trip_type,roundtrip'],
            'split_return' => ['sometimes', 'boolean'],

            // Leaf types only. The all-or-nothing shape of `recurrence` is
            // enforced in after(): required_without on count/until would also
            // fire for a plain one-off ride, which has no recurrence at all.
            'recurrence' => ['sometimes', 'nullable', 'array'],
            'recurrence.label' => ['nullable', 'string', 'max:120'],
            'recurrence.frequency' => ['nullable', Rule::in(['daily', 'weekly', 'monthly'])],
            'recurrence.interval' => ['nullable', 'integer', 'min:1', 'max:12'],
            'recurrence.count' => ['nullable', 'integer', 'min:2', 'max:'.RecurringRideService::MAX_OCCURRENCES],
            // The end date is a calendar date in the requester's zone; the
            // relation to the first pickup is checked in after() below.
            'recurrence.until' => ['nullable', 'date'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->boolean('split_return') && $this->input('trip_type') !== 'roundtrip') {
                $validator->errors()->add('split_return', 'Only a roundtrip ride can be split into a return trip.');
            }

            if ($this->boolean('split_return') && is_array($this->input('recurrence')) && $this->input('recurrence') !== []) {
                $validator->errors()->add('split_return', 'A recurring roundtrip cannot be split yet — create the return as a separate booking.');
            }

            if ($this->input('trip_type') !== 'roundtrip' && $this->filled('return_pickup_at')) {
                $validator->errors()->add('return_pickup_at', 'A return time needs a roundtrip ride.');
            }

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

            // Compare calendar dates on the requester's clock: an 8pm pickup
            // is still on the same local day as an "until" of that day.
            if (! empty($recurrence['until']) && $this->filled('scheduled_pickup_at')) {
                $zone = UserTimezone::fromRequest($this);
                $firstDay = Carbon::parse((string) $this->input('scheduled_pickup_at'))->setTimezone($zone)->toDateString();
                $lastDay = Carbon::parse((string) $recurrence['until'], $zone)->toDateString();

                if ($lastDay < $firstDay && ! $validator->errors()->has('recurrence.until')) {
                    $validator->errors()->add('recurrence.until', 'The series cannot end before its first ride.');
                }
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