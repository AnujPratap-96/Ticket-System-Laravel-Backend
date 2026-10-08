<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:20000'],
            'is_internal_note' => ['nullable', 'boolean'],
            'mentions' => ['nullable', 'array', 'max:10'],
            'mentions.*' => ['integer', 'exists:users,id'],
            'force_send' => ['nullable', 'boolean'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*.public_id' => ['required', 'string', 'max:512'],
            'attachments.*.resource_type' => ['required', 'string', 'in:image,raw,video'],
            'attachments.*.name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
