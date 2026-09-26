<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

use App\Modules\Documents\Contracts\DocumentRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\PDF;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Picqer\Barcode\BarcodeGenerator;
use Picqer\Barcode\BarcodeGeneratorSVG;

/**
 * dompdf for PDFs, picqer for barcodes and bacon for QR codes (spec §4.5).
 * Images are SVG data URIs, so no image extension is required. Remote
 * resources and PHP stay disabled (the package defaults): templates embed
 * everything as data URIs.
 */
final readonly class DompdfDocumentRenderer implements DocumentRenderer
{
    public function __construct(private ViewFactory $views) {}

    public function pdf(string $view, array $data, string|array $paper = 'a4', string $orientation = 'portrait'): string
    {
        /** @var PDF $pdf */
        $pdf = app('dompdf.wrapper');

        return $pdf->loadHTML($this->html($view, $data))
            ->setPaper(is_array($paper) ? [0, 0, $paper[0], $paper[1]] : $paper, $orientation)
            ->output();
    }

    public function html(string $view, array $data): string
    {
        return $this->views->make($view, $data)->render();
    }

    public function barcode(string $value, float $widthFactor = 1.5, float $height = 40): string
    {
        $svg = (new BarcodeGeneratorSVG)->getBarcode($value, BarcodeGenerator::TYPE_CODE_128, $widthFactor, $height);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    public function qrCode(string $value, int $size = 160): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd)))->writeString($value);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
