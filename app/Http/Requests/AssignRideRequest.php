<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignRideRequest extends FormRequest
{
    public function authorize(): bool
    {
        // schedulers/admins/owners assign manually; drivers only via self-assign route.
        return $this->user()->canDo('rides.assign');
    }

    public function rules(): array
    {
        return [
            // exists() is tenant-scoped: a foreign-tenant driver id fails validation.
            'driver_profile_id' => [
                'required', 'integer',
                Rule::exists('driver_profiles', 'id')->where('tenant_id', app()->bound('tenant') ? app('tenant')->id : null),
            ],
        ];
    }
}
