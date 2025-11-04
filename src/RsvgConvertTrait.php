<?php

/**
 * Webcooking ZPL to GDImage
 *
 * Copyright (c) 2025 Vincent Enjalbert
 * Licensed under LGPL-3.0-or-later. See the LICENSE file for details.
 */

declare(strict_types=1);

namespace Webcooking\ZplToImage;

use Imagick;

/**
 * Trait for rsvg-convert functionality shared between converters.
 */
trait RsvgConvertTrait
{
    /**
     * Check if rsvg-convert is available on the system.
     */
    private static function isRsvgConvertAvailable(): bool
    {
        static $available = null;
        if ($available === null) {
            $output = [];
            $returnCode = 0;
            exec('which rsvg-convert 2>/dev/null', $output, $returnCode);
            $available = ($returnCode === 0);
        }
        return $available;
    }

    /**
     * Convert SVG to PNG using rsvg-convert and return the PNG data.
     *
     * @param string $svgContent SVG content to convert
     * @param int $widthPixels Target width in pixels
     * @param int $heightPixels Target height in pixels
     * @return string PNG binary data
     * @throws \RuntimeException If conversion fails
     */
    private static function rsvgConvertToPng(string $svgContent, int $widthPixels, int $heightPixels): string
    {
        // Create temporary files
        $svgTempFile = tempnam(sys_get_temp_dir(), 'zpl_svg_');
        $pngTempFile = tempnam(sys_get_temp_dir(), 'zpl_png_');

        try {
            // Write SVG to temp file
            if (file_put_contents($svgTempFile, $svgContent) === false) {
                throw new \RuntimeException('Failed to write SVG to temporary file');
            }

            // Convert SVG to PNG using rsvg-convert
            $command = sprintf(
                'rsvg-convert --format=png --width=%d --height=%d --background-color=white %s -o %s 2>&1',
                $widthPixels,
                $heightPixels,
                escapeshellarg($svgTempFile),
                escapeshellarg($pngTempFile)
            );

            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                throw new \RuntimeException('rsvg-convert failed: ' . implode("\n", $output));
            }

            // Read PNG data
            $pngData = file_get_contents($pngTempFile);
            if ($pngData === false) {
                throw new \RuntimeException('Failed to read PNG from temporary file');
            }

            return $pngData;
        } finally {
            // Clean up temp files
            if (file_exists($svgTempFile)) {
                unlink($svgTempFile);
            }
            if (file_exists($pngTempFile)) {
                unlink($pngTempFile);
            }
        }
    }

    /**
     * Create an Imagick object from PNG data.
     *
     * @param string $pngData PNG binary data
     * @return Imagick
     * @throws \RuntimeException If creation fails
     */
    private static function createImagickFromPngData(string $pngData): Imagick
    {
        try {
            $imagick = new Imagick();
            $imagick->readImageBlob($pngData);
            return $imagick;
        } catch (\ImagickException $e) {
            throw new \RuntimeException('Failed to create Imagick from PNG data: ' . $e->getMessage(), 0, $e);
        }
    }
}
