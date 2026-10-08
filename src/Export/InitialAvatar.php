<?php

namespace Ernestdefoe\Folio\Export;

/**
 * The letter-in-a-circle for a member with no avatar, drawn as an image.
 *
 * 🚨 Not CSS. dompdf will not centre text inside a rounded box: as an
 * inline-block the letter sat on the line's baseline, as a block it sank to the
 * bottom-left — half the letter outside the circle either way. An image looks
 * the same in every PDF and in Word.
 */
final class InitialAvatar
{
    private const SIZE = 96; // drawn large, shown at ~26pt: stays crisp in print

    /** @var array<string, ?string> */
    private static array $memo = [];

    /** A `data:` PNG, or null when GD or the font is unavailable (the caller then leaves the space empty). */
    public static function dataUri(string $name, string $hex): ?string
    {
        $letter = mb_strtoupper(mb_substr(trim($name) !== '' ? trim($name) : '?', 0, 1));
        $key = $letter.$hex;

        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        return self::$memo[$key] = self::draw($letter, $hex);
    }

    private static function draw(string $letter, string $hex): ?string
    {
        $font = self::font();

        if ($font === null || ! function_exists('imagettftext')) {
            return null;
        }

        $hex = ltrim($hex, '#');
        $hex = strlen($hex) === 3 ? preg_replace('/(.)/', '$1$1', $hex) : $hex;
        [$r, $g, $b] = array_map('hexdec', str_split(str_pad($hex, 6, '0'), 2));

        // Drawn at 4x and scaled down: GD's circles have no antialiasing of their own.
        $big = self::SIZE * 4;
        $canvas = imagecreatetruecolor($big, $big);
        imagesavealpha($canvas, true);
        imagealphablending($canvas, false);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagealphablending($canvas, true);
        imagefilledellipse($canvas, $big / 2, $big / 2, $big, $big, imagecolorallocate($canvas, $r, $g, $b));

        // Centre on the glyph's own ink, not its advance box: a font's box
        // includes room for descenders, which is what pushes letters low.
        $size = $big * 0.42;
        $box = imagettfbbox($size, 0, $font, $letter);
        $inkW = $box[2] - $box[0];
        $inkH = $box[1] - $box[7];
        $x = (int) round(($big - $inkW) / 2 - $box[0]);
        $y = (int) round(($big + $inkH) / 2 - $box[1]);
        imagettftext($canvas, $size, 0, $x, $y, imagecolorallocate($canvas, 255, 255, 255), $font, $letter);

        $small = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagesavealpha($small, true);
        imagealphablending($small, false);
        imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
        imagecopyresampled($small, $canvas, 0, 0, 0, 0, self::SIZE, self::SIZE, $big, $big);

        ob_start();
        imagepng($small);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    /** The bold face dompdf ships with: present wherever Folio is installed. */
    private static function font(): ?string
    {
        $dir = dirname((new \ReflectionClass(\Dompdf\Dompdf::class))->getFileName(), 2).'/lib/fonts/';

        foreach (['DejaVuSans-Bold.ttf', 'DejaVuSans.ttf'] as $file) {
            if (is_file($dir.$file)) {
                return $dir.$file;
            }
        }

        return null;
    }
}
