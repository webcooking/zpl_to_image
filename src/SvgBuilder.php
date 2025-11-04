<?php

/**
 * Webcooking SVG Builder
 *
 * Copyright (c) 2025 Vincent Enjalbert
 * Licensed under LGPL-3.0-or-later. See the LICENSE file for details.
 */

declare(strict_types=1);

namespace Webcooking\ZplToImage;

/**
 * SVG Builder for ZPL rendering
 */
class SvgBuilder
{
    private array $elements = [];
    private array $customFonts = [];
    private int $width;
    private int $height;

    public function __construct(int $width, int $height)
    {
        $this->width = $width;
        $this->height = $height;
    }

    public function addText(
        string $text,
        int $x,
        int $y,
        int $fontSize,
        string $color = 'black',
        string $fontFamily = 'Arial, sans-serif',
        string $fontWeight = 'normal',
        float $scaleX = 1.0,
        ?int $textLength = null
    ): void {
        $text = htmlspecialchars($text, ENT_XML1);

        // If textLength is specified, use it to control exact text width
        if ($textLength !== null && $textLength > 0) {
            $this->elements[] = sprintf(
                '<text x="%d" y="%d" font-family="%s" font-size="%d" fill="%s" font-weight="%s" textLength="%d" lengthAdjust="spacingAndGlyphs">%s</text>',
                $x,
                $y,
                $fontFamily,
                $fontSize,
                $color,
                $fontWeight,
                $textLength,
                $text
            );
        }
        // If we need horizontal scaling, use transform
        elseif ($scaleX != 1.0 && $scaleX > 0) {
            $this->elements[] = sprintf(
                '<text x="%d" y="%d" font-family="%s" font-size="%d" fill="%s" font-weight="%s" transform="scale(%.2f,1) translate(%.2f,0)">%s</text>',
                0, // x will be applied via translate
                $y,
                $fontFamily,
                $fontSize,
                $color,
                $fontWeight,
                $scaleX,
                $x / $scaleX, // Compensate for scale
                $text
            );
        } else {
            $this->elements[] = sprintf(
                '<text x="%d" y="%d" font-family="%s" font-size="%d" fill="%s" font-weight="%s">%s</text>',
                $x,
                $y,
                $fontFamily,
                $fontSize,
                $color,
                $fontWeight,
                $text
            );
        }
    }

    public function addTextInBox(
        string $text,
        int $x,
        int $y,
        int $targetWidth,
        int $targetHeight,
        string $color,
        string $fontFamily,
        string $fontWeight
    ): void {
        $text = htmlspecialchars($text, ENT_XML1);

        // Estimate natural text width assuming average character width of 0.6 * height
        $estimatedCharWidth = $targetHeight * 0.6;
        $estimatedNaturalWidth = strlen($text) * $estimatedCharWidth;

        // Calculate scale to fit
        $scaleX = min(1.0, $targetWidth / max(1, $estimatedNaturalWidth));
        $scaleY = 1.0;

        // Use foreignObject with CSS transform to fit text in box
        $transformStyle = sprintf('transform:scale(%.3f,%.3f);', $scaleX, $scaleY);

        $this->elements[] = sprintf(
            '<foreignObject x="%d" y="%d" width="%d" height="%d"><div xmlns="http://www.w3.org/1999/xhtml" style="width:%dpx;height:%dpx;display:flex;align-items:flex-end;overflow:hidden;"><span style="font-family:%s;font-size:%dpx;color:%s;font-weight:%s;white-space:nowrap;transform-origin:left bottom;%s">%s</span></div></foreignObject>',
            $x,
            $y,
            $targetWidth,
            $targetHeight,
            $targetWidth,
            $targetHeight,
            $fontFamily,
            $targetHeight,
            $color,
            $fontWeight,
            $transformStyle,
            $text
        );
    }

    public function addTextWithLength(
        string $text,
        int $x,
        int $y,
        int $fontSize,
        string $color,
        string $fontFamily,
        string $fontWeight,
        int $textLength,
        string $lengthAdjust = 'spacing'
    ): void {
        $text = htmlspecialchars($text, ENT_XML1);

        $this->elements[] = sprintf(
            '<text x="%d" y="%d" font-family="%s" font-size="%d" fill="%s" font-weight="%s" textLength="%d" lengthAdjust="%s">%s</text>',
            $x,
            $y,
            $fontFamily,
            $fontSize,
            $color,
            $fontWeight,
            $textLength,
            $lengthAdjust,
            $text
        );
    }

