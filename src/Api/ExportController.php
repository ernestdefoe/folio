<?php

namespace Ernestdefoe\Folio\Api;

use Ernestdefoe\Folio\Export\DocumentBuilder;
use Ernestdefoe\Folio\Export\ExportOptions;
use Ernestdefoe\Folio\Export\UnsupportedScript;
use Ernestdefoe\Folio\Format\FormatRegistry;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /api/folio/discussions/{id}?format=pdf&scope=all&authors=1&dates=1&avatars=0 */
class ExportController implements RequestHandlerInterface
{
    public function __construct(
        private FormatRegistry $formats,
        private DocumentBuilder $builder,
        private SettingsRepositoryInterface $settings,
        private TranslatorInterface $translator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $query = $request->getQueryParams();

        // A discussion the reader cannot see is a 404, exactly as if it did
        // not exist — not a 403 that confirms it does.
        $discussion = Discussion::query()
            ->whereVisibleTo($actor)
            ->findOrFail((int) Arr::get($query, 'id'));

        $actor->assertCan('folioExport', $discussion);

        $options = ExportOptions::fromQuery($query);
        $format = $this->formats->get($options->format);

        if ($format === null) {
            throw new ValidationException([
                'format' => $this->translator->trans('ernestdefoe-folio.lib.errors.unknown_format'),
            ]);
        }

        // A long discussion with images is real work for a converter.
        @set_time_limit(180);

        try {
            $bytes = $format->render($this->builder->build($discussion, $actor, $options, $request));
        } catch (UnsupportedScript) {
            // Refused rather than produced wrong: a PDF of empty boxes looks
            // like a broken extension, and Word shows every script correctly.
            throw new ValidationException([
                'format' => $this->translator->trans('ernestdefoe-folio.lib.errors.needs_mpdf'),
            ]);
        }

        $body = new Stream('php://temp', 'wb+');
        $body->write($bytes);
        $body->rewind();

        $name = $this->filename($discussion) . '.' . $format->extension();

        return (new Response($body, 200))
            ->withHeader('Content-Type', $format->mimeType())
            ->withHeader('Content-Length', (string) strlen($bytes))
            ->withHeader('Content-Disposition', $this->disposition($name))
            // What a reader may see is personal to them: never cache it.
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /** The admin's pattern, filled in and made safe to be a filename on any system. */
    private function filename(Discussion $discussion): string
    {
        $pattern = (string) ($this->settings->get('ernestdefoe-folio.filename') ?: '{title}');

        $name = strtr($pattern, [
            '{title}' => (string) $discussion->title,
            '{id}'    => (string) $discussion->id,
            '{date}'  => date('Y-m-d'),
            '{forum}' => (string) $this->settings->get('forum_title'),
        ]);

        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '', $name);
        $name = trim(preg_replace('/\s+/u', ' ', $name), ' .');

        return Str::limit($name !== '' ? $name : 'discussion-' . $discussion->id, 120, '');
    }

    /** RFC 6266: an ASCII name for old clients, the real one for everything else. */
    private function disposition(string $name): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name);
        $ascii = str_replace(['"', '\\'], '', $ascii);

        return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
    }
}
