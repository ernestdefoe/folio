<?php

namespace Ernestdefoe\Folio\Format;

use Ernestdefoe\Folio\Export\Document;

/**
 * One kind of file a discussion can be exported as.
 *
 * Another extension adds its own with the Folio extender:
 *
 *     (new \Ernestdefoe\Folio\Extend\Folio())->format(MyEpubFormat::class)
 *
 * It then appears in the export dialog, and gets an on/off switch in Folio's
 * settings under `ernestdefoe-folio.format_<key>`.
 */
interface Format
{
    /** Short, stable, lowercase: it is the `format` query value and part of the setting key. */
    public function key(): string;

    /** What the reader sees in the dialog. Translate it. */
    public function label(): string;

    /** A Font Awesome class, e.g. `fas fa-file-pdf`. */
    public function icon(): string;

    /** Whether avatars mean anything in this format (Markdown has nowhere to put them). */
    public function supportsAvatars(): bool;

    public function extension(): string;

    public function mimeType(): string;

    /** The finished file's bytes. */
    public function render(Document $document): string;
}
