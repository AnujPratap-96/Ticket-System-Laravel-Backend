<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'business_hours_start' => $this->business_hours_start,
            'business_hours_end' => $this->business_hours_end,
            'timezone' => $this->timezone,
            'form_fields' => $this->form_fields ?? [],
        ];
    }
}
