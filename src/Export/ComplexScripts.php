<?php

namespace Ernestdefoe\Folio\Export;

/**
 * Whether a document contains writing the default PDF engine cannot set.
 *
 * 🚨 dompdf draws every character in ONE font and has no fallback per
 * character, so Chinese, Japanese and Korean come out as empty boxes. It also
 * has no bidirectional layout or letter joining, so Arabic and Hebrew come out
 * unjoined and in reverse order. Neither is a setting: these scripts need mPDF.
 */
final class ComplexScripts
{
    private const PATTERN = '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\p{Bopomofo}'
        . '\p{Arabic}\p{Hebrew}\p{Syriac}\p{Thaana}\p{Nko}'
        . '\p{Devanagari}\p{Bengali}\p{Gurmukhi}\p{Gujarati}\p{Oriya}\p{Tamil}\p{Telugu}\p{Kannada}\p{Malayalam}\p{Sinhala}'
        . '\p{Thai}\p{Lao}\p{Tibetan}\p{Myanmar}\p{Khmer}\p{Ethiopic}]/u';

    public static function present(Document $doc): bool
    {
        $text = $doc->title . ' ' . $doc->forumTitle . ' ' . $doc->header . ' ' . $doc->footer;

        foreach ($doc->posts as $post) {
            $text .= ' ' . $post->author . ' ' . strip_tags($post->html);
        }

        return (bool) preg_match(self::PATTERN, html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public static function engineAvailable(): bool
    {
        return class_exists(\Mpdf\Mpdf::class);
    }
}
