<?php

namespace Ernestdefoe\Folio\Export;

use Carbon\Carbon;

/** A discussion as it will be exported: everything a format needs, and nothing it must look up. */
class Document
{
    /** @param ExportedPost[] $posts */
    public function __construct(
        public readonly string $title,
        public readonly string $url,
        public readonly string $forumTitle,
        public readonly string $forumUrl,
        public readonly string $primaryColor,
        public readonly Carbon $exportedAt,
        public readonly array $posts,
        /** Posts the reader could see but the size limit left out. 0 when nothing was cut. */
        public readonly int $omitted,
        public readonly ExportOptions $options,
        public readonly string $header,
        public readonly string $footer,
        public readonly string $customCss,
        public readonly string $paper,
        public readonly string $locale,
    ) {
    }

    /** Header/footer placeholders, filled in. */
    public function fill(string $text): string
    {
        return strtr($text, [
            '{forum}' => $this->forumTitle,
            '{title}' => $this->title,
            '{date}'  => $this->exportedAt->isoFormat('LL'),
            '{url}'   => $this->url,
        ]);
    }
}
