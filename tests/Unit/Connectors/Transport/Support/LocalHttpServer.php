<?php

namespace Tests\Unit\Connectors\Transport\Support;

final class LocalHttpServer
{
    private $socket;

    private string $host;

    private int $port;

    /**
     * @var callable(string): string
     */
    private $responder;

    private ?int $handlerPid = null;

    private bool $tls;

    public function __construct(
        callable $responder,
        string $bindHost = '127.0.0.1',
        bool $tls = false,
        ?string $certPath = null,
        ?string $keyPath = null,
    ) {
        $this->responder = $responder;
        $this->tls = $tls;
        $context = stream_context_create($tls ? [
            'ssl' => [
                'local_cert' => $certPath,
                'local_pk' => $keyPath,
                'disable_compression' => true,
                'SNI_enabled' => true,
            ],
        ] : []);

        $this->socket = stream_socket_server(
            ($tls ? 'tls' : 'tcp')."://{$bindHost}:0",
            $errorCode,
            $errorMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );

        if ($this->socket === false) {
            throw new \RuntimeException("Failed to start server: {$errorMessage}");
        }

        $name = stream_socket_get_name($this->socket, false);
        [$this->host, $this->port] = explode(':', $name);
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function serveOnceInBackground(): void
    {
        if (! function_exists('pcntl_fork')) {
            throw new \RuntimeException('pcntl_fork is required for background HTTP fixture serving.');
        }

        $socketExport = $this->exportSocketToChild();
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('Unable to fork HTTP fixture handler.');
        }

        if ($pid === 0) {
            $exitCode = 0;

            try {
                $this->runSingleRequestFromExportedSocket($socketExport);
            } catch (\Throwable $exception) {
                $exitCode = 1;
                fwrite(STDERR, 'LocalHttpServer child failed: '.get_debug_type($exception).PHP_EOL);
            } finally {
                // An exception must never return the forked PHPUnit child to the test runner.
                exit($exitCode);
            }
        }

        $this->handlerPid = $pid;
        usleep(50_000);
    }

    public function waitForHandler(): void
    {
        if ($this->handlerPid === null) {
            return;
        }

        $pid = $this->handlerPid;
        $this->handlerPid = null;
        $waitedPid = pcntl_waitpid($pid, $status);

        if ($waitedPid !== $pid) {
            throw new \RuntimeException('Failed waiting for local HTTP fixture child.');
        }

        if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            throw new \RuntimeException('Local HTTP fixture child exited unsuccessfully.');
        }
    }

    public function close(): void
    {
        try {
            $this->waitForHandler();
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
        }
    }

    /**
     * @return array{path: string, responder: callable}
     */
    private function exportSocketToChild(): array
    {
        $meta = stream_get_meta_data($this->socket);
        if (($meta['stream_type'] ?? '') !== 'tcp_socket' && ($meta['stream_type'] ?? '') !== 'tcp_socket/ssl') {
            throw new \RuntimeException('Unexpected socket type for HTTP fixture export.');
        }

        return [
            'socket' => $this->socket,
            'responder' => $this->responder,
        ];
    }

    /**
     * @param  array{socket: resource, responder: callable}  $export
     */
    private function runSingleRequestFromExportedSocket(array $export): void
    {
        $client = @stream_socket_accept($export['socket'], 5);
        if ($client === false) {
            return;
        }

        $request = '';
        while (! str_contains($request, "\r\n\r\n")) {
            $chunk = fread($client, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $request .= $chunk;
        }

        $response = ($export['responder'])($request);
        if ($this->tls) {
            // A rejected certificate can close the TLS connection before the response is sent.
            // The client test verifies the rejection; a broken-pipe write is expected here.
            @fwrite($client, $response);
        } elseif (fwrite($client, $response) === false) {
            throw new \RuntimeException('Local HTTP fixture could not write its response.');
        }

        fclose($client);
    }
}
