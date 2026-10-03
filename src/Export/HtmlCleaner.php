<?php

namespace Ernestdefoe\Folio\Export;

use Flarum\Locale\TranslatorInterface;

/**
 * Turns a post's rendered HTML into something safe to hand to a document
 * converter: nothing that runs, nothing that loads by itself, every link
 * absolute so it still works outside the forum.
 */
class HtmlCleaner
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    /** Removed with everything inside them. */
    private const DROP = ['script', 'style', 'noscript', 'template', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'canvas', 'object', 'embed', 'link', 'meta'];

    /** Media that cannot be put on paper: replaced by a link to it. */
    private const MEDIA = ['iframe', 'video', 'audio'];

    public function clean(string $html, string $baseUrl): string
    {
        $dom = Html::parse($html);
        $root = Html::root($dom);

        foreach (self::DROP as $tag) {
            foreach (Html::all($dom, $tag) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        foreach (self::MEDIA as $tag) {
            foreach (Html::all($dom, $tag) as $node) {
                $src = $node->getAttribute('src') ?: $this->firstSource($node);
                $src = $src !== '' ? $this->absolute($src, $baseUrl) : '';

                if ($src === '' || ! preg_match('#^https?://#i', $src)) {
                    $node->parentNode?->removeChild($node);
                    continue;
                }

                $p = $dom->createElement('p');
                $a = $dom->createElement('a');
                $a->setAttribute('href', $src);
                $a->appendChild($dom->createTextNode($src));
                $p->appendChild($a);
                $node->parentNode?->replaceChild($p, $node);
            }
        }

        // Paper cannot hide anything, so a spoiler is at least labelled as one
        // rather than reading as ordinary text.
        $xpath = new \DOMXPath($dom);
        $label = (string) $this->translator->trans('ernestdefoe-folio.lib.spoiler');
        foreach (iterator_to_array($xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " spoiler ")]', $root)) as $spoiler) {
            /** @var \DOMElement $spoiler */
            $tag = $dom->createElement('em', $label . ' ');
            $tag->setAttribute('class', 'folio-spoiler-label');
            $spoiler->insertBefore($tag, $spoiler->firstChild);
        }

        foreach (iterator_to_array($root->getElementsByTagName('*')) as $el) {
            /** @var \DOMElement $el */
            foreach (iterator_to_array($el->attributes) as $attr) {
                $name = strtolower($attr->name);

                if (str_starts_with($name, 'on') || in_array($name, ['srcset', 'loading', 'draggable', 'contenteditable'], true)) {
                    $el->removeAttribute($attr->name);
                }
            }

            if ($el->tagName === 'a') {
                $href = $this->absolute($el->getAttribute('href'), $baseUrl);
                // A javascript: or data: link has no business in a document.
                preg_match('#^(https?:|mailto:)#i', $href) ? $el->setAttribute('href', $href) : $el->removeAttribute('href');
            }

            if ($el->tagName === 'img') {
                // Lazy-loading plugins keep the real address in data-src.
                $src = $el->getAttribute('src') ?: $el->getAttribute('data-src');
                $el->setAttribute('src', $this->absolute($src, $baseUrl));
            }
        }

        return Html::inner($dom);
    }

    private function firstSource(\DOMElement $media): string
    {
        foreach ($media->getElementsByTagName('source') as $source) {
            if ($source->getAttribute('src') !== '') {
                return $source->getAttribute('src');
            }
        }

        return '';
    }

    public function absolute(string $url, string $baseUrl): string
    {
        $url = trim($url);
        $base = rtrim($baseUrl, '/');

        if ($url === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return (parse_url($base, PHP_URL_SCHEME) ?: 'https') . ':' . $url;
        }

        if (str_starts_with($url, '/')) {
            $origin = preg_replace('#^(https?://[^/]+).*$#i', '$1', $base);

            return $origin . $url;
        }

        return $base . '/' . $url;
    }
}
