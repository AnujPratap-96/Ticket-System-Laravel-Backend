<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'holiday_date' => ['required', 'date_format:Y-m-d', 'unique:business_holidays,holiday_date'],
            'name' => ['required', 'string', 'max:150'],
        ];
    }
}
