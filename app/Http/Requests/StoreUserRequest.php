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
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            // Omit the password to send an email invitation (recommended): the person chooses their own.
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['required', 'string', 'in:customer,agent,lead,admin'],
            'organization_id' => ['nullable', 'exists:organizations,id'],
            'department_id' => ['nullable', 'required_if:role,agent,lead', 'exists:departments,id'],
            'max_active_tickets' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
