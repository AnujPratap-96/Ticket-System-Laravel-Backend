<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Replies are written in a small, safe subset of Markdown (bold, italic, links, lists, quotes, code).
 * Raw HTML is never allowed: it is shown as text. Images are not rendered (they could be tracking pixels),
 * and links must be http(s) or mailto and open in a new tab.
 */
class RichText
{
    public static function toHtml(?string $markdown, int $limit = 20000): string
    {
        $md = mb_substr((string) $markdown, 0, $limit);
        $md = preg_replace('/!\[/', '[', $md);   // no images

        $html = Str::markdown($md, ['html_input' => 'escape', 'allow_unsafe_links' => false, 'max_nesting_level' => 10]);

        return preg_replace_callback('/<a\s+([^>]*)>/i', function ($m) {
            if (! preg_match('/href="(https?:\/\/|mailto:)/i', $m[1])) {
                return '<a>';   // not a safe link: keep the text, drop the target
            }

            return '<a '.$m[1].' target="_blank" rel="noopener noreferrer nofollow">';
        }, $html);
    }
}
