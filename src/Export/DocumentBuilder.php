<?php

namespace Ernestdefoe\Folio\Export;

use Carbon\Carbon;
use Ernestdefoe\Folio\Event\PreparingPost;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\Config;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Http\Message\ServerRequestInterface;

class DocumentBuilder
{
    public function __construct(
        private SettingsRepositoryInterface $settings,
        private Config $config,
        private UrlGenerator $url,
        private Dispatcher $events,
        private HtmlCleaner $cleaner,
        private TranslatorInterface $translator,
    ) {
    }

    public function build(Discussion $discussion, User $actor, ExportOptions $options, ?ServerRequestInterface $request = null): Document
    {
        $max = max(1, (int) $this->settings->get('ernestdefoe-folio.max_posts', 500));
        $limit = $options->firstPostOnly ? 1 : $max;

        /*
         * 🚨 whereVisibleTo, every time. This is the whole of Folio's privacy:
         * an export contains exactly the posts this reader could scroll to —
         * no private posts, no hidden ones unless they may see hidden ones,
         * nothing from a discussion they cannot open. Querying the discussion's
         * posts directly would put all of them in the file.
         */
        $visible = Post::query()
            ->whereVisibleTo($actor)
            ->where('discussion_id', $discussion->id)
            ->where('type', 'comment');

        $total = (clone $visible)->count();

        $posts = $visible
            ->with('user')
            ->orderBy('number')
            ->limit($limit)
            ->get();

        $base = rtrim((string) $this->config->url(), '/');
        $deleted = $this->translator->trans('ernestdefoe-folio.lib.deleted_user');
        $exported = [];

        foreach ($posts as $post) {
            if (! $post instanceof CommentPost) {
                continue;
            }

            $event = new PreparingPost($post, $post->formatContent($request), $options);
            $this->events->dispatch($event);

            if ($event->skip) {
                continue;
            }

            $user = $post->user;

            $exported[] = new ExportedPost(
                number: (int) $post->number,
                author: $user->display_name ?? $deleted,
                avatarUrl: $user?->avatar_url,
                createdAt: $post->created_at ? Carbon::parse($post->created_at) : null,
                editedAt: $post->edited_at ? Carbon::parse($post->edited_at) : null,
                html: $this->cleaner->clean($event->html, $base),
            );
        }

        return new Document(
            title: (string) $discussion->title,
            url: $this->url->to('forum')->route('discussion', ['id' => $discussion->id.($discussion->slug ? '-'.$discussion->slug : '')]),
            forumTitle: (string) $this->settings->get('forum_title'),
            forumUrl: $base,
            primaryColor: $this->color((string) $this->settings->get('theme_primary_color')),
            exportedAt: Carbon::now(),
            posts: $exported,
            omitted: $options->firstPostOnly ? 0 : max(0, $total - $max),
            options: $options,
            header: (string) $this->settings->get('ernestdefoe-folio.header', ''),
            footer: (string) $this->settings->get('ernestdefoe-folio.footer', ''),
            customCss: (string) $this->settings->get('ernestdefoe-folio.custom_css', ''),
            paper: $this->settings->get('ernestdefoe-folio.paper') === 'letter' ? 'letter' : 'a4',
            locale: (string) ($this->translator->getLocale() ?: 'en'),
        );
    }

    /** The forum's primary colour if it is a plain hex colour, which is all the converters understand. */
    private function color(string $value): string
    {
        return preg_match('/^#([0-9a-f]{3}){1,2}$/i', trim($value)) ? trim($value) : '#4d698e';
    }
}
