<?php

namespace App\Libraries;

use TCPDF;

/**
 * Draws certificates onto their template with TCPDF.
 *
 * The template image comes from TemplateImageCache: a local, pre-scaled JPEG,
 * fetched once per template rather than per certificate. TCPDF then reads it
 * straight from disk, and in a combined PDF embeds it once for every page.
 */
class CertificatePDFService
{
    /** Positions are measured in CSS pixels, which is what a browser lays out in. */
    private const POSITION_DPI = 96;

    /** The one-dimensional barcodes TCPDF can draw. */
    private const BARCODE_TYPES = [
        'C39', 'C39+', 'C39E', 'C39E+', 'C93', 'S25', 'S25+',
        'I25', 'I25+', 'C128', 'C128A', 'C128B', 'C128C',
        'EAN2', 'EAN5', 'EAN8', 'EAN13', 'UPCA', 'UPCE',
        'MSI', 'MSI+', 'POSTNET', 'PLANET', 'RMS4CC', 'KIX', 'IMB', 'CODABAR', 'CODE11',
    ];

    private $pixelsToMm = 0.264583333; // Default 96 DPI factor

    private TemplateImageCache $templates;

    /** Prepared templates already looked up in this request. */
    private array $prepared = [];

    /** Uploaded photos downloaded once per batch. */
    private array $photos = [];

    public function __construct(?TemplateImageCache $templates = null)
    {
        $this->templates = $templates ?? new TemplateImageCache();
    }

    public function __destruct()
    {
        foreach ($this->photos as $photo) {
            if (is_file($photo['path'])) {
                unlink($photo['path']);
            }
        }
    }

    private function hasValidS3Signature(string $query): bool
    {
        parse_str($query, $parameters);

        return isset(
            $parameters['X-Amz-Algorithm'],
            $parameters['X-Amz-Credential'],
            $parameters['X-Amz-Date'],
            $parameters['X-Amz-Expires'],
            $parameters['X-Amz-SignedHeaders'],
            $parameters['X-Amz-Signature']
        ) && (int) $parameters['X-Amz-Expires'] === 86400;
    }

    /**
     * The local copy of a certificate's template, or null when it has none or
     * it cannot be read (the certificate is then drawn without a background
     * rather than not at all).
     */
    private function template(array $certificate): ?array
    {
        $path = (string) ($certificate['template_path'] ?? '');

        if ($path === '') {
            return null;
        }

        if (!array_key_exists($path, $this->prepared)) {
            try {
                $this->prepared[$path] = $this->templates->get($path);
            } catch (\Throwable $e) {
                log_message('error', 'Certificate template unavailable: ' . $e->getMessage());
                $this->prepared[$path] = null;
            }
        }

        return $this->prepared[$path];
    }

    /**
     * Generate a single PDF certificate
     */
    public function generate($certificate, $templateVars, $data, $options = [])
    {
        $pdf = $this->preparePDF($certificate, $templateVars, $data, $options);

        $this->renderTemplate($pdf, $certificate, $templateVars, $data);

        return $pdf->Output('', 'S');
    }

    /**
     * Generate a multi-page PDF for a batch of data
     */
    public function generateBulk($certificate, $templateVars, array $dataRows, $options = [])
    {
        if (empty($dataRows))
            return null;

        // Use the first row to determine page size/orientation
        $firstData = $dataRows[0] ?? [];
        $pdf = $this->preparePDF($certificate, $templateVars, $firstData, $options);

        foreach ($dataRows as $i => $data) {
            if ($i > 0) {
                $pdf->AddPage();
            }
            $this->renderTemplate($pdf, $certificate, $templateVars, $data);
        }

        return $pdf->Output('', 'S');
    }

