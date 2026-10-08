<?php

namespace App\Support;

/**
 * Email bodies are written in Markdown. Text typed by customers or agents must be shown literally:
 * otherwise "[click here](http://evil)" would become a real link inside an official-looking email.
 */
class MarkdownSafe
{
    public static function escape(?string $text, int $limit = 600): string
    {
        $text = trim(strip_tags((string) $text));
        $text = mb_strlen($text) > $limit ? mb_substr($text, 0, $limit).'…' : $text;

        // Backslash-escape every Markdown control character, then flatten blank lines so the text stays one block.
        $text = preg_replace('/([\\\\`*_{}\[\]()#+\-.!|>~<])/', '\\\\$1', $text);

        return preg_replace("/\n{2,}/", "\n", $text);
    }
}
