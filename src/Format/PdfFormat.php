<?php

namespace Ernestdefoe\Folio\Format;

use Dompdf\Dompdf;
use Dompdf\Options;
use Ernestdefoe\Folio\Export\Document;
use Ernestdefoe\Folio\Export\HtmlRenderer;
use Flarum\Foundation\Paths;
use Flarum\Locale\TranslatorInterface;

class PdfFormat implements Format
{
    public function __construct(
        private HtmlRenderer $html,
        private Paths $paths,
        private TranslatorInterface $translator,
    ) {
    }

    public function key(): string { return 'pdf'; }
    public function label(): string { return (string) $this->translator->trans('ernestdefoe-folio.lib.format_pdf'); }
    public function icon(): string { return 'fas fa-file-pdf'; }
    public function supportsAvatars(): bool { return true; }
    public function extension(): string { return 'pdf'; }
    public function mimeType(): string { return 'application/pdf'; }

    public function render(Document $document): string
    {
        $work = $this->paths->storage . '/tmp/folio';
        if (! is_dir($work)) {
            @mkdir($work, 0775, true);
        }

        $options = new Options();
        /*
         * 🚨 Remote loading OFF. Every image was already embedded (or refused)
         * by ImageEmbedder, which knows what is safe to fetch. Left on, dompdf
         * would fetch whatever a post's HTML points at, from the server.
         */
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setChroot([$work]);
        $options->setTempDir($work);
        $options->setFontCache($work);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html->render($document), 'UTF-8');
        $dompdf->setPaper($document->paper, 'portrait');
        $dompdf->render();

        // "Page 3 of 12" at the foot of every page.
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $label = (string) $this->translator->trans('ernestdefoe-folio.lib.page_of', ['page' => '{PAGE_NUM}', 'pages' => '{PAGE_COUNT}']);
        $width = $dompdf->getFontMetrics()->getTextWidth($label, $font, 8);
        $canvas->page_text(($canvas->get_width() - $width) / 2 + 12, $canvas->get_height() - 34, $label, $font, 8, [0.55, 0.58, 0.62]);

        return (string) $dompdf->output();
    }
}
