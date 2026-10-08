<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'job_title' => $this->job_title,
            'email' => $this->email,
            'role' => is_string($this->role) ? $this->role : $this->role->value,
            'avatar_url' => $this->avatar_public_id && app(\App\Services\CloudinaryService::class)->enabled()
                ? app(\App\Services\CloudinaryService::class)->avatarUrl($this->avatar_public_id)
                : null,
            'organization_id' => $this->organization_id,
            'department_id' => $this->department_id,
            'is_available_for_routing' => (bool) $this->is_available_for_routing,
            'max_active_tickets' => (int) $this->max_active_tickets,
            'is_active' => (bool) ($this->is_active ?? true),
            // For the admin's team list: has this person accepted their email invitation yet?
            'invite_status' => $this->whenLoaded('invite', fn () => $this->invite && ! $this->invite->accepted_at ? ($this->invite->expires_at->isPast() ? 'expired' : 'pending') : null),
            'is_org_admin' => (bool) ($this->is_org_admin ?? false),
            'organization' => $this->whenLoaded('organization', fn () => $this->organization ? ['id' => $this->organization->id, 'name' => $this->organization->name] : null),
            'department' => new DepartmentResource($this->whenLoaded('department')),
            'active_tickets_count' => $this->when(
                isset($this->active_tickets_count),
                (int) $this->active_tickets_count
            ),
        ];
    }
}
