<?php

namespace Ernestdefoe\Folio\Export;

use Carbon\Carbon;

/** One post, ready for a format to lay out. */
class ExportedPost
{
    public function __construct(
        public readonly int $number,
        public readonly string $author,
        public readonly ?string $avatarUrl,
        public readonly ?Carbon $createdAt,
        public readonly ?Carbon $editedAt,
        /** The post's HTML: cleaned, links made absolute, nothing that runs. */
        public readonly string $html,
    ) {
    }
}
