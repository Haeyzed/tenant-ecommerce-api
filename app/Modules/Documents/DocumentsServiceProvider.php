<?php

declare(strict_types=1);

namespace App\Modules\Documents;

use App\Modules\Documents\Contracts\DocumentRenderer;
use App\Modules\Documents\Support\DompdfDocumentRenderer;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the document renderer (spec §4.5): the only place the PDF, barcode
 * and QR libraries are chosen.
 */
final class DocumentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DocumentRenderer::class, DompdfDocumentRenderer::class);
    }
}
