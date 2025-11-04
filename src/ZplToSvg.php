<?php

/**
 * Webcooking ZplToSvg
 *
 * Copyright (c) 2025 Vincent Enjalbert
 * Licensed under LGPL-3.0-or-later. See the LICENSE file for details.
 */

declare(strict_types=1);

namespace Webcooking\ZplToImage;

/**
 * Class ZplToSvg
 *
 * Converts ZPL (Zebra Programming Language) to SVG format.
 */
class ZplToSvg
{
    private array $fieldData = [];
    private array $fieldPositions = []; // Store FO position for each FN
    private array $fieldFonts = []; // Store font settings for each FN
    private array $barcodeFields = []; // Store FN used by barcodes (should not be rendered as text)
    private int $currentX = 0;
    private int $currentY = 0;
    private int $currentFontHeight = 30;
    private int $currentFontWidth = 30;
    private ?int $currentFieldNumber = null;
    private bool $reverseField = false;
    private int $dpi;
    private SvgBuilder $svg;
    private string $fontRenderer = 'bitmap';
    private ?string $fontPath = null;

    /**
     * Converts ZPL string to SVG.
     *
     * @param string $zpl The ZPL content.
     * @param float $widthInches Label width in inches (default 4)
     * @param float $heightInches Label height in inches (default 6)
     * @param int $dpi Dots per inch (default 300)
     * @param string $fontRenderer Font renderer: 'noto' (default), 'ibm-vga', or path to custom TTF file
     * @return string The SVG content.
     */
    public static function convert(
        string $zpl,
        float $widthInches = 4.0,
        float $heightInches = 6.0,
        int $dpi = 300,
        string $fontRenderer = 'noto'
    ): string {
        $converter = new self();
        return $converter->render($zpl, $widthInches, $heightInches, $dpi, $fontRenderer);
    }

    /**
     * Renders ZPL to SVG string.
     */
    private function render(string $zpl, float $widthInches, float $heightInches, int $dpi, string $fontRenderer = 'noto'): string
    {
        $this->dpi = $dpi;
        $this->fontRenderer = $fontRenderer;
        $this->fontPath = $this->resolveFontPath($fontRenderer);

        // Calculate pixel dimensions
        $width = (int)($widthInches * $dpi);
        $height = (int)($heightInches * $dpi);

        // Create SVG builder
        $this->svg = new SvgBuilder($width, $height);

        // PASS 1: Extract all field data (FN -> FD mappings)
        $this->parseFieldData($zpl);

        // PASS 2: Process template section to store positions and fonts for each FN
        $this->parseFieldPositions($zpl);

        // PASS 3: Render graphic elements first (GB for boxes, lines)
        $this->renderCommands($zpl);

        // PASS 4: Render text fields on top of graphics
        $this->renderFields();

        return $this->svg->toSvg();
    }

