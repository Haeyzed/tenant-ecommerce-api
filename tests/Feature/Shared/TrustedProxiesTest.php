<?php

declare(strict_types=1);

use App\Shared\Http\TrustedProxies;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::get('/_probe/client', static fn (Request $request): array => [
        'ip' => $request->ip(),
        'host' => $request->getHost(),
        'secure' => $request->isSecure(),
    ]);
});

afterEach(function (): void {
    TrustProxies::flushState();
});

function probeClient(string $remoteAddr): array
{
    return test()->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'victim.example.test'])
        ->getJson('http://probe.test/_probe/client')->assertOk()->json();
}

it('uses the forwarded client address and scheme only from a trusted proxy, and never the forwarded host', function (): void {
    config(['app.trusted_proxies' => '10.20.0.0/16, 192.0.2.10']);
    TrustedProxies::configure();

    expect(probeClient('10.20.3.4'))->toBe(['ip' => '203.0.113.9', 'host' => 'probe.test', 'secure' => true])
        ->and(probeClient('192.0.2.10')['ip'])->toBe('203.0.113.9')
        // Anyone else is the client itself; its forwarded headers are ignored.
        ->and(probeClient('198.51.100.7'))->toBe(['ip' => '198.51.100.7', 'host' => 'probe.test', 'secure' => false]);
});

it('trusts nobody by default', function (): void {
    config(['app.trusted_proxies' => '']);
    TrustedProxies::configure();

    expect(probeClient('10.20.3.4'))->toBe(['ip' => '10.20.3.4', 'host' => 'probe.test', 'secure' => false]);
});
