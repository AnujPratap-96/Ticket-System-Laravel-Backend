<?php

namespace App\Http\Requests;

use App\Enums\SlaTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * "https://www.Acme.com/" -> "acme.com": customers are matched to a company by the domain of their email.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->domain)) {
            $d = strtolower(trim($this->domain));
            $d = preg_replace('#^https?://#', '', $d);
            $d = preg_replace('#^www\.#', '', $d);
            $this->merge(['domain' => rtrim(explode('/', $d)[0], '.')]);
        }
    }

    public function rules(): array
    {
        $id = $this->route('organization')?->id;

        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'domain' => ['required', 'string', 'max:255', 'regex:/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/', Rule::unique('organizations', 'domain')->ignore($id)],
            'sla_tier' => ['required', Rule::enum(SlaTier::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['domain.regex' => 'Enter a domain like acme.com.', 'domain.unique' => 'Another organization already uses this domain.'];
    }
}
