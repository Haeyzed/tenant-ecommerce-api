<?php

declare(strict_types=1);

namespace App\Modules\Documents\Contracts;

/**
 * The single seam over the document libraries (spec §4.5, §43): PDFs,
 * printable HTML, barcodes and QR codes. Swapping a library changes only
 * the binding.
 */
interface DocumentRenderer
{
    /**
     * Renders a Blade view to PDF bytes. Paper is a named size ("a4") or
     * [width, height] in points.
     *
     * @param  array<string, mixed>  $data
     * @param  string|array{0: float, 1: float}  $paper
     */
    public function pdf(string $view, array $data, string|array $paper = 'a4', string $orientation = 'portrait'): string;

    /**
     * Renders a Blade view to printable HTML (thermal receipts).
     *
     * @param  array<string, mixed>  $data
     */
    public function html(string $view, array $data): string;

    /**
     * A Code 128 barcode as an embeddable data URI.
     */
    public function barcode(string $value, float $widthFactor = 1.5, float $height = 40): string;

    /**
     * A QR code as an embeddable data URI.
     */
    public function qrCode(string $value, int $size = 160): string;
}
