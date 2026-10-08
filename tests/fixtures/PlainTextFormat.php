<?php

namespace Ernestdefoe\Folio\Tests\fixtures;

use Ernestdefoe\Folio\Export\Document;
use Ernestdefoe\Folio\Format\Format;

/** A format another extension might add through the Folio extender. */
class PlainTextFormat implements Format
{
    public function key(): string
    {
        return 'txt';
    }

    public function label(): string
    {
        return 'Plain text';
    }

    public function icon(): string
    {
        return 'fas fa-file-lines';
    }

    public function supportsAvatars(): bool
    {
        return false;
    }

    public function extension(): string
    {
        return 'txt';
    }

    public function mimeType(): string
    {
        return 'text/plain; charset=utf-8';
    }

    public function render(Document $document): string
    {
        return $document->title.': '.count($document->posts).' posts';
    }
}
