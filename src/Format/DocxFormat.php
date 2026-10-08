<?php

namespace Ernestdefoe\Folio\Format;

use Ernestdefoe\Folio\Export\Document;
use Ernestdefoe\Folio\Export\Html;
use Ernestdefoe\Folio\Export\ImageEmbedder;
use Ernestdefoe\Folio\Export\InitialAvatar;
use Flarum\Foundation\Paths;
use Flarum\Locale\TranslatorInterface;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Html as WordHtml;
use Psr\Log\LoggerInterface;

class DocxFormat implements Format
{
    /** Usable width of the page body, in the pixels PhpWord sizes images in. */
    private const BODY_WIDTH_PX = 600;

    public function __construct(
        private ImageEmbedder $images,
        private Paths $paths,
        private TranslatorInterface $translator,
        private LoggerInterface $log,
    ) {
    }

    public function key(): string { return 'docx'; }
    public function label(): string { return (string) $this->translator->trans('ernestdefoe-folio.lib.format_docx'); }
    public function icon(): string { return 'fas fa-file-word'; }
    public function supportsAvatars(): bool { return true; }
    public function extension(): string { return 'docx'; }
    public function mimeType(): string { return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'; }

    public function render(Document $doc): string
    {
        $t = fn (string $key, array $params = []) => (string) $this->translator->trans('ernestdefoe-folio.lib.' . $key, $params);
        $o = $doc->options;
        $color = ltrim($doc->primaryColor, '#');
        $color = strlen($color) === 3 ? preg_replace('/(.)/', '$1$1', $color) : $color;

        /*
         * 🚨 PhpWord writes text into the document XML UNESCAPED unless told
         * otherwise. One "&" in a post — a URL's query string, "Q&A" in a
         * title — made the whole file invalid XML that Word refuses to open.
         */
        Settings::setOutputEscapingEnabled(true);

        $word = new PhpWord();
        $word->setDefaultFontName('Calibri');
        $word->setDefaultFontSize(11);
        $word->getDocInfo()->setTitle($doc->title)->setCreator($doc->forumTitle);
        $word->addTitleStyle(1, ['size' => 20, 'bold' => true, 'color' => '111827'], ['spaceAfter' => 80]);

        $section = $word->addSection(['paperSize' => $doc->paper === 'letter' ? 'Letter' : 'A4']);

        if (trim($doc->header) !== '') {
            $section->addHeader()->addText($doc->fill($doc->header), ['size' => 8, 'color' => '6B7280']);
        }

        $footer = $section->addFooter();
        if (trim($doc->footer) !== '') {
            $footer->addText($doc->fill($doc->footer), ['size' => 8, 'color' => '6B7280'], ['alignment' => 'center']);
        }
        $footer->addPreserveText(
            $t('page_of', ['page' => '{PAGE}', 'pages' => '{NUMPAGES}']),
            ['size' => 8, 'color' => '9CA3AF'],
            ['alignment' => 'center']
        );

        $section->addTitle($doc->title, 1);
        $grey = ['size' => 8.5, 'color' => '6B7280'];
        $meta = $section->addTextRun(['spaceAfter' => 0]);
        $meta->addText($doc->forumTitle . ' · ', $grey);
        $meta->addLink($doc->url, $doc->url, ['size' => 8.5, 'color' => $color]);
        $section->addText(
            $t('exported_on', ['date' => $doc->exportedAt->locale($doc->locale)->isoFormat('LL')]),
            $grey,
            ['spaceAfter' => 240, 'borderBottomSize' => 12, 'borderBottomColor' => $color]
        );

        $missing = $t('image_unavailable');
        $this->images->prefetch(
            array_map(fn ($post) => $post->html, $doc->posts),
            $o->avatars && $o->authors ? array_map(fn ($post) => (string) $post->avatarUrl, $doc->posts) : []
        );

        foreach ($doc->posts as $post) {
            /*
             * Built with PhpWord's own runs, not HTML: its HTML reader drops
             * the spaces between inline elements, which ran the name into the
             * date ("KBExitMarch 27"). The rule above each post separates them,
             * since an empty paragraph's border is not drawn by every reader.
             */
            $byline = $section->addTextRun(['spaceBefore' => 160, 'spaceAfter' => 80, 'borderTopSize' => 4, 'borderTopColor' => 'E5E7EB']);

            $src = $o->avatars && $o->authors
                ? (($post->avatarUrl ? $this->images->dataFor($post->avatarUrl, false, true) : null) ?? InitialAvatar::dataUri($post->author, $doc->primaryColor))
                : null;
            if ($src) {
                $byline->addImage((string) base64_decode(substr($src, strpos($src, ',') + 1)), ['width' => 18, 'height' => 18]);
                $byline->addText(' ');
            }
            if ($o->authors) {
                $byline->addText($post->author, ['bold' => true, 'color' => '111827']);
            }
            if ($o->dates && $post->createdAt) {
                $byline->addText(
                    ($o->authors ? '   ' : '') . $post->createdAt->locale($doc->locale)->isoFormat('LLL') . ($post->editedAt ? ' · ' . $t('edited') : ''),
                    ['size' => 9, 'color' => '6B7280']
                );
            }
            $byline->addText('   #' . $post->number, ['size' => 9, 'color' => '9CA3AF']);

            $body = $this->forWord($this->images->embed($post->html, $missing));
            $this->addHtml($section, $body, $post->html);
        }

        if ($doc->omitted > 0) {
            $section->addText($t('omitted', ['count' => $doc->omitted]), ['italic' => true, 'color' => '6B7280']);
        }

        $file = tempnam($this->paths->storage . '/tmp', 'folio');
        try {
            IOFactory::createWriter($word, 'Word2007')->save($file);

            return (string) file_get_contents($file);
        } finally {
            @unlink($file);
        }
    }

    /**
     * 🚨 PhpWord's HTML reader is strict and partial. One post it cannot read
     * must not cost the reader the whole document, so a post that fails comes
     * through as its plain text instead — the words survive, the formatting
     * of that one post does not.
     */
    private function addHtml(Section $section, string $html, string $fallback): void
    {
        try {
            WordHtml::addHtml($section, $html, false, false);
        } catch (\Throwable $e) {
            $this->log->info('[folio] a post fell back to plain text in a Word export: ' . $e->getMessage());

            $text = trim(html_entity_decode(strip_tags(preg_replace('#<(br|/p|/li|/div|/h\d)[^>]*>#i', "\n", $fallback)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            foreach (preg_split('/\n{2,}/', $text) as $para) {
                $section->addText(str_replace("\n", ' ', $para));
            }
        }
    }

    /**
     * Well-formed XML, sized for the page. PhpWord places an image at its
     * natural pixel size unless told otherwise, so a screenshot runs straight
     * off the right-hand edge.
     */
    private function forWord(string $html): string
    {
        $dom = Html::parse($html);

        foreach (Html::all($dom, 'img') as $img) {
            if (str_contains(' ' . $img->getAttribute('class') . ' ', ' emoji ')) {
                $img->setAttribute('width', '16');
                $img->setAttribute('height', '16');
                continue;
            }

            $src = $img->getAttribute('src');
            $info = str_starts_with($src, 'data:') ? @getimagesizefromstring((string) base64_decode(substr($src, strpos($src, ',') + 1))) : false;

            if ($info) {
                [$w, $h] = $info;
                $scale = min(1, self::BODY_WIDTH_PX / max(1, $w));
                $img->setAttribute('width', (string) max(1, (int) round($w * $scale)));
                $img->setAttribute('height', (string) max(1, (int) round($h * $scale)));
            }
            $img->removeAttribute('style');
        }

        /*
         * PhpWord only understands some of HTML. Tables came out with no
         * borders and no header row, and struck-through text lost its strike,
         * so both are restated in terms it does read.
         */
        foreach (Html::all($dom, 'table') as $table) {
            $table->setAttribute('border', '1');
            $table->setAttribute('style', 'width: 100%; border-collapse: collapse; border: 1px solid #D1D5DB;');
        }
        foreach (Html::all($dom, 'th') as $th) {
            $th->setAttribute('style', 'background-color: #F3F4F6; font-weight: bold;');
        }
        foreach (['del', 's', 'strike'] as $tag) {
            foreach (Html::all($dom, $tag) as $old) {
                $span = $dom->createElement('span');
                $span->setAttribute('style', 'text-decoration: line-through;');
                while ($old->firstChild) {
                    $span->appendChild($old->firstChild);
                }
                $old->parentNode?->replaceChild($span, $old);
            }
        }

        return Html::innerXml($dom);
    }
}
