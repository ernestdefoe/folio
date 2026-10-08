<?php

namespace Ernestdefoe\Folio\Export;

/**
 * Downloads an image for embedding, and refuses anything that could turn an
 * export into a way of probing the server's own network.
 *
 * 🚨 The URLs come from posts, which anyone can write. Fetching them blindly is
 * a server-side request forgery: `<img src="http://169.254.169.254/...">` or
 * `http://localhost:6379` would be requested FROM the forum's server. So:
 *
 *   - http and https only, default ports only;
 *   - the host must resolve, and to public addresses only;
 *   - the connection is pinned to the address that was checked (CURLOPT_RESOLVE),
 *     so a DNS answer that changes between the check and the fetch cannot
 *     smuggle a private address in;
 *   - no redirects, which would skip every check above;
 *   - a short timeout, a size cap, and the reply must say it is an image.
 */
class SafeFetcher
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    /** @var array<string, ?string> */
    private array $memo = [];

    /** A `data:` URI for the image, or null when it was refused or failed. */
    public function fetch(string $url): ?string
    {
        if (array_key_exists($url, $this->memo)) {
            return $this->memo[$url];
        }

        return $this->memo[$url] = $this->attempt($url);
    }

    /**
     * Fetch several at once. A thread with thirty screenshots otherwise waits
     * on thirty downloads in a row before the converter can even start.
     *
     * @param string[] $urls
     */
    public function fetchMany(array $urls, int $concurrency = 6): void
    {
        $pending = [];
        foreach (array_unique($urls) as $url) {
            if (array_key_exists($url, $this->memo)) {
                continue;
            }
            $handle = $this->handle($url);
            if ($handle === null) {
                $this->memo[$url] = null;
                continue;
            }
            $pending[$url] = $handle;
        }

        foreach (array_chunk($pending, $concurrency, true) as $batch) {
            $multi = curl_multi_init();
            foreach ($batch as [$ch]) {
                curl_multi_add_handle($multi, $ch);
            }

            do {
                $status = curl_multi_exec($multi, $running);
                if ($running) {
                    curl_multi_select($multi, 1.0);
                }
            } while ($running && $status === CURLM_OK);

            foreach ($batch as $url => [$ch, $body]) {
                $this->memo[$url] = $this->finish($ch, $body->value);
                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
            }
            curl_multi_close($multi);
        }
    }

    private function attempt(string $url): ?string
    {
        $handle = $this->handle($url);
        if ($handle === null) {
            return null;
        }

        [$ch, $body] = $handle;
        curl_exec($ch);
        $result = $this->finish($ch, $body->value);
        curl_close($ch);

        return $result;
    }

    /**
     * A configured cURL handle for a URL that passed every check, or null.
     *
     * @return array{0: \CurlHandle, 1: object{value: string}}|null
     */
    private function handle(string $url): ?array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user'])) {
            return null;
        }

        $port = $scheme === 'https' ? 443 : 80;
        if (isset($parts['port']) && (int) $parts['port'] !== $port) {
            return null;
        }

        $ip = $this->publicAddress($host);
        if ($ip === null || ! function_exists('curl_init')) {
            return null;
        }

        $body = new class {
            public string $value = '';
        };
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => [$host.':'.$port.':'.$ip],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_USERAGENT => 'Folio (Flarum discussion export)',
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use ($body) {
                $body->value .= $chunk;

                // Returning less than was given aborts the transfer.
                return strlen($body->value) > self::MAX_BYTES ? 0 : strlen($chunk);
            },
        ]);

        return [$ch, $body];
    }

    private function finish(\CurlHandle $ch, string $body): ?string
    {
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($status !== 200 || $body === '' || strlen($body) > self::MAX_BYTES) {
            return null;
        }

        return self::dataUri($body);
    }

    /** The host's first address, if every address it has is a public one. */
    private function publicAddress(string $host): ?string
    {
        $host = rtrim(trim($host, '[]'), '.');

        /*
         * 🚨 A host whose last label is a number IS an address, in whatever
         * form: 0177.0.0.1 (octal), 0x7f.1 (hex), 2130706433 or 127.1 all
         * mean 127.0.0.1 to cURL and to the resolver. Only the plain
         * dotted-quad is reasoned about; every other spelling is refused.
         */
        $last = substr(strrchr('.'.$host, '.'), 1);
        if (preg_match('/^(0x[0-9a-f]*|[0-9]+)$/i', $last) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ($ips === []) {
            return null;
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE)) {
                return null;
            }
        }

        // IPv6 literals are refused rather than reasoned about.
        return filter_var($ips[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ips[0] : null;
    }

    /**
     * The bytes as a `data:` URI, typed by what they ARE, not by what the
     * server claimed. SVG is refused: it is a document that can carry script
     * and pull in further URLs, and none of the formats needs it.
     */
    public static function dataUri(string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);
        $mime = $info['mime'] ?? '';

        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
