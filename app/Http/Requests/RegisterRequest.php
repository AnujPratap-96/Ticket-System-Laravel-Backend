<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Public registration only creates customers. Role, org and department are not accepted.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', \App\Tenancy\TenantRule::unique('users', 'email')],
            'password' => ['required', 'string', \Illuminate\Validation\Rules\Password::default(), 'max:128', 'confirmed'],
        ];
    }
}
