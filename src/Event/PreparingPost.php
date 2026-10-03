<?php

namespace Ernestdefoe\Folio\Event;

use Ernestdefoe\Folio\Export\ExportOptions;
use Flarum\Post\CommentPost;

/**
 * A post is about to go into an export. `$html` is the post as the forum
 * renders it; change it to change what the export shows.
 *
 * For an extension whose content does not survive as plain HTML — a poll
 * widget, an embedded event — this is where to swap in a static version.
 * Set `$skip = true` to leave the post out altogether.
 */
class PreparingPost
{
    public bool $skip = false;

    public function __construct(
        public readonly CommentPost $post,
        public string $html,
        public readonly ExportOptions $options,
    ) {
    }
}
