<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', \App\Tenancy\TenantRule::unique('users', 'email')],
            // Omit the password to send an email invitation (recommended): the person chooses their own.
            'password' => ['nullable', 'string', \Illuminate\Validation\Rules\Password::default()],
            'role' => ['required', 'string', 'in:customer,agent,lead,admin'],
            'organization_id' => ['nullable', \App\Tenancy\TenantRule::exists('organizations')],
            'department_id' => ['nullable', 'required_if:role,agent,lead', \App\Tenancy\TenantRule::exists('departments')],
            'max_active_tickets' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
