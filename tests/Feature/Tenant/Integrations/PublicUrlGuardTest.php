<?php

declare(strict_types=1);

use App\Modules\Integrations\Support\PublicUrlGuard;
use App\Shared\Exceptions\ApiException;

it('allows only public https addresses for tenant-supplied integration URLs', function (string $url, ?string $code): void {
    config(['integrations.allow_private_hosts' => false]);

    if ($code === null) {
        expect(PublicUrlGuard::assertPublic($url))->toBe(rtrim($url, '/'));

        return;
    }

    try {
        PublicUrlGuard::assertPublic($url);
        $this->fail("{$url} was allowed.");
    } catch (ApiException $e) {
        expect($e->errorCode)->toBe($code);
    }
})->with([
    'plain http' => ['http://8.8.8.8', 'url_invalid'],
    'credentials in the URL' => ['https://user:pass@8.8.8.8', 'url_invalid'],
    'loopback' => ['https://127.0.0.1', 'url_not_public'],
    'private range' => ['https://10.0.0.5/shop', 'url_not_public'],
    'link-local metadata' => ['https://169.254.169.254', 'url_not_public'],
    'ipv6 loopback' => ['https://[::1]', 'url_not_public'],
    'public address' => ['https://8.8.8.8/', null],
]);
