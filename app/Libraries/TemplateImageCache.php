<?php

namespace App\Libraries;

use RuntimeException;

/**
 * A certificate template, fetched once and kept ready for TCPDF.
 *
 * A template is a single background image used on every certificate in a
 * batch, and it usually lives on S3. Handing TCPDF its URL means TCPDF fetches
 * it over the network — once to measure it, again to draw it, and again for
 * every page of a combined PDF — and a large PNG then goes through TCPDF's
 * pixel-by-pixel alpha handling on top. That is what ran generation past the
 * time limit.
 *
 * So it is fetched once, scaled to the size the page is built at anyway, and
 * flattened onto white — a background has no use for transparency, and it is
 * transparency that sends TCPDF down its slow path. It is then kept as a JPEG
 * or an opaque PNG, whichever is smaller: photographs suit JPEG, flat artwork
 * suits PNG, and TCPDF embeds either without decoding it. Every certificate
 * carries a copy, so a smaller template is a smaller bill for every PDF stored.
 *
 * The result is cached by template path, so a re-uploaded template (a new
 * path) is fetched fresh and an unchanged one is never fetched again.
 */
class TemplateImageCache
{
    /** The page is laid out at this size; anything larger is wasted work. */
    public const MAX_PIXELS = 2000;

    private const JPEG_QUALITY = 85;

