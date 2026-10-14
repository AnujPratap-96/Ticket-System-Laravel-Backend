<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MergeTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_ticket_id' => ['required', 'integer', \App\Tenancy\TenantRule::exists('tickets'), Rule::notIn([$this->route('ticket')?->id])],
        ];
    }
}
