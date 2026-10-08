<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'domain' => $this->domain,
            'sla_tier' => is_string($this->sla_tier) ? $this->sla_tier : $this->sla_tier->value,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
