<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRoutingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'max_active_tickets' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'is_available_for_routing' => ['sometimes', 'boolean'],
        ];
    }
}
