<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Drivers edit their own windows; schedulers/admins may edit any.
        return $this->user()->isRole('driver', 'scheduler', 'admin', 'supervisor');
    }

    public function rules(): array
    {
        return [
            'windows' => ['required', 'array', 'min:1'],
            'windows.*.weekday' => ['required', 'integer', 'between:0,6'],
            'windows.*.start_time' => ['required', 'date_format:H:i'],
            'windows.*.end_time' => ['required', 'date_format:H:i', 'after:windows.*.start_time'],
        ];
    }
}
