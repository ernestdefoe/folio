<?php

namespace Ernestdefoe\Folio;

use Ernestdefoe\Folio\Format\DocxFormat;
use Ernestdefoe\Folio\Format\FormatRegistry;
use Ernestdefoe\Folio\Format\MarkdownFormat;
use Ernestdefoe\Folio\Format\PdfFormat;
use Flarum\Foundation\AbstractServiceProvider;

class FolioServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(FormatRegistry::class, function ($container) {
            return new FormatRegistry($container, $container->make('flarum.settings'), [
                PdfFormat::class,
                DocxFormat::class,
                MarkdownFormat::class,
            ]);
        });
    }
}
