<?php

namespace App\Http\Resources;

use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isCustomer = $user && $user->role === UserRole::CUSTOMER;

        // Filter out internal notes for customers
        $messages = $this->whenLoaded('messages', function () use ($isCustomer) {
            $filtered = $this->messages;
            if ($isCustomer) {
                $filtered = $filtered->where('is_internal_note', false);
            }
            return TicketMessageResource::collection($filtered);
        });

        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'title' => $this->title,
            'description' => $this->description,
            'attachments' => app(\App\Services\AttachmentService::class)->present($this->attachments_json),
            'custom_fields' => $this->when(! empty($this->custom_fields), fn () => \App\Support\TicketForm::present(($this->relationLoaded('department') ? $this->department : \App\Models\Department::find($this->department_id))?->form_fields, $this->custom_fields), []),
            'status' => is_string($this->status) ? $this->status : $this->status->value,
            'priority' => is_string($this->priority) ? $this->priority : $this->priority->value,
            'channel' => is_string($this->channel) ? $this->channel : $this->channel->value,
            'organization' => new OrganizationResource($this->whenLoaded('organization')),
            'customer' => new UserResource($this->whenLoaded('customer')),
            'assigned_agent' => new UserResource($this->whenLoaded('assignedAgent')),
            'department' => new DepartmentResource($this->whenLoaded('department')),
            'sla_deadlines' => TicketSlaDeadlineResource::collection($this->whenLoaded('slaDeadlines')),
            'messages' => $messages,
            'audits' => TicketAuditResource::collection($this->whenLoaded('audits')),
            // Tags are internal triage labels: staff only.
            'tags' => $this->when(! $isCustomer, fn () => $this->relationLoaded('tags') ? $this->tags->pluck('name')->values() : []),
            // AI flags and the pending triage suggestion are for staff only.
            'ai' => $this->when(! $isCustomer, fn () => [
                'sentiment' => $this->sentiment,
                'urgent' => (bool) $this->is_ai_urgent,
                'triage' => ($this->ai_triage['status'] ?? null) === 'suggested' ? $this->ai_triage : null,
            ]),
            'is_watching' => $this->when(! $isCustomer && $user, fn () => $this->relationLoaded('watchers') ? $this->watchers->contains('id', $user->id) : false),
            'merged_into' => $this->whenLoaded('mergedInto', fn () => $this->mergedInto ? ['id' => $this->mergedInto->id, 'ticket_number' => $this->mergedInto->ticket_number] : null),
            'rating' => $this->whenLoaded('rating', fn () => $this->rating ? ['rating' => $this->rating->rating, 'comment' => $this->rating->comment] : null),
            'first_responded_at' => $this->first_responded_at?->toISOString(),
            'resolved_at' => $this->resolved_at?->toISOString(),
            'closed_at' => $this->closed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
