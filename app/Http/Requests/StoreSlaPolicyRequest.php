<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSlaPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'tier' => ['required', 'string', 'in:standard,silver,gold,platinum'],
            'priority' => ['required', 'string', 'in:low,medium,high,urgent'],
            'first_response_time_minutes' => ['required', 'integer', 'min:5', 'max:525600'],
            'resolution_time_minutes' => ['required', 'integer', 'min:15', 'max:525600', 'gte:first_response_time_minutes'],
            'applies_business_hours_only' => ['boolean'],
        ];
    }
}
