<?php

namespace Ernestdefoe\Folio\Format;

use Dompdf\Dompdf;
use Dompdf\Options;
use Ernestdefoe\Folio\Export\ComplexScripts;
use Ernestdefoe\Folio\Export\Document;
use Ernestdefoe\Folio\Export\HtmlRenderer;
use Ernestdefoe\Folio\Export\UnsupportedScript;
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

    public function key(): string
    {
        return 'pdf';
    }

    public function label(): string
    {
        return (string) $this->translator->trans('ernestdefoe-folio.lib.format_pdf');
    }

    public function icon(): string
    {
        return 'fas fa-file-pdf';
    }

    public function supportsAvatars(): bool
    {
        return true;
    }

    public function extension(): string
    {
        return 'pdf';
    }

    public function mimeType(): string
    {
        return 'application/pdf';
    }

    public function render(Document $document): string
    {
        $work = $this->paths->storage.'/tmp/folio';
        if (! is_dir($work)) {
            @mkdir($work, 0775, true);
        }

        if (ComplexScripts::present($document)) {
            if (! ComplexScripts::engineAvailable()) {
                throw new UnsupportedScript();
            }

            return $this->withMpdf($document, $work);
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

    /**
     * The same page, set by mPDF: it picks a font per script (CJK, Arabic,
     * Hebrew, Indic, Thai…) from the fonts it ships with, joins Arabic letters
     * and lays out right-to-left text. Slower and heavier than dompdf, so it is
     * only used for the documents that need it.
     */
    private function withMpdf(Document $document, string $work): string
    {
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => $document->paper === 'letter' ? 'Letter' : 'A4',
            'tempDir' => $work,
            'default_font' => 'dejavusans',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'margin_top' => 22,
            'margin_bottom' => 20,
            'margin_left' => 18,
            'margin_right' => 18,
            'margin_footer' => 8,
        ]);

        // Every image is already embedded; nothing should be fetched from here.
        $mpdf->curlTimeout = 1;

        $label = (string) $this->translator->trans('ernestdefoe-folio.lib.page_of', ['page' => '{PAGENO}', 'pages' => '{nbpg}']);
        $mpdf->SetHTMLFooter('<div style="text-align: center; font-size: 8pt; color: #8c949e;">'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</div>');
        $mpdf->SetTitle($document->title);
        /*
         * 🚨 mPDF reads "page-break-after: avoid" on the byline table as a
         * reason to START A NEW PAGE after it: every post's name sat alone at
         * the foot of one page and its text began on the next. mPDF keeps a
         * short table together by itself, so the rule is simply dropped here.
         */
        // Same for @page: mPDF takes it as a new page style that has no footer,
        // which silently dropped the page numbers. Its margins are set above.
        $html = str_replace(
            ['.folio-byline { page-break-after: avoid; page-break-inside: avoid; }', '@page { margin: 22mm 18mm 20mm; }'],
            '',
            $this->html->render($document)
        );
        $mpdf->WriteHTML($html);

        return (string) $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }
}
