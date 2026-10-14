<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Support\TicketForm;
use Illuminate\Validation\Rule;

class DepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('department')?->id;

        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:150', 'regex:/^[a-z0-9-]+$/', Rule::unique('departments', 'slug')->where('tenant_id', \App\Tenancy\TenantContext::id())->ignore($id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'business_hours_start' => ['required', 'date_format:H:i'],
            'business_hours_end' => ['required', 'date_format:H:i', 'after:business_hours_start'],
            'timezone' => ['required', 'timezone:all'],
            // Which weekdays the team works (1 = Monday ... 7 = Sunday); at least one
            'working_days' => ['sometimes', 'array', 'min:1', 'max:7'],
            'working_days.*' => ['integer', 'between:1,7', 'distinct'],
        ] + TicketForm::definitionRules();
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($msg = TicketForm::checkDefinitions((array) $this->input('form_fields', []))) {
                $validator->errors()->add('form_fields', $msg);
            }
        }];
    }
}
