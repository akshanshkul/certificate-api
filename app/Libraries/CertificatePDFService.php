<?php

namespace App\Libraries;

use TCPDF;

class CertificatePDFService
{
    private $pixelsToMm = 0.264583333; // Default 96 DPI factor

    /**
     * Generate a single PDF certificate
     */
    public function generate($certificate, $templateVars, $data, $options = [])
    {
        $pdf = $this->preparePDF($certificate, $templateVars, $data, $options);

        $this->renderTemplate($pdf, $certificate, $templateVars, $data);

        // Final metadata
        $this->addMetadata($pdf, $data);

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
            $this->addMetadata($pdf, $data);
        }

        return $pdf->Output('', 'S');
    }

    /**
     * Common PDF initialization logic
     */
    private function preparePDF($certificate, $templateVars, $data, $options = [])
    {
        $orientation = $certificate['orientation'] ?? 'L';

        // Robust page size detection based on image dimensions
        $templatePath = $certificate['template_path'];
        $pageSize = 'A4'; // Fallback

        if ($templatePath) {
            $fullPath = $templatePath;
            if (!filter_var($templatePath, FILTER_VALIDATE_URL)) {
                $fullPath = WRITEPATH . str_replace('writable/', '', $templatePath);
            }

            $imageInfo = @getimagesize($fullPath);
            if ($imageInfo) {
                $imgWidth = $imageInfo[0];
                $imgHeight = $imageInfo[1];

                // Scale down if too large (matching Api\Certificate logic)
                $maxPixels = 2000;
                if ($imgWidth > $maxPixels || $imgHeight > $maxPixels) {
                    $scale = $maxPixels / max($imgWidth, $imgHeight);
                    $imgWidth = intval($imgWidth * $scale);
                    $imgHeight = intval($imgHeight * $scale);
                }

                $dpi = 96;
                $this->pixelsToMm = 25.4 / $dpi;
                $pageSize = array($imgWidth * $this->pixelsToMm, $imgHeight * $this->pixelsToMm);
            }
        }

        $pdf = new TCPDF($orientation, 'mm', $pageSize, true, 'UTF-8', false);

        $pdf->SetCreator('Antigravity Certificate System');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetMargins(0, 0, 0);

        $pdf->AddPage();

        return $pdf;
    }

    /**
     * Shared rendering logic
     */
    private function renderTemplate(&$pdf, $certificate, $templateVars, $data)
    {
        $templatePath = $certificate['template_path'] ?? '';
        $pageWidth = $pdf->getPageWidth();
        $pageHeight = $pdf->getPageHeight();

        // Add template image (MUST USE ABSOLUTE PATH)
        if ($templatePath) {
            $fullPath = $templatePath;
            if (!filter_var($templatePath, FILTER_VALIDATE_URL)) {
                $fullPath = WRITEPATH . str_replace('writable/', '', $templatePath);
            }
            // Use absolute path for TCPDF Image()
            $pdf->Image($fullPath, 0, 0, $pageWidth, $pageHeight, '', '', '', false, 300, '', false, false, 0);
        }

        $fontMap = [
            'Arial' => 'helvetica',
            'Helvetica' => 'helvetica',
            'Times' => 'times',
            'Times New Roman' => 'times',
            'Courier' => 'courier',
            'Courier New' => 'courier'
        ];

        // Add variables
        foreach ($templateVars as $var) {
            $varKey = $var['var_key'];
            $value = $data[$varKey] ?? $var['label'] ?? '';
            if (empty($value))
                continue;

            // Date formatting
            if (strpos(strtolower($varKey), 'date') !== false) {
                try {
                    $date = new \DateTime($value);
                    $value = $date->format('F j, Y');
                } catch (\Exception $e) {
                }
            }

            // CRITICAL: Convert pixels to mm
            $x = (float) ($var['x'] ?? 0) * $this->pixelsToMm;
            $y = (float) ($var['y'] ?? 0) * $this->pixelsToMm;

            $fontSize = (float) ($var['font_size'] ?? 12);
            $color = $var['color'] ?? '#000000';
            $fontFamily = $var['font_family'] ?? 'Arial';
            $tcpdfFont = $fontMap[$fontFamily] ?? 'helvetica';
            $align = strtoupper($var['align'] ?? 'L');

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

            $pdf->SetTextColor($r, $g, $b);
            $pdf->SetFont($tcpdfFont, '', $fontSize);

            $pdf->SetXY($x, $y);
            $pdf->Cell(0, 0, $value, 0, 0, $align);
        }
    }

    /**
     * Add fixed metadata like Cert No and Issue Date at bottom
     */
    private function addMetadata(&$pdf, $data)
    {
        $pageWidth = $pdf->getPageWidth();
        $pageHeight = $pdf->getPageHeight();
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(100, 100, 100);

        if (!empty($data['certificate_number'])) {
            $pdf->SetXY(10, $pageHeight - 15);
            $pdf->Cell(0, 0, 'Certificate No: ' . $data['certificate_number'], 0, 0, 'L');
        }

        if (!empty($data['issue_date'])) {
            $text = 'Issued: ' . $data['issue_date'];
            $textWidth = $pdf->GetStringWidth($text);
            $pdf->SetXY($pageWidth - 10 - $textWidth, $pageHeight - 10);
            $pdf->Cell(0, 0, $text, 0, 0, 'L');
        }
    }
}
