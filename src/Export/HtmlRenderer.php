<?php

namespace Ernestdefoe\Folio\Export;

use Flarum\Locale\TranslatorInterface;

/**
 * The discussion as one standalone HTML page: the shape the PDF is printed
 * from. Written for dompdf, which speaks CSS 2.1 — tables and floats, no
 * flexbox or grid — so the byline is a table on purpose.
 */
class HtmlRenderer
{
    public function __construct(
        private TranslatorInterface $translator,
        private ImageEmbedder $images,
    ) {
    }

    public function render(Document $doc): string
    {
        $e = fn (string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = fn (string $key, array $params = []) => (string) $this->translator->trans('ernestdefoe-folio.lib.' . $key, $params);
        $missing = $t('image_unavailable');
        $o = $doc->options;

        $bodies = array_map(fn ($post) => Emoji::toImages($post->html), $doc->posts);
        $avatars = $o->avatars && $o->authors ? array_map(fn ($post) => (string) $post->avatarUrl, $doc->posts) : [];
        $this->images->prefetch($bodies, $avatars);

        $posts = '';
        foreach ($doc->posts as $i => $post) {
            $byline = '';

            if ($o->authors || $o->dates) {
                $avatar = '';
                if ($o->avatars && $o->authors) {
                    $src = ($post->avatarUrl ? $this->images->dataFor($post->avatarUrl, false, true) : null)
                        ?? InitialAvatar::dataUri($post->author, $doc->primaryColor);
                    $avatar = '<td class="folio-avatar">' . ($src ? '<img src="' . $e($src) . '" alt="" style="width: 26pt; height: 26pt;">' : '') . '</td>';
                }

                $date = '';
                if ($o->dates && $post->createdAt) {
                    $date = '<span class="folio-date">' . $e($post->createdAt->locale($doc->locale)->isoFormat('LLL')) . '</span>';
                    if ($post->editedAt) {
                        $date .= ' <span class="folio-edited">' . $e($t('edited')) . '</span>';
                    }
                }

                $name = $o->authors ? '<strong class="folio-author">' . $e($post->author) . '</strong>' : '';
                $sep = $name !== '' && $date !== '' ? '<br>' : '';

                $byline = '<table class="folio-byline"><tr>' . $avatar
                    . '<td>' . $name . $sep . $date . '</td>'
                    . '<td class="folio-number">#' . $post->number . '</td></tr></table>';
            }

            $posts .= '<div class="folio-post">' . $byline
                . '<div class="folio-body">' . $this->images->embed($bodies[$i], $missing) . '</div></div>';
        }

        $omitted = $doc->omitted > 0
            ? '<p class="folio-omitted">' . $e($t('omitted', ['count' => $doc->omitted])) . '</p>'
            : '';

        $header = trim($doc->header) !== '' ? '<p class="folio-custom-header">' . nl2br($e($doc->fill($doc->header))) . '</p>' : '';
        $footer = trim($doc->footer) !== '' ? '<p class="folio-custom-footer">' . nl2br($e($doc->fill($doc->footer))) . '</p>' : '';

        $meta = $e($doc->forumTitle) . ' &middot; <a href="' . $e($doc->url) . '">' . $e($doc->url) . '</a><br>'
            . $e($t('exported_on', ['date' => $doc->exportedAt->locale($doc->locale)->isoFormat('LL')]));

        return '<!DOCTYPE html><html lang="' . $e($doc->locale) . '"><head><meta charset="utf-8">'
            . '<title>' . $e($doc->title) . '</title>'
            . '<style>' . $this->css($doc) . '</style></head><body>'
            . $header
            . '<h1 class="folio-title">' . $e($doc->title) . '</h1>'
            . '<p class="folio-meta">' . $meta . '</p>'
            . $posts . $omitted . $footer
            . '</body></html>';
    }

    private function css(Document $doc): string
    {
        $c = $doc->primaryColor;

        // The admin's CSS comes last so it can override anything above. It
        // only ever reaches the exported file, never the forum itself.
        // `</` is neutralised so it cannot close the style element.
        $custom = str_replace('</', '<\/', $doc->customCss);

        return <<<CSS
@page { margin: 22mm 18mm 20mm; }
body { font-family: "DejaVu Sans", sans-serif; font-size: 10.5pt; line-height: 1.5; color: #1f2933; }
a { color: {$c}; }
.folio-title { font-size: 20pt; line-height: 1.2; margin: 0 0 4pt; color: #111827; }
.folio-meta { font-size: 8.5pt; color: #6b7280; margin: 0 0 14pt; padding-bottom: 10pt; border-bottom: 2pt solid {$c}; }
.folio-meta a { color: #6b7280; text-decoration: none; }
.folio-custom-header { font-size: 8.5pt; color: #6b7280; margin: 0 0 10pt; }
.folio-custom-footer { font-size: 8.5pt; color: #6b7280; margin-top: 18pt; padding-top: 8pt; border-top: 0.5pt solid #d1d5db; }
.folio-post { margin: 0 0 14pt; padding-bottom: 12pt; border-bottom: 0.5pt solid #e5e7eb; }
/* A byline is never left alone at the foot of a page with its post overleaf.
   (dompdf only: see PdfFormat::withMpdf.) */
.folio-byline { page-break-after: avoid; page-break-inside: avoid; }
.folio-byline { width: 100%; border-collapse: collapse; margin: 0 0 6pt; }
.folio-byline td { vertical-align: middle; padding: 0; }
.folio-avatar { width: 34pt; }
.folio-avatar img { width: 26pt; height: 26pt; border-radius: 13pt; }
.folio-author { color: #111827; }
.folio-date, .folio-edited { font-size: 8.5pt; color: #6b7280; }
.folio-edited { font-style: italic; }
.folio-number { width: 40pt; text-align: right; font-size: 8.5pt; color: #9ca3af; }
/* 🚨 max-height keeps a tall infographic on one page: without it the image
   ran off the bottom edge, cut, instead of shrinking to fit. */
.folio-body img { max-width: 100%; max-height: 225mm; height: auto; }
/* A bare URL has no spaces to break at and ran off the right-hand edge. */
.folio-body a { word-break: break-all; }
.folio-body p, .folio-body li, .folio-body blockquote { word-wrap: break-word; }
.folio-body .spoiler { background: #f3f4f6; border: 0.5pt dashed #9ca3af; padding: 0 2pt; }
.folio-spoiler-label { color: #6b7280; font-size: 8.5pt; }
.folio-body img.emoji { width: 1.15em; height: 1.15em; vertical-align: -0.2em; }
.folio-body p { margin: 0 0 7pt; }
.folio-body blockquote { margin: 0 0 8pt; padding: 4pt 10pt; border-left: 3pt solid {$c}; background: #f3f4f6; color: #374151; }
.folio-body pre { white-space: pre-wrap; background: #f3f4f6; padding: 7pt 9pt; font-family: "DejaVu Sans Mono", monospace; font-size: 8.5pt; border-radius: 3pt; }
.folio-body code { font-family: "DejaVu Sans Mono", monospace; font-size: 9pt; background: #f3f4f6; padding: 0 2pt; }
.folio-body pre code { background: none; padding: 0; }
.folio-body table { border-collapse: collapse; margin: 0 0 8pt; }
.folio-body th, .folio-body td { border: 0.5pt solid #d1d5db; padding: 3pt 6pt; }
.folio-body th { background: #f3f4f6; }
.folio-body h1, .folio-body h2, .folio-body h3 { line-height: 1.25; margin: 10pt 0 5pt; }
.folio-body h1 { font-size: 15pt; } .folio-body h2 { font-size: 13pt; } .folio-body h3 { font-size: 11.5pt; }
.folio-body hr { border: 0; border-top: 0.5pt solid #d1d5db; }
.folio-omitted { font-style: italic; color: #6b7280; }
{$custom}
CSS;
    }
}
