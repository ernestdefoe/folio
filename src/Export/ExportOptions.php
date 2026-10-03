<?php

namespace Ernestdefoe\Folio\Export;

/** What the reader picked in the export dialog. */
class ExportOptions
{
    public function __construct(
        public readonly string $format,
        public readonly bool $firstPostOnly = false,
        public readonly bool $authors = true,
        public readonly bool $dates = true,
        public readonly bool $avatars = false,
    ) {
    }

    /** Anything missing or unrecognised falls back to the dialog's defaults. */
    public static function fromQuery(array $query): self
    {
        $flag = fn (string $key, bool $default) => array_key_exists($key, $query)
            ? filter_var($query[$key], FILTER_VALIDATE_BOOLEAN)
            : $default;

        return new self(
            format: strtolower(trim((string) ($query['format'] ?? 'pdf'))),
            firstPostOnly: ($query['scope'] ?? 'all') === 'first',
            authors: $flag('authors', true),
            dates: $flag('dates', true),
            avatars: $flag('avatars', false),
        );
    }
}
