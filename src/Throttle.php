<?php

namespace Ernestdefoe\Folio;

use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository;
use Psr\Http\Message\ServerRequestInterface;

/**
 * One export every few seconds per person. Building a PDF of a long thread is
 * real CPU, and a button held down — or a script — should not be able to spend
 * the server's whole capacity on it.
 */
class Throttle
{
    private const SECONDS = 8;

    public function __construct(private Repository $cache)
    {
    }

    public function __invoke(ServerRequestInterface $request): ?bool
    {
        if ($request->getAttribute('routeName') !== 'folio.export') {
            return null; // Not ours: no opinion.
        }

        $actor = RequestUtil::getActor($request);

        if ($actor->isAdmin()) {
            return null;
        }

        $who = $actor->isGuest()
            ? 'ip:' . sha1((string) ($request->getAttribute('ipAddress') ?? ''))
            : 'user:' . $actor->id;

        // add() is atomic: true for the first request in the window, false after.
        return $this->cache->add('folio.throttle.' . $who, 1, self::SECONDS) ? null : true;
    }
}
