<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Validates client-reported attachments against Cloudinary and builds the stored shape.
 */
class AttachmentService
{
    public function __construct(private CloudinaryService $cloudinary) {}

    public static function ticketFolder(Ticket $ticket): string
    {
        return "deskflow/tickets/{$ticket->id}";
    }

    public static function pendingFolder(User $user): string
    {
        return "deskflow/pending/{$user->id}";
    }

    /**
     * @param  array<int, array{public_id:string, resource_type:string, name?:string}>  $items
     * @return array<int, array<string, mixed>>
     */
    public function resolve(array $items, string $expectedFolder): array
    {
        if ($items === []) {
            return [];
        }

        if (! $this->cloudinary->enabled()) {
            throw ValidationException::withMessages(['attachments' => ['File uploads are not configured on the server.']]);
        }

        $max = (int) config('services.cloudinary.max_files');
        if (count($items) > $max) {
            throw ValidationException::withMessages(['attachments' => ["You can attach at most {$max} files."]]);
        }

        $out = [];
        foreach ($items as $item) {
            $publicId = $item['public_id'];

            // Only files uploaded into this ticket's (or the user's pending) folder may be attached.
            if (! str_starts_with($publicId, $expectedFolder.'/') || str_contains($publicId, '..')) {
                throw ValidationException::withMessages(['attachments' => ['Invalid attachment reference.']]);
            }

            $asset = $this->cloudinary->fetchAsset($publicId, $item['resource_type']);

            if (! $asset) {
                throw ValidationException::withMessages(['attachments' => ['An uploaded file could not be found. Please upload it again.']]);
            }

            if (($asset['bytes'] ?? 0) > config('services.cloudinary.max_bytes')) {
                $this->cloudinary->delete($publicId, $item['resource_type']);
                throw ValidationException::withMessages(['attachments' => ['A file exceeds the 10 MB limit.']]);
            }

            $out[] = [
                'public_id' => $publicId,
                'resource_type' => $item['resource_type'],
                'format' => $asset['format'] ?? null,
                'bytes' => (int) ($asset['bytes'] ?? 0),
                'name' => mb_substr($item['name'] ?? basename($publicId), 0, 255),
            ];
        }

        return $out;
    }

    /**
     * Adds fresh, expiring download URLs for display. Stored data never contains URLs.
     */
    public function present(?array $stored): array
    {
        if (! $stored || ! $this->cloudinary->enabled()) {
            return [];
        }

        return array_map(function ($a) {
            if (! isset($a['public_id'])) {
                return $a; // legacy rows
            }

            return $a + [
                'url' => $this->cloudinary->downloadUrl($a['public_id'], $a['resource_type'], $a['format'] ?? null),
                'is_image' => ($a['resource_type'] ?? '') === 'image',
            ];
        }, $stored);
    }
}
