<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
| BG-06: the OpenAPI document types `data` from the APIResponse argument,
| adds meta.pagination to paginated lists, and describes every error in
| the envelope.
*/

it('documents typed data, pagination and the error envelope', function (): void {
    $path = 'storage/framework/testing/openapi-envelope.json';

    try {
        $this->artisan('scramble:export', [
            '--api' => 'default',
            '--routes' => 'landlord.settings.media.store,landlord.tenancy.tenants.index,landlord.platform.config',
            '--path' => $path,
        ])->assertSuccessful();

        $doc = json_decode((string) File::get(base_path($path)), true, flags: JSON_THROW_ON_ERROR);
    } finally {
        File::delete(base_path($path));
    }

    $created = $doc['paths']['/admin/platform-settings/media']['post']['responses']['201']['content']['application/json']['schema'];
    expect($created['required'])->toBe(['success', 'message', 'data', 'meta', 'errors'])
        ->and(array_keys($created['properties']['data']['properties']))->toBe(['setting', 'media_id', 'url'])
        ->and($created['properties']['data']['properties']['media_id']['type'])->toBe('integer');

    $list = $doc['paths']['/admin/tenants']['get']['responses']['200']['content']['application/json']['schema'];
    expect($list['properties']['data']['type'])->toBe('array')
        ->and($list['properties']['meta']['properties']['pagination']['required'])->toContain('current_page', 'per_page', 'total', 'last_page')
        ->and(array_keys($list['properties']['meta']['properties']['links']['properties']))->toBe(['first', 'prev', 'next', 'last']);

    $envelope = $doc['components']['schemas']['ErrorEnvelope'];
    expect($envelope['required'])->toBe(['success', 'message', 'data', 'meta', 'errors'])
        ->and($envelope['properties']['meta']['required'])->toBe(['error_code', 'details']);

    // Every error response, inline or shared, is the envelope.
    $errors = [];
    foreach ($doc['paths'] as $operations) {
        foreach ($operations as $operation) {
            foreach ($operation['responses'] ?? [] as $code => $response) {
                if ((int) $code >= 400) {
                    $errors[] = $response['$ref'] ?? $response['content']['application/json']['schema']['$ref'] ?? null;
                }
            }
        }
    }
    foreach ($doc['components']['responses'] ?? [] as $response) {
        $errors[] = $response['content']['application/json']['schema']['$ref'] ?? null;
    }

    expect($errors)->not->toBeEmpty()->not->toContain(null)
        ->and(collect($errors)->reject(fn (string $ref): bool => $ref === '#/components/schemas/ErrorEnvelope' || str_starts_with($ref, '#/components/responses/'))->all())->toBe([]);
});
