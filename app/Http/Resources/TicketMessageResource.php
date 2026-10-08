<?php

namespace App\Http\Resources;

use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            'sender' => new UserResource($this->whenLoaded('sender')),
            'is_internal_note' => (bool) $this->is_internal_note,
            'body' => $this->body,
            'body_html' => \App\Support\RichText::toHtml($this->body),
            'attachments' => app(\App\Services\AttachmentService::class)->present($this->attachments_json),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
