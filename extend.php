<?php

/*
 * Folio — export a discussion as a PDF, a Word document or Markdown.
 */

use Ernestdefoe\Folio\Api\ExportController;
use Ernestdefoe\Folio\Format\FormatRegistry;
use Ernestdefoe\Folio\Throttle;
use Flarum\Api\Context;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js'),

    new Extend\Locales(__DIR__ . '/locale'),

    (new Extend\ServiceProvider())
        ->register(\Ernestdefoe\Folio\FolioServiceProvider::class),

    (new Extend\Settings())
        ->default('ernestdefoe-folio.format_pdf', true)
        ->default('ernestdefoe-folio.format_docx', true)
        ->default('ernestdefoe-folio.format_markdown', true)
        ->default('ernestdefoe-folio.paper', 'a4')
        ->default('ernestdefoe-folio.max_posts', 500)
        ->default('ernestdefoe-folio.filename', '{title}')
        ->default('ernestdefoe-folio.remote_images', false),

    /*
     * 🚨 Asked through the policy, not hasPermission(). With flarum/tags the
     * permission is granted per tag, and only `can()` on the discussion reaches
     * the tags policy; a bare hasPermission() would read the global grant and
     * answer for the wrong tag.
     */
    (new Extend\ApiResource(DiscussionResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('canFolioExport')
                ->get(fn ($discussion, Context $context) => $context->getActor()->can('folioExport', $discussion)),
        ]),

    // The formats the reader can pick, in order — including any another
    // extension registered — so the modal never offers one the server refuses.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Arr::make('folioFormats')
                ->get(fn () => resolve(FormatRegistry::class)->describeEnabled()),
            // Admin page only: whether PDFs can set CJK, Arabic, Hebrew and the like.
            Schema\Boolean::make('folioMpdf')
                ->visible(fn ($model, Context $context) => $context->getActor()->isAdmin())
                ->get(fn () => \Ernestdefoe\Folio\Export\ComplexScripts::engineAvailable()),
        ]),

    (new Extend\Routes('api'))
        ->get('/folio/discussions/{id}', 'folio.export', ExportController::class),

    (new Extend\ThrottleApi())
        ->set('folio-export', Throttle::class),
];
