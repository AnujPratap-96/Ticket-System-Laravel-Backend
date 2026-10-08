<?php

namespace App\Services\Ai;

/**
 * Strips obvious personal data before text leaves our servers for the AI provider.
 */
class PiiRedactor
{
    public static function redact(string $text): string
    {
        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $text);
        $text = preg_replace('/https?:\/\/\S+/i', '[link]', $text);
        // phone-like runs (7+ digits, optionally separated) and long id-like numbers (card/aadhaar-style)
        $text = preg_replace('/(?<!\w)\+?\d[\d\s().\-]{7,}\d(?!\w)/', '[number]', $text);

        return $text;
    }

    /**
     * Reversible version for translation: emails, links and numbers become ⟦n⟧ placeholders the model must keep,
     * and are put back afterwards, so personal data never leaves but the text stays usable.
     *
     * @return array{0:string, 1:array<int,string>} [masked text, originals]
     */
    public static function mask(string $text): array
    {
        $map = [];
        $masked = preg_replace_callback('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}|https?:\/\/\S+|(?<!\w)\+?\d[\d\s().\-]{7,}\d(?!\w)/i', function ($m) use (&$map) {
            $map[] = $m[0];

            return '⟦'.(count($map) - 1).'⟧';
        }, $text);

        return [$masked, $map];
    }

    public static function unmask(string $text, array $map): string
    {
        return preg_replace_callback('/⟦(\d+)⟧/u', fn ($m) => $map[(int) $m[1]] ?? '', $text);
    }
}
