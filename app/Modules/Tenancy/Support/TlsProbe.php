<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use Illuminate\Support\Carbon;

/**
 * Connects to a host over HTTPS and reads the certificate the edge serves
 * (spec §7.5). Verification uses the system CA store and the host name.
 */
class TlsProbe
{
    /**
     * The expiry of a valid certificate served for the host, or null when
     * none is served.
     */
    public function certificateExpiry(string $host): ?Carbon
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ]]);

        $client = @stream_socket_client('ssl://'.$host.':443', $errno, $error, 10, STREAM_CLIENT_CONNECT, $context);

        if ($client === false) {
            return null;
        }

        $params = stream_context_get_params($client);
        fclose($client);

        $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
        $info = $certificate !== null ? openssl_x509_parse($certificate) : false;

        return is_array($info) && isset($info['validTo_time_t']) ? Carbon::createFromTimestamp((int) $info['validTo_time_t']) : null;
    }
}
