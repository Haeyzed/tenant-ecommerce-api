<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

use Symfony\Component\HttpFoundation\Response;

/**
 * A rendered document: its bytes, MIME type and download file name.
 */
final readonly class RenderedDocument
{
    public function __construct(
        public string $content,
        public string $mimeType,
        public string $fileName,
    ) {}

    /**
     * Inline, uncached: documents carry personal data.
     */
    public function toResponse(): Response
    {
        return response($this->content, 200, [
            'Content-Type' => $this->mimeType === 'text/html' ? 'text/html; charset=UTF-8' : $this->mimeType,
            'Content-Disposition' => 'inline; filename="'.preg_replace('/[^A-Za-z0-9._-]+/', '-', $this->fileName).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
