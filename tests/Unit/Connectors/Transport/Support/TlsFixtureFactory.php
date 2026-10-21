<?php

namespace Tests\Unit\Connectors\Transport\Support;

final class TlsFixtureFactory
{
    public static function create(string $directory, string $commonName): array
    {
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Unable to create TLS fixture directory.');
        }

        $caKey = $directory.'/ca.key.pem';
        $caCert = $directory.'/ca.pem';
        $serverKey = $directory.'/server.key.pem';
        $serverCsr = $directory.'/server.csr.pem';
        $serverCert = $directory.'/server.pem';

        self::runOpenSsl(['genrsa', '-out', $caKey, '2048']);
        self::runOpenSsl([
            'req', '-x509', '-new', '-nodes', '-key', $caKey,
            '-sha256', '-days', '3650', '-subj', '/CN=Connector Transport Test CA',
            '-out', $caCert,
        ]);
        self::runOpenSsl(['genrsa', '-out', $serverKey, '2048']);
        self::runOpenSsl([
            'req', '-new', '-key', $serverKey,
            '-subj', '/CN='.$commonName,
            '-out', $serverCsr,
        ]);
        self::runOpenSsl([
            'x509', '-req', '-in', $serverCsr,
            '-CA', $caCert, '-CAkey', $caKey, '-CAcreateserial',
            '-out', $serverCert, '-days', '3650', '-sha256',
        ]);

        return [
            'caBundle' => $caCert,
            'serverCert' => $serverCert,
            'serverKey' => $serverKey,
        ];
    }

    /**
     * @param  list<string>  $args
     */
    private static function runOpenSsl(array $args): void
    {
        $command = 'openssl '.implode(' ', array_map('escapeshellarg', $args)).' 2>&1';
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException('openssl failed: '.implode("\n", $output));
        }
    }
}
