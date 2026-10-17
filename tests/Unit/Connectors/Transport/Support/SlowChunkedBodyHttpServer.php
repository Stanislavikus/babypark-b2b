<?php

namespace Tests\Unit\Connectors\Transport\Support;

final class SlowChunkedBodyHttpServer
{
    private $socket;

    private string $host;

    private int $port;

    private ?int $handlerPid = null;

    private readonly string $completionMarkerPath;

    private readonly string $bytesWrittenPath;

    public function __construct(string $bindHost = '127.0.0.1')
    {
        $this->completionMarkerPath = tempnam(sys_get_temp_dir(), 'chunked_complete_');
        $this->bytesWrittenPath = tempnam(sys_get_temp_dir(), 'chunked_bytes_');
        if ($this->completionMarkerPath === false || $this->bytesWrittenPath === false) {
            throw new \RuntimeException('Unable to create chunked server state files.');
        }

        @unlink($this->completionMarkerPath);

        $this->socket = stream_socket_server(
            "tcp://{$bindHost}:0",
            $errorCode,
            $errorMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
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

    public function serveInBackground(int $totalBodyBytes, int $chunkSize, int $interChunkDelayMicroseconds): void
    {
        if (! function_exists('pcntl_fork')) {
            throw new \RuntimeException('pcntl_fork is required for background HTTP fixture serving.');
        }

        $state = [
            'socket' => $this->socket,
            'totalBodyBytes' => $totalBodyBytes,
            'chunkSize' => $chunkSize,
            'interChunkDelayMicroseconds' => $interChunkDelayMicroseconds,
            'completionMarkerPath' => $this->completionMarkerPath,
            'bytesWrittenPath' => $this->bytesWrittenPath,
        ];

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('Unable to fork chunked HTTP fixture handler.');
        }

        if ($pid === 0) {
            self::runHandler($state);
            exit(0);
        }

        $this->handlerPid = $pid;
        usleep(50_000);
    }

    public function waitForHandler(): void
    {
        if ($this->handlerPid !== null) {
            pcntl_waitpid($this->handlerPid, $status);
            $this->handlerPid = null;
        }
    }

    public function close(): void
    {
        $this->waitForHandler();

        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    public function completionMarkerExists(): bool
    {
        return is_file($this->completionMarkerPath) && filesize($this->completionMarkerPath) > 0;
    }

    public function bytesSuccessfullyWritten(): int
    {
        if (! is_file($this->bytesWrittenPath)) {
            return 0;
        }

        $contents = file_get_contents($this->bytesWrittenPath);

        return $contents === false ? 0 : (int) $contents;
    }

    /**
     * @param  array{socket: resource, totalBodyBytes: int, chunkSize: int, interChunkDelayMicroseconds: int, completionMarkerPath: string, bytesWrittenPath: string}  $state
     */
    private static function runHandler(array $state): void
    {
        $client = @stream_socket_accept($state['socket'], 5);
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

        $headers = 'HTTP/1.1 200 OK'."\r\n"
            .'Content-Type: text/plain'."\r\n"
            .'Content-Length: '.$state['totalBodyBytes']."\r\n"
            ."\r\n";

        if (! self::writeAll($client, $headers)) {
            fclose($client);
            file_put_contents($state['bytesWrittenPath'], '0');

            return;
        }

        $written = 0;
        $remaining = $state['totalBodyBytes'];

        while ($remaining > 0) {
            $chunk = str_repeat('X', min($state['chunkSize'], $remaining));
            if (! self::writeAll($client, $chunk)) {
                fclose($client);
                file_put_contents($state['bytesWrittenPath'], (string) $written);

                return;
            }

            $written += strlen($chunk);
            $remaining -= strlen($chunk);

            if ($remaining > 0) {
                usleep($state['interChunkDelayMicroseconds']);
            }
        }

        file_put_contents($state['bytesWrittenPath'], (string) $written);
        file_put_contents($state['completionMarkerPath'], 'done');
        fclose($client);
    }

    /**
     * @param  resource  $stream
     */
    private static function writeAll($stream, string $data): bool
    {
        $length = strlen($data);
        $offset = 0;

        while ($offset < $length) {
            $written = @fwrite($stream, substr($data, $offset));
            if ($written === false || $written === 0) {
                return false;
            }

            $offset += $written;
        }

        return true;
    }
}
