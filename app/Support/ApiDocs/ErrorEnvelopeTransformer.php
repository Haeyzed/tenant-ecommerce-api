<?php

declare(strict_types=1);

namespace App\Support\ApiDocs;

use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\MixedType;
use Dedoc\Scramble\Support\Generator\Types\NullType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

/**
 * Documents every error response in the API's envelope (frontend spec
 * BG-06, docs only). Scramble describes exceptions in Laravel's own shape
 * ({message, errors}); ExceptionRenderer answers with
 * {success: false, message, data: null, meta: {error_code, details}, errors}.
 * Each 4xx/5xx response, inline or a shared component, points at the one
 * ErrorEnvelope schema and keeps its description.
 */
final class ErrorEnvelopeTransformer
{
    public const string SCHEMA = 'ErrorEnvelope';

    public function __invoke(OpenApi $openApi): void
    {
        $reference = $openApi->components->hasSchema(self::SCHEMA)
            ? $openApi->components->getSchemaReference(self::SCHEMA)
            : $openApi->components->addSchema(self::SCHEMA, Schema::fromType(self::schema()));

        foreach ($openApi->components->responses as $response) {
            if ($response instanceof Response) {
                $response->setContent('application/json', $reference);
            }
        }

        foreach ($openApi->paths as $path) {
            foreach ($path->operations as $operation) {
                foreach ((array) $operation->responses as $response) {
                    if ($response instanceof Response && is_numeric($response->code ?? null) && (int) $response->code >= 400) {
                        $response->setContent('application/json', $reference);
                    }
                }
            }
        }
    }

    public static function schema(): ObjectType
    {
        $details = (new ObjectType)->additionalProperties(new MixedType);
        $meta = (new ObjectType)
            ->addProperty('error_code', (new StringType)->setDescription('Machine-readable code; the full list is error-codes.json of the frontend contract (php artisan frontend:contract).'))
            ->addProperty('details', $details)
            ->setRequired(['error_code', 'details']);

        return (new ObjectType)
            ->addProperty('success', (new BooleanType)->const(false))
            ->addProperty('message', new StringType)
            ->addProperty('data', new NullType)
            ->addProperty('meta', $meta)
            ->addProperty('errors', (new ObjectType)->additionalProperties((new ArrayType)->setItems(new StringType))->setDescription('Field errors of a 422 validation_failed, keyed by field.'))
            ->setRequired(['success', 'message', 'data', 'meta', 'errors']);
    }
}
