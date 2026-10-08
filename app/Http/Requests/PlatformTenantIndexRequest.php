<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request for platform admin to index tenants.
 */
class PlatformTenantIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canDo('tenants.settings');
    }

    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'between:1,200'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
