<?php

namespace App\Http\Requests;

use App\Models\Department;
use App\Support\TicketForm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class CreateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organization_id' => ['nullable', 'exists:organizations,id'],
            'department_id' => ['required', 'exists:departments,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:50000'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,urgent'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*.public_id' => ['required', 'string', 'max:512'],
            'attachments.*.resource_type' => ['required', 'string', 'in:image,raw,video'],
            'attachments.*.name' => ['nullable', 'string', 'max:255'],
            'channel' => ['nullable', 'string', 'in:portal,email,api'],
            'custom_fields' => ['nullable', 'array'],
        ];
    }

    /**
     * The department's own questions: required ones must be answered, selects must use a listed option.
     */
    public function after(): array
    {
        return [function ($validator) {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('department_id')) {
                return;
            }
            try {
                $clean = TicketForm::validate(Department::find($this->department_id)?->form_fields, $this->input('custom_fields'));
                $this->merge(['custom_fields' => $clean]);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $field => $messages) {
                    foreach ($messages as $m) {
                        $validator->errors()->add($field, $m);
                    }
                }
            }
        }];
    }
}
