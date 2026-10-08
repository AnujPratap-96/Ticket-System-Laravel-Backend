<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SignUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Omit when attaching files to a ticket that does not exist yet.
            'ticket_id' => ['nullable', 'integer', 'exists:tickets,id'],
        ];
    }
}