    public function addTextWithSpacing(
        string $text,
        int $x,
        int $y,
        int $fontSize,
        string $color = 'black',
        string $fontFamily = 'Arial, sans-serif',
        string $fontWeight = 'normal',
        float $letterSpacing = 0
    ): void {
        $text = htmlspecialchars($text, ENT_XML1);

        if ($letterSpacing != 0) {
            $this->elements[] = sprintf(
                '<text x="%d" y="%d" font-family="%s" font-size="%d" fill="%s" font-weight="%s" letter-spacing="%.2fpx">%s</text>',
                $x,
                $y,
                $fontFamily,
                $fontSize,
                $color,
                $fontWeight,
                $letterSpacing,
                $text
            );
        } else {
            $this->elements[] = sprintf(
                '<text x="%d" y="%d" font-family="%s" font-size="%d" fill="%s" font-weight="%s">%s</text>',
                $x,
                $y,
                $fontFamily,
                $fontSize,
                $color,
                $fontWeight,
                $text
            );
        }
    }

    public function addTextWithFont(
        string $text,
        int $x,
        int $y,
        int $fontSize,
        string $color = 'black',
        ?string $fontPath = null
    ): void {
        $text = htmlspecialchars($text, ENT_XML1);

        // Generate a unique font family name based on the font file
        $fontFamily = 'CustomFont-' . md5($fontPath ?? 'default');

        // Store font for embedding
        if ($fontPath && file_exists($fontPath)) {
            $this->customFonts[$fontFamily] = $fontPath;
        }

        $this->elements[] = sprintf(
            '<text x="%d" y="%d" font-family="%s" font-size="%d" fill="%s">%s</text>',
            $x,
            $y,
            $fontFamily,
            $fontSize,
            $color,
            $text
        );
    }

    public function addRect(
        int $x,
        int $y,
        int $width,
        int $height,
        string $fill = 'none',
        string $stroke = 'black',
        int $strokeWidth = 1
    ): void {
        $this->elements[] = sprintf(
            '<rect x="%d" y="%d" width="%d" height="%d" fill="%s" stroke="%s" stroke-width="%d"/>',
            $x,
            $y,
            $width,
            $height,
            $fill,
            $stroke,
            $strokeWidth
        );
    }

    public function addLine(int $x1, int $y1, int $x2, int $y2, string $stroke = 'black', int $strokeWidth = 1): void
    {
        $this->elements[] = sprintf(
            '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="%d"/>',
            $x1,
            $y1,
            $x2,
            $y2,
            $stroke,
            $strokeWidth
        );
    }

    public function addBarcode(string $data, int $x, int $y, int $height, int $barWidth = 2): void
    {
        // Generate simple Code 128-like barcode representation
        $barcodeX = $x;

        // Start with bars for each character
        for ($i = 0; $i < strlen($data); $i++) {
            // Alternate black and white bars
            if ($i % 2 === 0 || $data[$i] !== ' ') {
                $this->addRect($barcodeX, $y, $barWidth, $height, 'black', 'none');
            }
            $barcodeX += $barWidth;
        }

        // Add text below barcode
        $this->addText($data, $x, $y + $height + 15, 12, 'black');
    }

    public function toSvg(): string
    {
        // Embed Swiss 721 font if available
        $fontDefs = $this->embedFonts();

        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d">
%s
<rect width="100%%" height="100%%" fill="white"/>
%s
</svg>',
            $this->width,
            $this->height,
            $this->width,
            $this->height,
            $fontDefs,
            implode("\n", $this->elements)
        );
    }

    private function embedFonts(): string
    {
        $fontDir = __DIR__ . '/../fonts';
        $defs = "<defs>\n<style>\n";

        // Embed custom fonts added via addTextWithFont
        foreach ($this->customFonts as $fontFamily => $fontPath) {
            if (file_exists($fontPath)) {
                $fontData = base64_encode(file_get_contents($fontPath));
                $extension = pathinfo($fontPath, PATHINFO_EXTENSION);
                $mimeType = $extension === 'otf' ? 'font/opentype' : 'font/truetype';

                $defs .= "@font-face {\n";
                $defs .= "  font-family: '{$fontFamily}';\n";
                $defs .= "  src: url('data:{$mimeType};base64,{$fontData}');\n";
                $defs .= "}\n";
            }
        }

        // Try to embed Swiss 721 fonts (legacy support)
        $fonts = [
            'Swiss721BT-Roman' => 'Swiss721BT-Roman.otf',
            'Swiss721BT-Medium' => 'Swiss721BT-Medium.otf',
            'Swiss721BT-BoldCondensed' => 'Swiss721BT-BoldCondensed.otf',
        ];

        foreach ($fonts as $fontName => $fontFile) {
            $fontPath = $fontDir . '/' . $fontFile;
            if (file_exists($fontPath)) {
                $fontData = base64_encode(file_get_contents($fontPath));
                $defs .= "@font-face {\n";
                $defs .= "  font-family: '{$fontName}';\n";
                $defs .= "  src: url('data:font/opentype;base64,{$fontData}');\n";
                $defs .= "}\n";
            }
        }

        $defs .= "</style>\n</defs>\n";
        return $defs;
    }
}