    /**
     * Common PDF initialization logic
     */
    private function preparePDF($certificate, $templateVars, $data, $options = [])
    {
        $orientation = $certificate['orientation'] ?? 'L';

        $pageSize = 'A4'; // Fallback
        $template = $this->template($certificate);

        // Positions are measured against the artwork, so the scale from a
        // position to a millimetre is set by the artwork either way.
        if ($template !== null) {
            $this->pixelsToMm = 25.4 / self::POSITION_DPI;
            $pageSize = [$template['width'] * $this->pixelsToMm, $template['height'] * $this->pixelsToMm];
        }

        // A size that was asked for wins over the one the artwork implies.
        //
        // An ID card has to come out at 85.6 by 54 millimetres to fit a badge
        // holder, and artwork for one at any printable resolution would
        // otherwise make a page the size of a poster. The positions still refer
        // to the artwork, so they are rescaled to the page that is actually
        // being printed rather than the one the pixels suggest.
        $width  = (float) ($certificate['page_width_mm'] ?? 0);
        $height = (float) ($certificate['page_height_mm'] ?? 0);

        if ($width > 0 && $height > 0) {
            $pageSize = [$width, $height];

            if ($template !== null && $template['width'] > 0) {
                $this->pixelsToMm = $width / $template['width'];
            }
        }

        $pdf = new TCPDF($orientation, 'mm', $pageSize, true, 'UTF-8', false);

        $pdf->SetCreator('Antigravity Certificate System');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetMargins(0, 0, 0);

        // No padding inside a cell. A field is placed at a point, not in a box,
        // and TCPDF's default millimetre of padding pushed every line of text
        // a millimetre right of where it was put.
        $pdf->setCellPaddings(0, 0, 0, 0);

        $pdf->AddPage();

        return $pdf;
    }

    /**
     * Shared rendering logic
     */
    private function renderTemplate(&$pdf, $certificate, $templateVars, $data)
    {
        $pageWidth = $pdf->getPageWidth();
        $pageHeight = $pdf->getPageHeight();

        // A local JPEG: TCPDF embeds it as it is, and reuses it on every page.
        $template = $this->template($certificate);

        if ($template !== null) {
            $pdf->Image($template['path'], 0, 0, $pageWidth, $pageHeight, $template['type'], '', '', false, 300, '', false, false, 0);
        }

        $fontMap = [
            'Arial' => 'helvetica',
            'Helvetica' => 'helvetica',
            'Times' => 'times',
            'Times New Roman' => 'times',
            'Georgia' => 'times',
            'Courier' => 'courier',
            'Courier New' => 'courier'
        ];

        // Add variables
        foreach ($templateVars as $var) {
            $varKey = $var['var_key'];
            $options = $this->optionsOf($var);
            $kind = strtolower((string) ($options['kind'] ?? 'text'));
            $isCode = $kind === 'qrcode' || $kind === 'barcode';
            $valueKey = $isCode
                ? (string) ($options['source_key'] ?? $varKey)
                : $varKey;
            $value = $isCode && ($options['payload_mode'] ?? 'field') === 'custom'
                ? ($options['payload'] ?? '')
                : ($data[$valueKey] ?? ($kind === 'image' ? '' : ($var['label'] ?? '')));

            if ($value === null || $value === '' || $value === [])
                continue;

            // Image answers are structured S3 references, not text values.
            // Their positions and bounds use the same artwork-relative units.
            $x = (float) ($var['x'] ?? 0) * $this->pixelsToMm;
            $y = (float) ($var['y'] ?? 0) * $this->pixelsToMm;

            if ($kind === 'image') {
                $this->placeImage($pdf, $value, $x, $y, $options);

                continue;
            }

            // Date formatting
            if (strpos(strtolower($varKey), 'date') !== false) {
                if (!is_scalar($value)) {
                    continue;
                }

                try {
                    $date = new \DateTime($value);
                    $value = $date->format('F j, Y');
                } catch (\Exception $e) {
                }
            }

            // A field can be printed as a code rather than as its characters.
            // An ID card usually carries at least one — a QR square to scan at
            // a gate, a barcode to swipe — and what is encoded is the field's
            // own value, so nothing new has to be collected to produce one.
            $fontSize = (float) ($var['font_size'] ?? 12);
            $color = $var['color'] ?? '#000000';
            $fontFamily = $var['font_family'] ?? 'Arial';
            $tcpdfFont = $fontMap[$fontFamily] ?? 'helvetica';
            $align = match (strtolower((string) ($var['align'] ?? 'left'))) {
                'center', 'c' => 'C',
                'right', 'r' => 'R',
                default => 'L',
            };

            if ($isCode) {
                if (is_array($value)) {
                    $value = $value['value'] ?? '';
                }

                if (!is_scalar($value)) {
                    continue;
                }
                $value = trim((string) $value);

                if ($value === '') {
                    continue;
                }

                if (strlen($value) > 2000) {
                    throw new \RuntimeException(sprintf(
                        'Code value for "%s" exceeds the 2,000-character limit.',
                        (string) ($var['label'] ?? $varKey)
                    ));
                }
            } elseif (is_array($value)) {
                $value = $value['name'] ?? '';
            }

            if (!is_scalar($value)) {
                continue;
            }

            // Hex to RGB
            $hex = str_replace('#', '', $color);
            if (strlen($hex) == 3) {
                $r = hexdec(str_repeat(substr($hex, 0, 1), 2));
                $g = hexdec(str_repeat(substr($hex, 1, 1), 2));
                $b = hexdec(str_repeat(substr($hex, 2, 1), 2));
            } else {
                $r = hexdec(substr($hex, 0, 2));
                $g = hexdec(substr($hex, 2, 2));
                $b = hexdec(substr($hex, 4, 2));
            }

            if ($isCode) {
                $this->placeCode($pdf, $kind, (string) $value, $x, $y, $options, $r, $g, $b);

                continue;
            }

            $pdf->SetTextColor($r, $g, $b);
            $pdf->SetFont($tcpdfFont, '', $fontSize);

            $this->place($pdf, $value, $x, $y, $align);
        }
    }