    /**
     * How big the prepared template may be before it is worth re-encoding.
     *
     * Every certificate embeds its own copy of the template, so this number is
     * multiplied by the size of the batch. A 1.7 MB template makes a hundred
     * 1.7 MB certificates; the same artwork as a 300 KB JPEG makes a hundred
     * 300 KB ones, from the same source and at a quality nobody can tell apart
     * at print size.
     *
     * Below this, leaving the file alone is the right call — re-encoding a
     * small palette PNG only ever makes it bigger.
     */
    private const SIZE_BUDGET = 400 * 1024;

    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? WRITEPATH . 'cache/templates', '/\\') . DIRECTORY_SEPARATOR;
    }

    /**
     * The local, prepared copy of a template.
     *
     * @return array{path: string, type: string, width: int, height: int}
     *
     * @throws RuntimeException when the template cannot be read at all
     */
    public function get(string $templatePath): array
    {
        $key       = sha1($templatePath);
        $base      = $this->dir . $key;
        $sizeFile  = $base . '.json';

        if (is_file($sizeFile)) {
            $cached = json_decode((string) file_get_contents($sizeFile), true);

            if (isset($cached['file'], $cached['width'], $cached['height']) && is_file($this->dir . $cached['file'])) {
                return [
                    'path'   => $this->dir . $cached['file'],
                    'type'   => (string) ($cached['type'] ?? ''),
                    'width'  => (int) $cached['width'],
                    'height' => (int) $cached['height'],
                ];
            }
        }

        if (!is_dir($this->dir) && !mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            throw new RuntimeException('Cannot create the template cache at ' . $this->dir);
        }

        $source = $this->fetch($templatePath, $base . '.src');

        try {
            $result = $this->prepare($source, $base);
        } finally {
            // Only the prepared copy is kept.
            if ($source !== $templatePath && is_file($source)) {
                @unlink($source);
            }
        }

        file_put_contents($sizeFile, json_encode([
            'file'   => basename($result['path']),
            'type'   => $result['type'],
            'width'  => $result['width'],
            'height' => $result['height'],
        ]));

        return $result;
    }

    /** Forget a template, e.g. when it is replaced or deleted. */
    public function forget(string $templatePath): void
    {
        $key = sha1($templatePath);

        foreach (['.jpg', '.png', '.gif', '.json', '.src', '.orig.jpg', '.orig.png'] as $ext) {
            @unlink($this->dir . $key . $ext);
        }
    }

    /**
     * Bring the template to a local file.
     *
     * A local path is used where it is. Anything on S3 is read with the SDK, so
     * a private bucket works as well as a public one; any other URL is fetched
     * over HTTP with a timeout, rather than left to TCPDF to fetch with none.
     */
    private function fetch(string $templatePath, string $target): string
    {
        if (!filter_var($templatePath, FILTER_VALIDATE_URL)) {
            $local = is_file($templatePath)
                ? $templatePath
                : WRITEPATH . str_replace('writable/', '', ltrim($templatePath, '/'));

            if (!is_file($local)) {
                throw new RuntimeException('Template image not found: ' . $templatePath);
            }

            return $local;
        }

        $s3 = new S3Service();

        if ($s3->isEnabled() && $s3->ownsUrl($templatePath)) {
            $s3->downloadToLocal($templatePath, $target);

            return $target;
        }

        $context = stream_context_create(['http' => ['timeout' => 20], 'https' => ['timeout' => 20]]);
        $bytes   = @file_get_contents($templatePath, false, $context);

        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Could not download the template image.');
        }

        file_put_contents($target, $bytes);

        return $target;
    }

    /**
     * Scale to at most MAX_PIXELS on the long side, flatten onto white, and
     * keep the smaller of a JPEG and an opaque PNG. If GD cannot read it, the
     * original is kept as it is, under its own extension, so TCPDF is never
     * told a PNG is a JPEG.
     *
     * @return array{path: string, type: string, width: int, height: int}
     */
    private function prepare(string $source, string $base): array
    {
        $info = @getimagesize($source);

        if ($info === false) {
            throw new RuntimeException('The template is not a readable image.');
        }

        [$width, $height] = $info;

        $keepOriginal = function () use ($source, $base, $info, $width, $height): array {
            $ext = match ($info[2]) {
                IMAGETYPE_PNG => 'png',
                IMAGETYPE_GIF => 'gif',
                default       => 'jpg',
            };

            copy($source, "{$base}.{$ext}");

            return ['path' => "{$base}.{$ext}", 'type' => strtoupper($ext), 'width' => $width, 'height' => $height];
        };

        // Small enough, the right dimensions, and a format TCPDF embeds as it
        // is: leave it alone. Re-encoding a compact palette PNG only ever made
        // it bigger.
        //
        // The size test is the point. Without it this shortcut waved through
        // any PNG under two thousand pixels however heavy it was, and a 1.7 MB
        // background was then copied into every single certificate — which is
        // how a hundred certificates came to 175 MB.
        if (
            max($width, $height) <= self::MAX_PIXELS
            && filesize($source) <= self::SIZE_BUDGET
            && $this->embedsAsIs($source, $info[2])
        ) {
            return $keepOriginal();
        }

        if (!function_exists('imagecreatefromstring')) {
            return $keepOriginal();
        }

        // Decoding a large PNG needs width × height × 4 bytes and then some.
        $needed = (int) ($width * $height * 5) + 64 * 1024 * 1024;
        $limit  = $this->bytes((string) ini_get('memory_limit'));

        if ($limit > 0 && $limit < $needed) {
            @ini_set('memory_limit', (string) $needed);
        }

        $image = @imagecreatefromstring((string) file_get_contents($source));

        if ($image === false) {
            return $keepOriginal();
        }

        $scale     = min(1, self::MAX_PIXELS / max($width, $height));
        $newWidth  = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        // Opaque, 8-bit, not interlaced: the PNG TCPDF can embed without GD.
        imagesavealpha($canvas, false);
        imageinterlace($canvas, false);

        imagejpeg($canvas, "{$base}.jpg", self::JPEG_QUALITY);
        imagepng($canvas, "{$base}.png", 9);

        imagedestroy($image);
        imagedestroy($canvas);

        // Whichever is smallest, including leaving it as it was: a re-encode
        // that gains nothing is a loss of quality for no reason. The original
        // only qualifies if it needed no resizing and TCPDF takes it as it is.
        $candidates = [
            'PNG' => "{$base}.png",
            'JPG' => "{$base}.jpg",
        ];

        if ($newWidth === $width && $newHeight === $height && $this->embedsAsIs($source, $info[2])) {
            $ext = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';

            if ($source !== "{$base}.{$ext}" && @copy($source, "{$base}.orig.{$ext}")) {
                $candidates[$info[2] === IMAGETYPE_PNG ? 'PNG' : 'JPG'] = "{$base}.orig.{$ext}";
            }
        }

        $best = null;

        foreach ($candidates as $type => $file) {
            if (is_file($file) && ($best === null || filesize($file) < filesize($best[1]))) {
                $best = [$type, $file];
            }
        }

        if ($best === null) {
            throw new RuntimeException('The template could not be prepared.');
        }

        [$type, $chosen] = $best;

        // One canonical name, so the cache and the cleanup both know it.
        $final = $base . ($type === 'PNG' ? '.png' : '.jpg');

        if ($chosen !== $final) {
            @unlink($final);
            rename($chosen, $final);
        }

        foreach (["{$base}.png", "{$base}.jpg", "{$base}.orig.png", "{$base}.orig.jpg"] as $leftover) {
            if ($leftover !== $final && is_file($leftover)) {
                @unlink($leftover);
            }
        }

        return ['path' => $final, 'type' => $type, 'width' => $newWidth, 'height' => $newHeight];
    }

    /**
     * Whether TCPDF can copy this image straight into the PDF.
     *
     * A baseline JPEG always can. A PNG can when it has no transparency (no
     * alpha channel, no tRNS chunk), is not interlaced and is at most 8 bits a
     * channel — anything else sends TCPDF through GD, pixel by pixel.
     */
    private function embedsAsIs(string $file, int $type): bool
    {
        if ($type === IMAGETYPE_JPEG) {
            return true;
        }

        if ($type !== IMAGETYPE_PNG) {
            return false;
        }

        $f = @fopen($file, 'rb');

        if ($f === false) {
            return false;
        }

        try {
            fread($f, 8); // signature

            while (!feof($f)) {
                $header = fread($f, 8);

                if (strlen($header) < 8) {
                    return false;
                }

                $length = unpack('N', substr($header, 0, 4))[1];
                $chunk  = substr($header, 4, 4);

                if ($chunk === 'IHDR') {
                    $ihdr      = fread($f, $length);
                    $bitDepth  = ord($ihdr[8]);
                    $colorType = ord($ihdr[9]);
                    $interlace = ord($ihdr[12]);

                    // 4 and 6 carry an alpha channel.
                    if ($bitDepth > 8 || $interlace !== 0 || in_array($colorType, [4, 6], true)) {
                        return false;
                    }

                    fread($f, 4); // CRC
                    continue;
                }

                if ($chunk === 'tRNS') {
                    return false;
                }

                // Transparency, if any, is declared before the image data.
                if ($chunk === 'IDAT') {
                    return true;
                }

                fseek($f, $length + 4, SEEK_CUR);
            }

            return false;
        } finally {
            fclose($f);
        }
    }

    private function bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => $number,
        };
    }
}