    /**
     * Parse template to store position and font for each field number.
     * Only processes the template section (before ^XFNORMAL).
     */
    private function parseFieldPositions(string $zpl): void
    {
        // Split at ^XFNORMAL to get only the template section
        $parts = preg_split('/\^XFNORMAL\^FS/', $zpl);
        $templateSection = $parts[0] ?? $zpl;

        $commands = explode('^', $templateSection);
        $currentX = 0;
        $currentY = 0;
        $currentFontHeight = 30;
        $currentFontWidth = 30;
        $reverseField = false;

        foreach ($commands as $command) {
            if (empty($command)) {
                continue;
            }

            // Font command
            if (preg_match('/^A0[N]?,(\d+),(\d+)/', $command, $matches)) {
                $currentFontHeight = (int)$matches[1];
                $currentFontWidth = (int)$matches[2];
            }
            // Field Origin
            elseif (preg_match('/^FO\s*(\d+)\s*,\s*(\d+)/', $command, $matches)) {
                $currentX = (int)$matches[1];
                $currentY = (int)$matches[2];
            }
            // Field Reverse
            elseif (preg_match('/^FR\s*$/', $command)) {
                $reverseField = true;
            }
            // Graphic Box - consumes the reverse flag without storing it
            elseif (preg_match('/^GB\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $command)) {
                // ^GB consumes the ^FR flag but doesn't propagate it to fields
                $reverseField = false;
            }
            // Field Number - store current position and font including reverse
            elseif (preg_match('/^FN(\d+)/', $command, $matches)) {
                $fieldNum = (int)$matches[1];
                $this->fieldPositions[$fieldNum] = ['x' => $currentX, 'y' => $currentY];
                $this->fieldFonts[$fieldNum] = [
                    'height' => $currentFontHeight,
                    'width' => $currentFontWidth,
                    'reverse' => $reverseField
                ];
                $reverseField = false; // Reset after use
            }
        }
    }

    /**
     * Render all fields using stored positions and data.
     */
    private function renderFields(): void
    {
        foreach ($this->fieldData as $fieldNum => $data) {
            if (empty($data)) {
                continue;
            }
            if (!isset($this->fieldPositions[$fieldNum])) {
                continue;
            }
            // Skip fields used by barcodes
            if (isset($this->barcodeFields[$fieldNum])) {
                continue;
            }

            $pos = $this->fieldPositions[$fieldNum];
            $font = $this->fieldFonts[$fieldNum] ?? ['height' => 30, 'width' => 30, 'reverse' => false];

            $this->currentX = $pos['x'];
            $this->currentY = $pos['y'];
            $this->currentFontHeight = $font['height'];
            $this->currentFontWidth = $font['width'];
            $this->reverseField = $font['reverse'];

            $this->drawText($data);
        }
    }

    /**
     * Parses ZPL to extract field data.
     *
     * ZPL can have two formats:
     * 1. Inline: ^FN123^FDdata^FS
     * 2. Separated: Template defines ^FN123^FS, then later ^FN123^FDdata^FS provides data
     */
    private function parseFieldData(string $zpl): void
    {
        // Find all ^FN followed by ^FD (with or without ^FS in between)
        // Pattern: ^FN(number) possibly followed by ^FS, then later ^FD(data)

        // Split into individual commands for easier parsing
        $commands = preg_split('/\^/', $zpl);

        $pendingFN = null;

        foreach ($commands as $command) {
            if (empty($command)) {
                continue;
            }

            // Check if it's a FN command
            if (preg_match('/^FN(\d+)/', $command, $matches)) {
                $pendingFN = (int)$matches[1];
            }
            // Check if it's a FD command (field data)
            elseif (preg_match('/^FD(.*)$/', $command, $matches)) {
                $data = $matches[1];
                // Remove trailing ^FS if present
                $data = preg_replace('/\^FS$/', '', $data);
                $data = trim($data);

                if ($pendingFN !== null) {
                    // Associate this data with the last FN we saw
                    $this->fieldData[$pendingFN] = $data;
                    $pendingFN = null;
                }
            }
            // Reset pending FN if we encounter other commands
            elseif (!preg_match('/^FS/', $command)) {
                // Don't reset on ^FS, but reset on other commands
                if ($pendingFN !== null && !preg_match('/^(FS|FD)/', $command)) {
                    $pendingFN = null;
                }
            }
        }
    }

    /**
     * Renders non-field commands (GB for boxes, BCN for barcodes, direct FD text) to SVG.
     */
    private function renderCommands(string $zpl): void
    {
        $commands = explode('^', $zpl);
        $currentBarcodeWidth = 2;
        $currentBarcodeHeight = 100;
        $currentFontHeight = 30;
        $currentFontWidth = 30;

        foreach ($commands as $i => $command) {
            if (empty($command)) {
                continue;
            }

            // Font command (for direct FD text)
            if (preg_match('/^A0[N]?,(\d+),(\d+)/', $command, $matches)) {
                $currentFontHeight = (int)$matches[1];
                $currentFontWidth = (int)$matches[2];
            }

            // Field Origin for non-field elements
            elseif (preg_match('/^FO\s*(\d+)\s*,\s*(\d+)/', $command, $matches)) {
                $this->currentX = (int)$matches[1];
                $this->currentY = (int)$matches[2];
                // Reset reverse field on new field origin (starts new logical line)
                $this->reverseField = false;
            }

            // Field Separator: FS (resets reverse field if not consumed)
            elseif (preg_match('/^FS\s*$/', $command)) {
                // Reset reverse field at end of field
                $this->reverseField = false;
            }

            // Field Reverse flag
            elseif (preg_match('/^FR\s*$/', $command)) {
                $this->reverseField = true;
            }

            // Graphic Box: GB width,height,thickness
            elseif (preg_match('/^GB\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $command, $matches)) {
                $width = (int)$matches[1];
                $height = (int)$matches[2];
                $thickness = (int)$matches[3];

                // Determine if this is a filled rectangle or a line
                $isFilled = $thickness > $width || $thickness > $height;
                $isLine = $thickness == $width || $thickness == $height;

                if ($isFilled) {
                    // Filled rectangle
                    if ($this->reverseField) {
                        // Reverse: white fill with black border
                        $this->svg->addRect($this->currentX, $this->currentY, $width, $height, 'white', 'black', min($thickness, 5));
                    } else {
                        // Normal: black fill
                        $this->svg->addRect($this->currentX, $this->currentY, $width, $height, 'black', 'none');
                    }
                } elseif ($isLine) {
                    // This is a line (thickness equals width or height)
                    if ($this->reverseField) {
                        // Reverse line: white
                        $this->svg->addRect($this->currentX, $this->currentY, $width, $height, 'white', 'none');
                    } else {
                        // Normal line: black
                        $this->svg->addRect($this->currentX, $this->currentY, $width, $height, 'black', 'none');
                    }
                } else {
                    // Border only (outline)
                    if ($this->reverseField) {
                        // Reverse border: white fill with black stroke (ensures white background inside)
                        $this->svg->addRect($this->currentX, $this->currentY, $width, $height, 'white', 'black', $thickness);
                    } else {
                        // Normal border: black stroke, no fill
                        $this->svg->addRect($this->currentX, $this->currentY, $width, $height, 'none', 'black', $thickness);
                    }
                }
                // Always reset reverse field after GB
                $this->reverseField = false;
            }

            // Field Data (direct text, not via FN)
            elseif (preg_match('/^FD(.+?)(?:FS)?$/', $command, $matches)) {
                $text = trim($matches[1]);
                // Check if this is NOT associated with a FN (direct text like MESS)
                // Look back to see if there's a recent FN
                $hasFN = false;
                for ($j = max(0, $i - 3); $j < $i; $j++) {
                    if (preg_match('/^FN\d+/', $commands[$j])) {
                        $hasFN = true;
                        break;
                    }
                }

                if (!$hasFN && !empty($text)) {
                    // Direct text rendering
                    $this->currentFontHeight = $currentFontHeight;
                    $this->currentFontWidth = $currentFontWidth;
                    $this->drawText($text);
                }
            }

            // Barcode Width: BY width[,ratio]
            elseif (preg_match('/^BY\s*(\d+)/', $command, $matches)) {
                $currentBarcodeWidth = (int)$matches[1];
            }

            // Code 128 Barcode: BCN,height,printInterpretationLine[,printAbove,checkDigit,mode]
            elseif (preg_match('/^BCN\s*,\s*(\d+)\s*,?\s*(\w*)/', $command, $matches)) {
                $currentBarcodeHeight = (int)$matches[1];
                $printInterpretation = !empty($matches[2]) ? $matches[2] : 'Y';

                // Look for the FN that follows this BCN
                for ($j = $i + 1; $j < count($commands); $j++) {
                    if (preg_match('/^FN(\d+)/', $commands[$j], $fnMatches)) {
                        $fieldNum = (int)$fnMatches[1];
                        // Mark this FN as used by a barcode so it won't be rendered as text
                        $this->barcodeFields[$fieldNum] = true;

                        if (isset($this->fieldData[$fieldNum])) {
                            $barcodeData = $this->fieldData[$fieldNum];
                            $this->drawBarcode($barcodeData, $currentBarcodeWidth, $currentBarcodeHeight, $printInterpretation);
                        }
                        break;
                    }
                    // Stop if we hit another command that's not FS
                    if (!preg_match('/^FS/', $commands[$j])) {
                        break;
                    }
                }
            }
        }
    }

    /**
     * Process a single ZPL command - DEPRECATED, kept for compatibility.
     */
    private function processCommand(string $command): void
    {
        // This method is no longer used in the three-pass architecture
        // but kept to avoid errors if called from old code paths
    }

    /**
     * Draw text to SVG using TrueType font.
     */
    private function drawText(string $text): void
    {
        $this->drawTextWithTTF($text);
    }

    /**
     * Draw text using TrueType font.
     */
    private function drawTextWithTTF(string $text): void
    {
        if (empty($text)) {
            return;
        }
        if (!$this->fontPath || !file_exists($this->fontPath)) {
            throw new \RuntimeException("Font file not found: {$this->fontPath}");
        }

        // For TTF fonts, use the ZPL font size more directly
        // ZPL height is in dots, we scale it slightly
        $fontSize = (int)round($this->currentFontHeight * 0.6);

        $color = 'black';
        $bgColor = null;

        // Handle reverse field (white text on black background)
        if ($this->reverseField) {
            // Calculate approximate text width (rough estimate)
            $charWidth = $fontSize * 0.6; // Approximate
            $totalWidth = strlen($text) * $charWidth;

            $this->svg->addRect(
                $this->currentX,
                $this->currentY,
                (int)$totalWidth,
                $this->currentFontHeight,
                'black',
                'none'
            );

            $color = 'white';
            $this->reverseField = false;
        }

        // Add text element to SVG with font-family reference
        $this->svg->addTextWithFont(
            $text,
            $this->currentX,
            $this->currentY + $fontSize, // Adjust baseline
            $fontSize,
            $color,
            $this->fontPath
        );
    }

    /**
     * Resolve font path from font renderer string.
     */
    private function resolveFontPath(string $fontRenderer): ?string
    {
        // If it's already a path, use it
        if (file_exists($fontRenderer) && pathinfo($fontRenderer, PATHINFO_EXTENSION) === 'ttf') {
            return $fontRenderer;
        }

        // Get the base fonts directory
        $fontsDir = dirname(__DIR__) . '/fonts';

        // Predefined fonts
        $fontMap = [
            'noto' => $fontsDir . '/Noto_Sans/NotoSans-VariableFont_wdth,wght.ttf',
            'ibm-vga' => $fontsDir . '/IBM_VGA/Px437_IBM_VGA_8x16.ttf',
        ];

        return $fontMap[$fontRenderer] ?? null;
    }

    /**
     * Draw Code 128 barcode as SVG paths.
     */
    private function drawBarcode(string $data, int $barWidth, int $height, string $printInterpretation = 'Y'): void
    {
        // Code 128 encoding patterns (simplified for basic ASCII)
        // Each character is encoded as a pattern of bars and spaces
        $code128Patterns = $this->getCode128Patterns();

        $x = $this->currentX;
        $y = $this->currentY;

        // ZPL barWidth is in dots, but we need to scale it down
        // BY3 in ZPL doesn't mean 3 pixels per module, it's relative
        // Divide by 1.5 to get reasonable width (empirically determined)
        $barWidth = max(1, (int)round($barWidth / 1.5));

        // Start pattern (Code 128B Start)
        $pattern = '11010010000';
        $x = $this->drawBarcodePattern($pattern, $x, $y, $height, $barWidth);

        // Encode each character
        for ($i = 0; $i < strlen($data); $i++) {
            $char = $data[$i];
            $ascii = ord($char);

            // Get pattern for this character (simplified mapping)
            if ($ascii >= 32 && $ascii <= 127) {
                $patternIndex = $ascii - 32;
                if (isset($code128Patterns[$patternIndex])) {
                    $pattern = $code128Patterns[$patternIndex];
                    $x = $this->drawBarcodePattern($pattern, $x, $y, $height, $barWidth);
                }
            }
        }

        // Stop pattern
        $pattern = '1100011101011';
        $x = $this->drawBarcodePattern($pattern, $x, $y, $height, $barWidth);

        // Draw the text below barcode only if printInterpretation is not 'N'
        if (strtoupper($printInterpretation) !== 'N') {
            $textY = $y + $height + 20;
            $this->svg->addText($data, $this->currentX, $textY, 12, 'black');
        }
    }

    /**
     * Draw a barcode pattern (1=black bar, 0=white space).
     */
    private function drawBarcodePattern(string $pattern, int $x, int $y, int $height, int $barWidth): int
    {
        for ($i = 0; $i < strlen($pattern); $i++) {
            if ($pattern[$i] === '1') {
                $this->svg->addRect($x, $y, $barWidth, $height, 'black', 'none');
            }
            $x += $barWidth;
        }
        return $x;
    }

    /**
     * Get Code 128 encoding patterns (simplified subset).
     */
    private function getCode128Patterns(): array
    {
        // Simplified Code 128B patterns for basic ASCII characters
        // In reality, Code 128 uses more complex encoding with checksums
        return [
            '11011001100', // Space (32)
            '11001101100', // ! (33)
            '11001100110', // " (34)
            '10010011000', // # (35)
            '10010001100', // $ (36)
            '10001001100', // % (37)
            '10011001000', // & (38)
            '10011000100', // ' (39)
            '10001100100', // ( (40)
            '11001001000', // ) (41)
            '11001000100', // * (42)
            '11000100100', // + (43)
            '10110011100', // , (44)
            '10011011100', // - (45)
            '10011001110', // . (46)
            '10111001100', // / (47)
            '10011101100', // 0 (48)
            '10011100110', // 1 (49)
            '11001110010', // 2 (50)
            '11001011100', // 3 (51)
            '11001001110', // 4 (52)
            '11011100100', // 5 (53)
            '11001110100', // 6 (54)
            '11101101110', // 7 (55)
            '11101001100', // 8 (56)
            '11100101100', // 9 (57)
            '11100100110', // : (58)
            '11101100100', // ; (59)
            '11100110100', // < (60)
            '11100110010', // = (61)
            '11011011000', // > (62)
            '11011000110', // ? (63)
            '11000110110', // @ (64)
            '10100011000', // A (65)
            '10001011000', // B (66)
            '10001000110', // C (67)
            '10110001000', // D (68)
            '10001101000', // E (69)
            '10001100010', // F (70)
            '11010001000', // G (71)
            '11000101000', // H (72)
            '11000100010', // I (73)
            '10110111000', // J (74)
            '10110001110', // K (75)
            '10001101110', // L (76)
            '10111011000', // M (77)
            '10111000110', // N (78)
            '10001110110', // O (79)
            '11101110110', // P (80)
            '11010001110', // Q (81)
            '11000101110', // R (82)
            '11011101000', // S (83)
            '11011100010', // T (84)
            '11011101110', // U (85)
            '11101011000', // V (86)
            '11101000110', // W (87)
            '11100010110', // X (88)
            '11101101000', // Y (89)
            '11101100010', // Z (90)
        ];
    }
}
