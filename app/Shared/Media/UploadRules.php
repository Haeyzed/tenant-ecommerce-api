<?php

declare(strict_types=1);

namespace App\Shared\Media;

/**
 * Validation rules for every upload (spec §19.3, §75 rule 10): the MIME type
 * is sniffed from the content (mimetypes), the extension must match an
 * allow-list, and the size is capped. SVG is never accepted: it can carry
 * script.
 */
final class UploadRules
{
    public const array IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public const array IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public const array DOCUMENT_MIMES = ['application/pdf', 'text/plain', 'text/csv', 'image/jpeg', 'image/png'];

    public const array DOCUMENT_EXTENSIONS = ['pdf', 'txt', 'csv', 'jpg', 'jpeg', 'png'];

    /**
     * @return list<string>
     */
    public static function image(int $maxKilobytes = 5120): array
    {
        return [
            'file',
            'mimetypes:'.implode(',', self::IMAGE_MIMES),
            'extensions:'.implode(',', self::IMAGE_EXTENSIONS),
            'max:'.$maxKilobytes,
        ];
    }

    /**
     * @return list<string>
     */
    public static function document(int $maxKilobytes = 10240): array
    {
        return [
            'file',
            'mimetypes:'.implode(',', self::DOCUMENT_MIMES),
            'extensions:'.implode(',', self::DOCUMENT_EXTENSIONS),
            'max:'.$maxKilobytes,
        ];
    }
}
