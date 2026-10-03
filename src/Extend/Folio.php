<?php

namespace Ernestdefoe\Folio\Extend;

use Ernestdefoe\Folio\Format\FormatRegistry;
use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Illuminate\Contracts\Container\Container;

/**
 * For other extensions. Add an export format:
 *
 *     (new \Ernestdefoe\Folio\Extend\Folio())->format(MyFormat::class),
 *
 * To change what a post looks like in an export (a poll, an event card), listen
 * for \Ernestdefoe\Folio\Event\PreparingPost instead.
 */
class Folio implements ExtenderInterface
{
    /** @var array<int, class-string> */
    private array $formats = [];

    /** @param class-string<\Ernestdefoe\Folio\Format\Format> $class */
    public function format(string $class): self
    {
        $this->formats[] = $class;

        return $this;
    }

    public function extend(Container $container, ?Extension $extension = null): void
    {
        $formats = $this->formats;

        $container->extend(FormatRegistry::class, function (FormatRegistry $registry) use ($formats) {
            foreach ($formats as $class) {
                $registry->add($class);
            }

            return $registry;
        });
    }
}
