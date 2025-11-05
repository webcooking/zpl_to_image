<?php

/**
 * Webcooking ZPL to GDImage
 *
 * Copyright (c) 2025 Vincent Enjalbert
 * Licensed under LGPL-3.0-or-later. See the LICENSE file for details.
 */

declare(strict_types=1);

namespace Webcooking\ZplToImage;

use GdImage;
use Imagick;

/**
 * ZPL to GDImage converter.
 */
class ZplToGdImage
{
    use RsvgConvertTrait;
    /**
     * @param string $zpl
     * @param float $widthInches
     * @param float $heightInches
     * @param int $dpi
     * @param string $fontRenderer
     * @return GdImage
     */
    public static function convert(
        string $zpl,
        float $widthInches = 4.0,
        float $heightInches = 6.0,
        int $dpi = 300,
        string $fontRenderer = 'noto'
    ): GdImage {
        // Try rsvg-convert first if available (direct SVG -> PNG conversion)
        if (self::isRsvgConvertAvailable()) {
            return self::convertUsingRsvgConvert($zpl, $widthInches, $heightInches, $dpi, $fontRenderer);
        }

        // Fallback to Imagick conversion
        $imagick = ZplToImagick::convert($zpl, $widthInches, $heightInches, $dpi, $fontRenderer);
        return self::imagickToGdImage($imagick);
    }

    /**
     * Converts ZPL string to SVG string.
     *
     * @param string $zpl The ZPL content.
     * @param float $widthInches Label width in inches (default 4)
     * @param float $heightInches Label height in inches (default 6)
     * @param int $dpi Dots per inch (default 300)
     * @param string $fontRenderer Font renderer: 'noto' (default), 'ibm-vga', or path to TTF file
     * @return string The SVG content.
     */
    public static function toSvg(
        string $zpl,
        float $widthInches = 4.0,
        float $heightInches = 6.0,
        int $dpi = 300,
        string $fontRenderer = 'noto'
    ): string {
        return ZplToSvg::convert($zpl, $widthInches, $heightInches, $dpi, $fontRenderer);
    }

    /**
     * @param string $zpl
     * @param string $outputPath
     * @param float $widthInches
     * @param float $heightInches
     * @param int $dpi
     * @param string $fontRenderer
     * @param int $quality
     */
    public static function toPng(
        string $zpl,
        string $outputPath,
        float $widthInches = 4.0,
        float $heightInches = 6.0,
        int $dpi = 300,
        string $fontRenderer = 'noto',
        int $quality = 9
    ): void {
        // Try direct rsvg-convert to PNG if available (most efficient)
        if (self::isRsvgConvertAvailable()) {
            $svgContent = ZplToSvg::convert($zpl, $widthInches, $heightInches, $dpi, $fontRenderer);
            $widthPixels = (int) round($widthInches * $dpi);
            $heightPixels = (int) round($heightInches * $dpi);

            $pngData = self::rsvgConvertToPng($svgContent, $widthPixels, $heightPixels);

            if (file_put_contents($outputPath, $pngData) === false) {
                throw new \RuntimeException("Failed to save PNG to: {$outputPath}");
            }
            return;
        }

        // Fallback to GDImage conversion
        $gdImage = self::convert($zpl, $widthInches, $heightInches, $dpi, $fontRenderer);

        // Set PNG compression
        imagesavealpha($gdImage, true);

        if (!imagepng($gdImage, $outputPath, $quality)) {
            throw new \RuntimeException("Failed to save PNG to: {$outputPath}");
        }

        imagedestroy($gdImage);
    }

    /**
     * @param string $zpl
     * @param string $outputPath
     * @param float $widthInches
     * @param float $heightInches
     * @param int $dpi
     * @param string $fontRenderer
     * @param int $quality
     */
    public static function toJpeg(
        string $zpl,
        string $outputPath,
        float $widthInches = 4.0,
        float $heightInches = 6.0,
        int $dpi = 300,
        string $fontRenderer = 'noto',
        int $quality = 90
    ): void {
        $gdImage = self::convert($zpl, $widthInches, $heightInches, $dpi, $fontRenderer);

        if (!imagejpeg($gdImage, $outputPath, $quality)) {
            throw new \RuntimeException("Failed to save JPEG to: {$outputPath}");
        }

        imagedestroy($gdImage);
    }

    /**
     * Convert ZPL to GDImage using rsvg-convert (direct SVG -> PNG conversion).
     */
    private static function convertUsingRsvgConvert(
        string $zpl,
        float $widthInches,
        float $heightInches,
        int $dpi,
        string $fontRenderer
    ): GdImage {
        // Generate SVG first
        $svgContent = ZplToSvg::convert($zpl, $widthInches, $heightInches, $dpi, $fontRenderer);

        // Calculate dimensions in pixels
        $widthPixels = (int) round($widthInches * $dpi);
        $heightPixels = (int) round($heightInches * $dpi);

        // Convert SVG to PNG using rsvg-convert
        $pngData = self::rsvgConvertToPng($svgContent, $widthPixels, $heightPixels);

        // Create GDImage from PNG data
        $gdImage = imagecreatefromstring($pngData);
        if ($gdImage === false) {
            throw new \RuntimeException('Failed to create GDImage from PNG data');
        }

        return $gdImage;
    }

    /**
     * Convert Imagick object to GDImage.
     *
     * @param Imagick $imagick The Imagick object
     * @return GdImage The converted image
     * @throws \RuntimeException If conversion fails
     */
    private static function imagickToGdImage(Imagick $imagick): GdImage
    {
        // Set format to PNG for best quality transfer to GD
        $imagick->setImageFormat('PNG');

        // Get PNG blob from Imagick and convert to GD
        $pngBlob = $imagick->getImageBlob();
        $imagick->clear();
        $imagick->destroy();

        $gdImage = imagecreatefromstring($pngBlob);
        if ($gdImage === false) {
            throw new \RuntimeException('Failed to create GDImage from PNG data');
        }

        return $gdImage;
    }
}
