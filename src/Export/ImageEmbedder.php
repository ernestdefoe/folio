<?php

namespace Ernestdefoe\Folio\Export;

use Flarum\Foundation\Config;
use Flarum\Foundation\Paths;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * Puts the images INTO the document, for the formats that carry their own
 * (PDF, Word): a file someone saves and opens next year cannot rely on the
 * forum still serving the picture.
 *
 *   - the forum's own files are read from disk, never over HTTP;
 *   - the forum's own uploads stored elsewhere (fof/upload on S3, R2, a CDN)
 *     are recognised by their row in fof/upload's table, and fetched;
 *   - avatars are fetched: the forum generated those addresses, not a poster;
 *   - emoji images come from their public CDN, so a post keeps its 👍;
 *   - other sites' images only when the admin allowed it, and then through
 *     SafeFetcher;
 *   - anything else becomes a link to where the image was, so nothing is lost
 *     silently.
 */
class ImageEmbedder
{
    /** Emoji renderers point at these. Fixed, public, and tiny files. */
    private const EMOJI_HOSTS = ['cdn.jsdelivr.net', 'twemoji.maxcdn.com', 'cdnjs.cloudflare.com'];

    private const MAX_LOCAL_BYTES = 10 * 1024 * 1024;

    /** Wider than this is wider than the page; keeping the extra only costs time and megabytes. */
    private const MAX_WIDTH = 1400;

    /** Above this many pixels a PNG is flattened to JPEG (see prepare()). */
    private const FLATTEN_PIXELS = 400_000;

    /** @var array<string, bool> address => is a fof/upload file */
    private array $uploadMemo = [];

    private ?bool $hasUploadsTable = null;

    public function __construct(
        private Paths $paths,
        private Config $config,
        private SettingsRepositoryInterface $settings,
        private SafeFetcher $fetcher,
        private ConnectionInterface $db,
    ) {
    }

    public function embed(string $html, string $missingLabel): string
    {
        $dom = Html::parse($html);
        $imgs = Html::all($dom, 'img');
        $uploads = $this->knownUploads(array_map(fn ($img) => $img->getAttribute('src'), $imgs));

        foreach ($imgs as $img) {
            $src = $img->getAttribute('src');
            $isEmoji = str_contains(' ' . $img->getAttribute('class') . ' ', ' emoji ');
            $data = $this->dataFor($src, $isEmoji, isset($uploads[$src]));

            if ($data !== null) {
                $img->setAttribute('src', $data);
                continue;
            }

            // An emoji falls back to its own character; any other image to a link.
            $alt = trim($img->getAttribute('alt'));

            if ($isEmoji && $alt !== '') {
                $img->parentNode?->replaceChild($dom->createTextNode($alt), $img);
                continue;
            }

            $a = $dom->createElement('a');
            if (preg_match('#^https?://#i', $src)) {
                $a->setAttribute('href', $src);
            }
            $a->appendChild($dom->createTextNode('[' . ($alt !== '' ? $alt : $missingLabel) . ']'));
            $img->parentNode?->replaceChild($a, $img);
        }

        return Html::inner($dom);
    }

    /**
     * Download every image a document will need, in parallel, before the
     * converter asks for them one at a time.
     */
    /**
     * @param string[] $bodies the post HTML exactly as it will be embedded
     * @param string[] $avatars
     */
    public function prefetch(array $bodies, array $avatars = []): void
    {
        $srcs = $emoji = [];

        foreach ($bodies as $html) {
            foreach (Html::all(Html::parse($html), 'img') as $img) {
                $src = $img->getAttribute('src');
                str_contains(' ' . $img->getAttribute('class') . ' ', ' emoji ') ? $emoji[] = $src : $srcs[] = $src;
            }
        }

        // Emoji too: embed() asks about every <img>, and an address looked up
        // here is one it never has to query for.
        $uploads = $this->knownUploads(array_merge($srcs, $emoji));
        $wanted = [];

        foreach ($srcs as $src) {
            if ($this->localFile($src) === null && $this->mayFetch($src, false, isset($uploads[$src]))) {
                $wanted[] = $src;
            }
        }
        foreach ($emoji as $src) {
            if ($this->mayFetch($src, true, false)) {
                $wanted[] = $src;
            }
        }
        foreach (array_unique(array_filter($avatars)) as $avatar) {
            if ($this->localFile($avatar) === null) {
                $wanted[] = $avatar;
            }
        }

        $this->fetcher->fetchMany($wanted);
    }

    private function mayFetch(string $src, bool $isEmoji, bool $trusted): bool
    {
        $host = strtolower((string) parse_url($src, PHP_URL_HOST));

        return $trusted
            || $isEmoji && in_array($host, self::EMOJI_HOSTS, true)
            || (bool) $this->settings->get('ernestdefoe-folio.remote_images');
    }

    /** One image address as a `data:` URI, or null if it may not or cannot be embedded. */
    public function dataFor(string $src, bool $isEmoji = false, bool $trusted = false): ?string
    {
        if (str_starts_with($src, 'data:')) {
            return SafeFetcher::dataUri((string) base64_decode(preg_replace('#^data:[^,]*,#', '', $src)));
        }

        $local = $this->localFile($src);
        if ($local !== null) {
            $bytes = @file_get_contents($local);

            return $bytes === false ? null : $this->prepare(SafeFetcher::dataUri($bytes));
        }

        return $this->mayFetch($src, $isEmoji, $trusted) ? $this->prepare($this->fetcher->fetch($src)) : null;
    }

