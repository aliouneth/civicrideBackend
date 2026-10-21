<?php

namespace App\Http\Requests;

use App\Models\CancellationReason;
use Illuminate\Foundation\Http\FormRequest;

class TransitionRideRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Drivers execute rides; schedulers/admins may cancel.
        return $this->user()->canDo('rides.execute') || $this->user()->canDo('rides.cancel');
    }

    public function rules(): array
    {
        return [
            'to_code' => ['required', 'string', 'in:assigned,en_route,picked_up,completed,cancelled,pending'],
            'note' => ['nullable', 'string', 'max:2000'],
            'incident' => ['nullable', 'string', 'max:2000'],
            'reason' => [
                'nullable',
                'string',
                'max:500',
                // When cancelling, the reason must be a valid active cancellation reason code
                function ($attribute, $value, $fail) {
                    if ($this->to_code === 'cancelled' && $value !== null) {
                        $exists = CancellationReason::where('code', $value)
                            ->where('is_active', true)
                            ->exists();
                        if (! $exists) {
                            $fail('The selected cancellation reason is not valid.');
                        }
                    }
                },
            ],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }
}