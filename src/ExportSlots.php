<?php

namespace Ernestdefoe\Folio;

use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository;
use Psr\Http\Message\ServerRequestInterface;

/**
 * How many exports may be built AT ONCE. The Throttle limits how often one is
 * started; this limits how many run together, because a long PDF can hold a
 * PHP worker for minutes, and starting one every few seconds would otherwise
 * let a single member occupy every worker the forum has.
 *
 * One at a time per person, a few at a time for the whole forum. Each slot is
 * an atomic cache add() with an expiry, so a worker that dies mid-export frees
 * its slot when the expiry passes instead of holding it forever.
 */
class ExportSlots
{
    public const PER_FORUM = 2;

    /** Longer than the export's own time limit, so a slot outlives its export. */
    public const TTL = 200;

    public function __construct(private Repository $cache)
    {
    }

    /**
     * Claim a slot. Returns a release callback, or the reason it was refused:
     * 'self' (this person already has one running) or 'busy' (the forum does).
     *
     * @return callable|string
     */
    public function claim(User $actor, ServerRequestInterface $request): callable|string
    {
        if ($actor->isAdmin()) {
            return static fn () => null;
        }

        $token = bin2hex(random_bytes(8));
        $mine = 'folio.running.'.($actor->isGuest()
            ? 'ip:'.sha1((string) ($request->getAttribute('ipAddress') ?? ''))
            : 'user:'.$actor->id);

        if (! $this->cache->add($mine, $token, self::TTL)) {
            return 'self';
        }

        for ($i = 0; $i < self::PER_FORUM; $i++) {
            $slot = 'folio.running.slot.'.$i;

            if ($this->cache->add($slot, $token, self::TTL)) {
                return fn () => $this->release([$mine, $slot], $token);
            }
        }

        $this->release([$mine], $token);

        return 'busy';
    }

    /** Only frees keys still holding our token: an expired slot may be someone else's now. */
    private function release(array $keys, string $token): void
    {
        foreach ($keys as $key) {
            if ($this->cache->get($key) === $token) {
                $this->cache->forget($key);
            }
        }
    }
}
