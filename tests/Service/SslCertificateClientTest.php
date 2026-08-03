<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Service;

use c975L\ConfigBundle\Service\SslCertificateClient;
use PHPUnit\Framework\TestCase;

class SslCertificateClientTest extends TestCase
{
    private ?string $certificatePath = null;

    /** @var resource|null */
    private $serverProcess;

    protected function tearDown(): void
    {
        if (null !== $this->serverProcess) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
        }
        if (null !== $this->certificatePath) {
            @unlink($this->certificatePath);
        }
    }

    public function testFetchExpiryReadsTheCertificateNotAfterDate(): void
    {
        [$port, $expectedExpiry] = $this->startTlsServer(10);

        $expiresAt = (new SslCertificateClient())->fetchExpiry('127.0.0.1', $port);

        $this->assertSame($expectedExpiry->getTimestamp(), $expiresAt->getTimestamp());
    }

    public function testFetchExpiryThrowsWhenTheConnectionFails(): void
    {
        $this->expectException(\RuntimeException::class);

        // Nothing is listening on this port
        (new SslCertificateClient())->fetchExpiry('127.0.0.1', 1);
    }

    // Spawns a background TLS server on a short-lived self-signed certificate for the client to reach
    // @return array{0: int, 1: \DateTimeImmutable}
    private function startTlsServer(int $validDays): array
    {
        $privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $privateKey, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $privateKey, $validDays, ['digest_alg' => 'sha256']);

        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($privateKey, $keyPem);

        $this->certificatePath = tempnam(sys_get_temp_dir(), 'ssl-cert-test-');
        file_put_contents($this->certificatePath, $certificatePem . $keyPem);

        $expiresAt = (new \DateTimeImmutable())->setTimestamp(openssl_x509_parse($certificate)['validTo_time_t']);

        $port = random_int(20000, 60000);
        // Loops rather than accepting one connection, the readiness probe consuming one of its own
        $script = sprintf(
            '$c=stream_context_create(["ssl"=>["local_cert"=>%s,"allow_self_signed"=>true,"verify_peer"=>false]]);'
            . '$s=stream_socket_server("tls://127.0.0.1:%d",$e,$m,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,$c);'
            . '$deadline=microtime(true)+5;'
            . 'while(microtime(true)<$deadline){if($conn=@stream_socket_accept($s,0.2)){usleep(200000);fclose($conn);}}',
            var_export($this->certificatePath, true),
            $port,
        );

        $this->serverProcess = proc_open(['php', '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        // Waits for the server to actually be listening rather than a fixed sleep
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $probe = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 0.1);
            if ($probe) {
                fclose($probe);
                break;
            }
            usleep(50000);
        }

        return [$port, $expiresAt];
    }
}
