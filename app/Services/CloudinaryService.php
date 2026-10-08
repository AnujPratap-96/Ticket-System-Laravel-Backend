<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Signed direct-upload support. Files go browser -> Cloudinary as private
 * ("authenticated") assets; the API only signs uploads, verifies what was uploaded,
 * and hands out short-lived download URLs after an authorization check.
 */
class CloudinaryService
{
    public const DOWNLOAD_TTL_SECONDS = 3600;

    public function enabled(): bool
    {
        return (bool) ($this->cfg('cloud_name') && $this->cfg('api_key') && $this->cfg('api_secret'));
    }

    /**
     * Parameters the browser needs to upload one file into $folder.
     */
    public function signUpload(string $folder): array
    {
        $params = [
            'allowed_formats' => $this->cfg('allowed_formats'),
            'folder' => $folder,
            'timestamp' => time(),
            'type' => 'authenticated',
        ];

        return [
            'upload_url' => "https://api.cloudinary.com/v1_1/{$this->cfg('cloud_name')}/auto/upload",
            'api_key' => $this->cfg('api_key'),
            'signature' => $this->sign($params),
            'max_bytes' => $this->cfg('max_bytes'),
            'params' => $params,
        ];
    }

    public const AVATAR_MAX_BYTES = 2 * 1024 * 1024;
    public const AVATAR_FORMATS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Signed parameters for uploading ONE profile photo under a server-chosen, unguessable public_id.
     * The image is downscaled on upload (max 512 px) and stored publicly, as profile photos are shown to other users.
     */
    public function signAvatarUpload(string $publicId): array
    {
        $params = [
            'allowed_formats' => implode(',', self::AVATAR_FORMATS),
            'public_id' => $publicId,
            'timestamp' => time(),
            'transformation' => 'c_limit,w_512,h_512',
            'type' => 'upload',
        ];

        return [
            'upload_url' => "https://api.cloudinary.com/v1_1/{$this->cfg('cloud_name')}/image/upload",
            'api_key' => $this->cfg('api_key'),
            'signature' => $this->sign($params),
            'max_bytes' => self::AVATAR_MAX_BYTES,
            'public_id' => $publicId,
            'params' => $params,
        ];
    }

    /**
     * Square, face-aware thumbnail URL for a stored avatar.
     */
    public function avatarUrl(string $publicId, int $size = 160): string
    {
        return "https://res.cloudinary.com/{$this->cfg('cloud_name')}/image/upload/c_fill,g_face,w_{$size},h_{$size},f_auto,q_auto/{$publicId}";
    }

    /**
     * Fetch the authoritative asset record from Cloudinary (never trust client-sent sizes).
     * Returns null when the asset does not exist.
     */
    public function fetchAsset(string $publicId, string $resourceType, string $type = 'authenticated'): ?array
    {
        $res = Http::withBasicAuth($this->cfg('api_key'), $this->cfg('api_secret'))
            ->acceptJson()
            ->get($this->apiBase()."/resources/{$resourceType}/{$type}/".rawurlencode($publicId));

        if ($res->status() === 404) {
            return null;
        }
        if ($res->failed()) {
            throw new RuntimeException('Cloudinary lookup failed: '.$res->status());
        }

        return $res->json();
    }

    public function delete(string $publicId, string $resourceType, string $type = 'authenticated'): void
    {
        Http::withBasicAuth($this->cfg('api_key'), $this->cfg('api_secret'))
            ->asForm()
            ->delete($this->apiBase()."/resources/{$resourceType}/{$type}", [
                'public_ids' => [$publicId],
            ]);
    }

    /**
     * Expiring private download URL for an authenticated asset.
     */
    public function downloadUrl(string $publicId, string $resourceType, ?string $format = null, ?int $ttl = null): string
    {
        $params = array_filter([
            'api_key' => $this->cfg('api_key'),
            'expires_at' => time() + ($ttl ?? self::DOWNLOAD_TTL_SECONDS),
            'format' => $format,
            'public_id' => $publicId,
            'timestamp' => time(),
            'type' => 'authenticated',
        ], fn ($v) => $v !== null && $v !== '');

        $params['signature'] = $this->sign($params);

        return $this->apiBase()."/{$resourceType}/download?".http_build_query($params);
    }

    /**
     * Cloudinary signature: sha1 of the sorted "k=v&k=v" string with the secret appended.
     */
    public function sign(array $params): string
    {
        unset($params['file'], $params['cloud_name'], $params['resource_type'], $params['api_key'], $params['signature']);
        ksort($params);

        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = $k.'='.(is_array($v) ? implode(',', $v) : $v);
        }

        return sha1(implode('&', $pairs).$this->cfg('api_secret'));
    }

    private function apiBase(): string
    {
        return "https://api.cloudinary.com/v1_1/{$this->cfg('cloud_name')}";
    }

    private function cfg(string $key)
    {
        return config("services.cloudinary.{$key}");
    }
}
