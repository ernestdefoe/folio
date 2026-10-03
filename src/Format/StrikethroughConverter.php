<?php

namespace Ernestdefoe\Folio\Format;

use League\HTMLToMarkdown\Converter\ConverterInterface;
use League\HTMLToMarkdown\ElementInterface;

/** ~~struck~~ text: the converter has no rule for it and dropped the strike silently. */
class StrikethroughConverter implements ConverterInterface
{
    public function convert(ElementInterface $element): string
    {
        $value = $element->getValue();

        return trim($value) === '' ? $value : '~~' . $value . '~~';
    }

    public function getSupportedTags(): array
    {
        return ['del', 's', 'strike'];
    }
}
