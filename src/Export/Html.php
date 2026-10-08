<?php

namespace Ernestdefoe\Folio\Export;

/** Parse a fragment and serialise it back, without libxml's encoding and wrapper surprises. */
final class Html
{
    public static function parse(string $fragment): \DOMDocument
    {
        $dom = new \DOMDocument();

        // 🚨 Without the charset hint libxml reads the bytes as Latin-1 and
        // every non-ASCII character in a post comes out as mojibake.
        @$dom->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="folio-root">'.$fragment.'</div></body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        return $dom;
    }

    public static function root(\DOMDocument $dom): \DOMElement
    {
        return $dom->getElementById('folio-root') ?? $dom->getElementsByTagName('body')->item(0);
    }

    public static function inner(\DOMDocument $dom): string
    {
        $out = '';
        foreach (iterator_to_array(self::root($dom)->childNodes) as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    /** The same fragment as well-formed XML, which is what PhpWord insists on. */
    public static function innerXml(\DOMDocument $dom): string
    {
        $out = '';
        foreach (iterator_to_array(self::root($dom)->childNodes) as $child) {
            $out .= $dom->saveXML($child);
        }

        return $out;
    }

    /** @return \DOMElement[] a snapshot, safe to modify while walking */
    public static function all(\DOMDocument $dom, string $tag): array
    {
        return iterator_to_array(self::root($dom)->getElementsByTagName($tag));
    }
}
