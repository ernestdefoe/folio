<?php

namespace Ernestdefoe\Folio\Format;

use Ernestdefoe\Folio\Export\Document;
use Ernestdefoe\Folio\Export\Html;
use Flarum\Locale\TranslatorInterface;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * Plain text that reads well as-is and drops straight into a static site, a
 * notes app or a prompt. Images stay as links to where they live; Markdown has
 * no way to carry the file itself.
 */
class MarkdownFormat implements Format
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function key(): string
    {
        return 'markdown';
    }

    public function label(): string
    {
        return (string) $this->translator->trans('ernestdefoe-folio.lib.format_markdown');
    }

    public function icon(): string
    {
        return 'fab fa-markdown';
    }

    public function supportsAvatars(): bool
    {
        return false;
    }

    public function extension(): string
    {
        return 'md';
    }

    public function mimeType(): string
    {
        return 'text/markdown; charset=utf-8';
    }

    public function render(Document $doc): string
    {
        $t = fn (string $key, array $params = []) => (string) $this->translator->trans('ernestdefoe-folio.lib.'.$key, $params);
        $o = $doc->options;

        $converter = new HtmlConverter([
            'strip_tags' => true,
            'header_style' => 'atx',
            'hard_break' => true,
            'remove_nodes' => 'script style',
        ]);
        // Neither is on by default: tables (Scribe makes them) and strikethrough
        // were dropped without a trace.
        $converter->getEnvironment()->addConverter(new TableConverter());
        $converter->getEnvironment()->addConverter(new StrikethroughConverter());

        $out = [];
        if (trim($doc->header) !== '') {
            $out[] = $doc->fill($doc->header);
        }

        $out[] = '# '.$this->line($doc->title);
        $out[] = '_'.$this->line($doc->forumTitle).' · <'.$doc->url.'> · '
            .$t('exported_on', ['date' => $doc->exportedAt->locale($doc->locale)->isoFormat('LL')]).'_';

        foreach ($doc->posts as $post) {
            $out[] = '---';

            $byline = [];
            if ($o->authors) {
                $byline[] = '**'.$this->line($post->author).'**';
            }
            if ($o->dates && $post->createdAt) {
                $byline[] = $post->createdAt->locale($doc->locale)->isoFormat('LLL').($post->editedAt ? ' ('.$t('edited').')' : '');
            }
            if ($byline) {
                $out[] = implode(' · ', $byline).' · #'.$post->number;
            }

            $out[] = $this->unescape(trim($converter->convert($this->emojiAsText($post->html))));
        }

        if ($doc->omitted > 0) {
            $out[] = '---';
            $out[] = '_'.$t('omitted', ['count' => $doc->omitted]).'_';
        }

        if (trim($doc->footer) !== '') {
            $out[] = '---';
            $out[] = $doc->fill($doc->footer);
        }

        return implode("\n\n", $out)."\n";
    }

    /**
     * 🚨 The converter leaves HTML entities in ordinary text, so a URL's
     * "&more=" read "&amp;more=" and "Q&A" read "Q&amp;A". They are decoded
     * everywhere except inside code, where the converter has already decoded
     * them, and except < and >, which must stay escaped or a reader would take
     * them for HTML.
     */
    private function unescape(string $markdown): string
    {
        $parts = preg_split('/(```.*?```|`[^`\n]*`)/s', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $parts[$i] = str_replace(['&amp;', '&quot;', '&#039;', '&#39;', '&nbsp;'], ['&', '"', "'", "'", ' '], $part);
            }
        }

        return implode('', $parts);
    }

    /** An emoji image is the emoji: keep the character, not a link to a PNG of it. */
    private function emojiAsText(string $html): string
    {
        $dom = Html::parse($html);

        foreach (Html::all($dom, 'img') as $img) {
            if (str_contains(' '.$img->getAttribute('class').' ', ' emoji ') && $img->getAttribute('alt') !== '') {
                $img->parentNode?->replaceChild($dom->createTextNode($img->getAttribute('alt')), $img);
            }
        }

        return Html::inner($dom);
    }

    /** Markdown's own characters escaped where a title or name is inlined. */
    private function line(string $text): string
    {
        return addcslashes(str_replace(["\r", "\n"], ' ', $text), '\\*_[]#`<>');
    }
}