    /**
     * Which of these addresses are files uploaded through fof/upload.
     *
     * An exact match on the URL fof/upload itself recorded, so "it is one of
     * ours" cannot be claimed by a look-alike address. Without fof/upload the
     * table is absent and nothing is trusted this way.
     *
     * @param string[] $srcs
     * @return array<string, true>
     */
    private function knownUploads(array $srcs): array
    {
        $srcs = array_values(array_unique(array_filter($srcs, fn ($s) => preg_match('#^https?://#i', $s))));

        /*
         * 🚨 Memoised per export. embed() runs once per POST, so asking the
         * schema and the table every time cost two queries per post with an
         * image — up to a thousand on a long thread. prefetch() has already
         * looked up every address the document holds, so the per-post calls
         * are answered from here without touching the database.
         */
        $unknown = array_values(array_filter($srcs, fn ($s) => ! array_key_exists($s, $this->uploadMemo)));

        if ($unknown !== [] && $this->hasUploadsTable()) {
            foreach (array_chunk($unknown, 500) as $chunk) {
                $found = array_fill_keys($this->db->table('fof_upload_files')->whereIn('url', $chunk)->pluck('url')->all(), true);
                foreach ($chunk as $src) {
                    $this->uploadMemo[$src] = isset($found[$src]);
                }
            }
        }

        $known = [];
        foreach ($srcs as $src) {
            if ($this->uploadMemo[$src] ?? false) {
                $known[$src] = true;
            }
        }

        return $known;
    }

    private function hasUploadsTable(): bool
    {
        return $this->hasUploadsTable ??= $this->db->getSchemaBuilder()->hasTable('fof_upload_files');
    }

    /**
     * The file under public/ that a URL on the forum's own address points at.
     *
     * 🚨 Resolved with realpath and required to stay inside public/: a post can
     * contain `/assets/../../config.php`, and "it was on our own host" must not
     * become a way of reading files the web server would never serve.
     */
    private function localFile(string $src): ?string
    {
        $forum = parse_url((string) $this->config->url());
        $url = parse_url($src);

        if (! isset($url['host'], $forum['host']) || strcasecmp($url['host'], $forum['host']) !== 0) {
            return null;
        }

        $path = rawurldecode($url['path'] ?? '');
        $basePath = rtrim($forum['path'] ?? '', '/');
        if ($basePath !== '' && str_starts_with($path, $basePath . '/')) {
            $path = substr($path, strlen($basePath));
        }

        $public = realpath($this->paths->public);
        $file = realpath($this->paths->public . $path);

        if ($public === false || $file === false || ! str_starts_with($file, $public . DIRECTORY_SEPARATOR) || ! is_file($file)) {
            return null;
        }

        return filesize($file) <= self::MAX_LOCAL_BYTES ? $file : null;
    }

    /**
     * The image made fit for a document.
     *
     *   - WebP becomes PNG: Word cannot show it, and dompdf only can when PHP's
     *     GD was built with it.
     *   - Wider than the page is scaled down to it.
     *   - 🚨 A large PNG is flattened onto white and saved as JPEG. dompdf
     *     separates a PNG's transparency pixel by pixel IN PHP: a thread of
     *     full-size screenshots took 18 seconds in the converter alone. Paper
     *     has no use for transparency; small PNGs (icons, emoji) keep theirs.
     *   - GIF is left alone.
     */
    private function prepare(?string $dataUri): ?string
    {
        if ($dataUri === null || str_starts_with($dataUri, 'data:image/gif')) {
            return $dataUri;
        }

        $isWebp = str_starts_with($dataUri, 'data:image/webp');

        if (! function_exists('imagecreatefromstring')) {
            return $isWebp ? null : $dataUri;
        }

        $bytes = (string) base64_decode(substr($dataUri, strpos($dataUri, ',') + 1));
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return $isWebp ? null : $dataUri;
        }

        $w = imagesx($image);
        $h = imagesy($image);
        $resized = false;

        if ($w > self::MAX_WIDTH) {
            $scaled = imagescale($image, self::MAX_WIDTH, (int) round($h * self::MAX_WIDTH / $w), IMG_BICUBIC);
            if ($scaled !== false) {
                $image = $scaled;
                [$w, $h] = [imagesx($image), imagesy($image)];
                $resized = true;
            }
        }

        $isJpeg = str_starts_with($dataUri, 'data:image/jpeg');

        if ($isJpeg || $w * $h > self::FLATTEN_PIXELS) {
            if (! $isJpeg) {
                $flat = imagecreatetruecolor($w, $h);
                imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
                imagecopy($flat, $image, 0, 0, 0, 0, $w, $h);
                $image = $flat;
            } elseif (! $resized) {
                return $dataUri; // A JPEG that already fits: leave its bytes alone.
            }

            ob_start();
            imagejpeg($image, null, 90);

            return 'data:image/jpeg;base64,' . base64_encode((string) ob_get_clean());
        }

        if (! $isWebp && ! $resized) {
            return $dataUri;
        }

        imagesavealpha($image, true);
        ob_start();
        imagepng($image, null, 6);

        return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    }
}
