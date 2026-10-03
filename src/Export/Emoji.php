<?php

namespace Ernestdefoe\Folio\Export;

/**
 * Emoji typed as characters, drawn as images — for the PDF.
 *
 * 🚨 dompdf draws text with the document's font, and no font it ships has
 * colour emoji: "I'm on it! 🤔" comes out as "I'm on it! ▯". Word and Markdown
 * do not need this; the reader's own system draws the character there.
 *
 * Only true emoji are converted: anything outside the Basic Multilingual Plane,
 * or anything marked for emoji presentation (U+FE0F) or joined with U+200D.
 * Plain symbols like © ™ ✔ ☀ stay text, because the font has those.
 */
final class Emoji
{
    /** jdecked's maintained fork of Twemoji: the same images Flarum's emoji extension uses. */
    public const BASE = 'https://cdn.jsdelivr.net/gh/jdecked/twemoji@15.1.0/assets/72x72/';

    private const PATTERN = '/\p{Regional_Indicator}{2}'
        . '|[#*0-9]\x{FE0F}?\x{20E3}'
        . '|\p{Extended_Pictographic}(?:\x{FE0F}|\p{Emoji_Modifier})?(?:\x{200D}\p{Extended_Pictographic}(?:\x{FE0F}|\p{Emoji_Modifier})?)*/u';

    public static function toImages(string $html): string
    {
        if (! preg_match('/[\x{1F000}-\x{1FFFF}\x{FE0F}\x{20E3}]/u', $html)) {
            return $html; // Nothing to do — the common case, kept cheap.
        }

        $dom = Html::parse($html);
        $xpath = new \DOMXPath($dom);

        foreach (iterator_to_array($xpath->query('//text()[not(ancestor::pre) and not(ancestor::code)]', Html::root($dom))) as $text) {
            /** @var \DOMText $text */
            $value = $text->nodeValue ?? '';

            if (! preg_match_all(self::PATTERN, $value, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $fragment = $dom->createDocumentFragment();
            $at = 0;
            $changed = false;

            foreach ($matches[0] as [$emoji, $offset]) {
                if (! self::isEmoji($emoji)) {
                    continue;
                }

                $fragment->appendChild($dom->createTextNode(substr($value, $at, $offset - $at)));
                $img = $dom->createElement('img');
                $img->setAttribute('class', 'emoji');
                $img->setAttribute('alt', $emoji);
                $img->setAttribute('src', self::BASE . self::file($emoji) . '.png');
                $fragment->appendChild($img);
                $at = $offset + strlen($emoji);
                $changed = true;
            }

            if ($changed) {
                $fragment->appendChild($dom->createTextNode(substr($value, $at)));
                $text->parentNode?->replaceChild($fragment, $text);
            }
        }

        return Html::inner($dom);
    }

    private static function isEmoji(string $sequence): bool
    {
        return (bool) preg_match('/[\x{1F000}-\x{1FFFF}\x{FE0F}\x{200D}\x{20E3}]/u', $sequence);
    }

    /** Twemoji's own file naming: code points in hex, FE0F dropped unless the sequence is joined. */
    private static function file(string $sequence): string
    {
        $points = array_map(fn ($c) => mb_ord($c, 'UTF-8'), mb_str_split($sequence, 1, 'UTF-8'));

        if (! in_array(0x200D, $points, true)) {
            $points = array_values(array_filter($points, fn ($p) => $p !== 0xFE0F));
        }

        return implode('-', array_map('dechex', $points));
    }
}
