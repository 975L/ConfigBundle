<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Service;

// Reads a host's TLS certificate expiry via a raw TLS handshake - openssl_x509_parse() needs the certificate itself, not an HTTP response, so this opens (then immediately closes) a bare TLS socket rather than issuing a real request. Peer verification is deliberately disabled: this is a read-only diagnostic reading whatever certificate the host presents (including an expired or self-signed one), not a connection meant to carry real traffic. Used by SslCertificateHealthCheckProvider, only ever from the c975l:health-check:run command
class SslCertificateClient
{
    public function fetchExpiry(string $host, int $port = 443): \DateTimeImmutable
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]]);

        $client = @stream_socket_client(
            "ssl://{$host}:{$port}",
            $errno,
            $errstr,
            10,
            \STREAM_CLIENT_CONNECT,
            $context,
        );
        if (false === $client) {
            throw new \RuntimeException($errstr ?: 'TLS connection failed');
        }

        $params = stream_context_get_params($client);
        fclose($client);

        $certificate = openssl_x509_parse($params['options']['ssl']['peer_certificate'] ?? '');
        if (false === $certificate || !isset($certificate['validTo_time_t'])) {
            throw new \RuntimeException('Unable to read the certificate');
        }

        return (new \DateTimeImmutable())->setTimestamp($certificate['validTo_time_t']);
    }
}
