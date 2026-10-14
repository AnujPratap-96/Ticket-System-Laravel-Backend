<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', 'string', 'in:customer,agent,lead,admin'],
            'department_id' => ['sometimes', 'nullable', \App\Tenancy\TenantRule::exists('departments')],
            'organization_id' => ['sometimes', 'nullable', \App\Tenancy\TenantRule::exists('organizations')],
            'max_active_tickets' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'is_available_for_routing' => ['sometimes', 'boolean'],
            'away_until' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
