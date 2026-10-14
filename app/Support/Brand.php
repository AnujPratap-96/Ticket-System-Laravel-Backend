<?php

namespace App\Support;

use App\Models\Tenant;
use App\Services\CloudinaryService;

/**
 * How a workspace presents itself: its name, colour and logo (emails, sign-in page, app header).
 * Falls back to the DeskFlow defaults for anything the workspace has not set.
 */
class Brand
{
    public const DEFAULT_COLOR = '#4f46e5';

    /** @return array{name:string,color:string,logo_url:?string,sender_name:?string,reply_to:?string,home_url:string} */
    public static function for(?Tenant $tenant): array
    {
        $cloud = app(CloudinaryService::class);

        return [
            'name' => $tenant?->name ?: (string) config('app.name'),
            'color' => $tenant?->brand_color ?: self::DEFAULT_COLOR,
            'logo_url' => $tenant?->logo_public_id && $cloud->enabled() ? $cloud->logoUrl($tenant->logo_public_id) : null,
            'sender_name' => $tenant?->sender_name ?: ($tenant?->name),
            'reply_to' => $tenant?->reply_to_email,
            'home_url' => $tenant ? $tenant->url('/') : (config('services.mail_brand.home_url') ?: config('app.url')),
        ];
    }

    /** "#4f46e5" -> a darker shade for borders and hover (percent 0-100 darker). */
    public static function shade(string $hex, int $percent): string
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        $f = 1 - $percent / 100;

        return sprintf('#%02x%02x%02x', (int) round($r * $f), (int) round($g * $f), (int) round($b * $f));
    }
}