    /**
     * A field's extra settings, whatever shape they arrived in.
     *
     * The column holds JSON, but it has been written to by more than one thing
     * over the years and sometimes already comes back decoded.
     *
     * @return array<string, mixed>
     */
    private function optionsOf(array $var): array
    {
        $options = $var['options'] ?? null;

        if (is_array($options)) {
            return $options;
        }

        if (is_string($options) && $options !== '') {
            $decoded = json_decode($options, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * Draws a QR square or a barcode, centred on the point it was placed at.
     *
     * Sized like everything else here: in the artwork's own pixels, so a code
     * keeps its proportions relative to the design whatever the page is
     * printed at. A barcode with no height given gets a third of its width,
     * which is about what a scanner wants; a QR code is square by definition.
     */
    private function placeCode(
        TCPDF $pdf,
        string $kind,
        string $value,
        float $x,
        float $y,
        array $options,
        int $r,
        int $g,
        int $b
    ): void {
        if (trim($value) === '') {
            return;
        }

        $width = ((float) ($options['w'] ?? 0)) * $this->pixelsToMm;

        if ($width <= 0) {
            $width = $kind === 'qrcode' ? 20.0 : 40.0;
        }

        $height = ((float) ($options['h'] ?? 0)) * $this->pixelsToMm;

        if ($height <= 0) {
            $height = $kind === 'qrcode' ? $width : $width / 3;
        }

        // Centred on the marker, as text is.
        $left = $x - ($width / 2);
        $top  = $y - ($height / 2);

        $style = [
            'border'        => false,
            'padding'       => 0,
            'fgcolor'       => [$r, $g, $b],
            'bgcolor'       => false,
            'module_width'  => 1,
            'module_height' => 1,
        ];

        if ($kind === 'qrcode') {
            $level = strtoupper((string) ($options['level'] ?? 'M'));

            if (!in_array($level, ['L', 'M', 'Q', 'H'], true)) {
                $level = 'M';
            }

            $pdf->write2DBarcode($value, 'QRCODE,' . $level, $left, $top, $width, $height, $style, 'N');

            return;
        }

        $symbology = strtoupper((string) ($options['symbology'] ?? 'C128'));

        // Only the symbologies TCPDF can actually draw, so a typo in the
        // layout does not become an exception in the middle of a batch.
        if (!in_array($symbology, self::BARCODE_TYPES, true)) {
            $symbology = 'C128';
        }

        $style['text']     = (bool) ($options['show_text'] ?? false);
        $style['stretch']  = true;
        $style['fontsize'] = 6;

        $pdf->write1DBarcode($value, $symbology, $left, $top, $width, $height, null, $style, 'N');
    }

    /**
     * Draws an uploaded form photo, preserving its aspect ratio inside the
     * artwork-relative box centred on the editor's placement point.
     */
    private function placeImage(TCPDF $pdf, mixed $value, float $x, float $y, array $options): void
    {
        if (!is_array($value) || ($value['_type'] ?? null) !== 'image') {
            throw new \RuntimeException('The selected photo field has no usable image.');
        }

        $url = (string) ($value['url'] ?? '');
        $parsedUrl = parse_url($url);
        $host = is_array($parsedUrl) ? (string) ($parsedUrl['host'] ?? '') : '';

        if (!is_array($parsedUrl)
            || ($parsedUrl['scheme'] ?? null) !== 'https'
            || !preg_match('/^[a-z0-9][a-z0-9.-]*\.s3(?:\.[a-z0-9-]+)?\.amazonaws\.com$/i', $host)
            || isset($parsedUrl['user'])
            || isset($parsedUrl['pass'])
            || isset($parsedUrl['port'])
            || isset($parsedUrl['fragment'])
            || !preg_match('#^/forms/uploads/\d+/[A-Za-z0-9._%-]+$#D', (string) ($parsedUrl['path'] ?? ''))
            || !$this->hasValidS3Signature($parsedUrl['query'] ?? '')) {
            throw new \RuntimeException('The selected photo does not have a valid 24-hour private S3 URL. Resend recipient data from RegX.');
        }

        $fit = strtolower((string) ($options['fit'] ?? 'contain'));
        $fit = $fit === 'cover' ? 'cover' : 'contain';
        $cacheKey = $url . '|' . $fit;

        if (!isset($this->photos[$cacheKey])) {
            $directory = WRITEPATH . 'tmp/certificate-photos';

            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new \RuntimeException('Could not prepare temporary photo storage.');
            }

            $path = tempnam($directory, 'photo_');

            if ($path === false) {
                throw new \RuntimeException('Could not create temporary photo storage.');
            }
            try {
                $this->downloadPublicPhoto($url, $path);

                if (!is_file($path) || filesize($path) > 10 * 1024 * 1024) {
                    throw new \RuntimeException('The selected photo is missing or exceeds the 10 MB limit.');
                }

                $image = getimagesize($path);
                $imageTypes = [
                    IMAGETYPE_JPEG => 'JPG',
                    IMAGETYPE_PNG  => 'PNG',
                    IMAGETYPE_GIF  => 'GIF',
                ];

                if ($image === false || !isset($imageTypes[$image[2]])
                    || $image[0] > 8000 || $image[1] > 8000 || $image[0] * $image[1] > 40000000) {
                    throw new \RuntimeException('The selected photo must be a valid JPEG, PNG, or GIF image.');
                }

                if ($fit === 'cover') {
                    $cropPath = $this->cropPhotoToBox(
                        $path,
                        $directory,
                        $image[0],
                        $image[1],
                        max(1.0, (float) ($options['w'] ?? 96)),
                        max(1.0, (float) ($options['h'] ?? 128))
                    );
                    unlink($path);
                    $path = $cropPath;
                    $image = getimagesize($path);
                    $imageTypes[$image[2]] = 'PNG';
                }

                $this->photos[$cacheKey] = [
                    'path'   => $path,
                    'type'   => $fit === 'cover' ? 'PNG' : $imageTypes[$image[2]],
                    'width'  => $image[0],
                    'height' => $image[1],
                ];
            } catch (\Throwable $error) {
                if (is_file($path)) {
                    unlink($path);
                }

                throw $error;
            }
        }

        $photo = $this->photos[$cacheKey];
        $boxWidth = max(1.0, (float) ($options['w'] ?? 96) * $this->pixelsToMm);
        $boxHeight = max(1.0, (float) ($options['h'] ?? 128) * $this->pixelsToMm);
        $scale = $fit === 'cover'
            ? max($boxWidth / $photo['width'], $boxHeight / $photo['height'])
            : min($boxWidth / $photo['width'], $boxHeight / $photo['height']);
        $width = $photo['width'] * $scale;
        $height = $photo['height'] * $scale;

        $pdf->Image(
            $photo['path'],
            $x - ($width / 2),
            $y - ($height / 2),
            $width,
            $height,
            $photo['type']
        );
    }

    private function cropPhotoToBox(
        string $path,
        string $directory,
        int $sourceWidth,
        int $sourceHeight,
        float $boxWidth,
        float $boxHeight
    ): string {
        if (!function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('GD is required to crop a photo to its placement box.');
        }

        $sourceBytes = file_get_contents($path);
        $source = $sourceBytes === false ? false : imagecreatefromstring($sourceBytes);

        if ($source === false) {
            throw new \RuntimeException('Could not prepare the selected photo for cropping.');
        }

        $targetRatio = $boxWidth / $boxHeight;
        $sourceRatio = $sourceWidth / $sourceHeight;

        if ($sourceRatio > $targetRatio) {
            $cropHeight = $sourceHeight;
            $cropWidth = (int) round($sourceHeight * $targetRatio);
            $sourceX = (int) floor(($sourceWidth - $cropWidth) / 2);
            $sourceY = 0;
        } else {
            $cropWidth = $sourceWidth;
            $cropHeight = (int) round($sourceWidth / $targetRatio);
            $sourceX = 0;
            $sourceY = (int) floor(($sourceHeight - $cropHeight) / 2);
        }

        $cropped = imagecreatetruecolor($cropWidth, $cropHeight);
        imagealphablending($cropped, false);
        imagesavealpha($cropped, true);
        imagecopyresampled(
            $cropped,
            $source,
            0,
            0,
            $sourceX,
            $sourceY,
            $cropWidth,
            $cropHeight,
            $cropWidth,
            $cropHeight
        );

        $cropPath = tempnam($directory, 'crop_');

        if ($cropPath === false || !imagepng($cropped, $cropPath)) {
            imagedestroy($source);
            imagedestroy($cropped);

            if ($cropPath !== false && is_file($cropPath)) {
                unlink($cropPath);
            }

            throw new \RuntimeException('Could not crop the selected photo.');
        }

        imagedestroy($source);
        imagedestroy($cropped);

        return $cropPath;
    }

    /**
     * Draws a line of text centred on the point it was placed at.
     *
     * The marker in the layout editor sits on its own centre — you drag a
     * label and the label straddles the cursor — so that is what the position
     * has always meant, and the renderer now means the same thing by it.
     *
     * Three things were in the way.
     *
     * Cell() refuses to be shorter than one line: a height of zero quietly
     * becomes the font's line height, and the text was then centred inside
     * that invented box rather than on the point. The error grew with the font
     * size, which is backwards — a large field is one somebody placed with
     * more care, not less.
     *
     * Alignment was measured against the paper. Asking for centred text
     * centred it between the field and the far edge of the page, which is not
     * what choosing "centre" on a dragged marker means. Here the marker is the
     * anchor: left starts at it, centre straddles it, right ends at it.
     *
     * And aligning to the top of a line is not the same as aligning to the
     * top of the letters: TCPDF's line top is the ascender, high enough to
     * clear accents no capital reaches, which still left the text sitting low.
     *
     * Asking for a box of no height and centred contents is what does it. The
     * arithmetic is TCPDF's own, against the font's real ascent and descent —
     * worth noting because getFontAscent() is not: it re-resolves the font by
     * name and hands back a figure for a different one, 2.09mm where the font
     * in use ascends 5.91mm. Anything built on that number is quietly wrong.
     */
    private function place(TCPDF $pdf, string $value, float $x, float $y, string $align): void
    {
        $width = $pdf->GetStringWidth($value);

        $left = match ($align) {
            'C' => $x - ($width / 2),
            'R' => $x - $width,
            default => $x,
        };

        $pdf->SetXY($left, $y);

        // ignore_min_height keeps the height at nothing, and centring within
        // nothing puts the middle of the em box on the point.
        $pdf->Cell($width, 0, $value, 0, 0, 'L', false, '', 0, true, 'T', 'M'        );
    }

    private function downloadPublicPhoto(string $url, string $path): void
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new \RuntimeException('Could not open temporary photo storage.');
        }

        $size = 0;
        $tooLarge = false;
        $curl = curl_init($url);

        if ($curl === false) {
            fclose($handle);
            throw new \RuntimeException('Could not initialize the photo download.');
        }

        try {
            curl_setopt_array($curl, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($handle, &$size, &$tooLarge): int {
                    $size += strlen($chunk);

                    if ($size > 10 * 1024 * 1024) {
                        $tooLarge = true;
                        return 0;
                    }

                    $written = fwrite($handle, $chunk);
                    return $written === false ? 0 : $written;
                },
            ]);

            $downloaded = curl_exec($curl);
            $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

            if ($tooLarge) {
                throw new \RuntimeException('The selected photo exceeds the 10 MB limit.');
            }

            if ($downloaded === false || $statusCode < 200 || $statusCode >= 300) {
                if ($statusCode === 403) {
                    throw new \RuntimeException(
                        'S3 denied access to the certificate photo. The private photo link may have expired; resend recipient data from RegX and generate within 24 hours.'
                    );
                }

                if ($statusCode === 404) {
                    throw new \RuntimeException('The uploaded form photo was not found in S3. Re-upload it in the form and resend recipient data.');
                }

                throw new \RuntimeException(sprintf(
                    'Could not fetch the selected photo from its public S3 URL (HTTP %d).',
                    $statusCode
                ));
            }
        } finally {
            curl_close($curl);
            fclose($handle);
        }
    }
}
