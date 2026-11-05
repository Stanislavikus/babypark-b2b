<?php

// Deliberately executed in a separate PHP process, never loaded by PHPUnit.
require_once __DIR__.'/LocalHttpServer.php';
require_once __DIR__.'/SlowChunkedBodyHttpServer.php';

use Tests\Unit\Connectors\Transport\Support\LocalHttpServer;
use Tests\Unit\Connectors\Transport\Support\SlowChunkedBodyHttpServer;

$scenario = $argv[1] ?? '';
$marker = $argv[2] ?? '';
$originalPid = getmypid();

if ($marker === '' || ! is_file($marker)) {
    fwrite(STDERR, "Missing marker path.\n");
    exit(2);
}

if ($scenario === 'handler-throws') {
    $server = new LocalHttpServer(
        fn (string $request): string => throw new RuntimeException('Deliberate child failure.'),
    );

    try {
        $server->serveOnceInBackground();
    } catch (Throwable $exception) {
        // Simulate PHPUnit catching a Throwable from an escaped fork child.
        if (getmypid() !== $originalPid) {
            file_put_contents($marker, "escaped-child\n", FILE_APPEND | LOCK_EX);
            exit(0);
        }

        throw $exception;
    }

    file_put_contents($marker, "after-serve\n", FILE_APPEND | LOCK_EX);
    $client = stream_socket_client('tcp://'.$server->host().':'.$server->port(), $errorCode, $errorMessage, 3);

    if ($client === false) {
        throw new RuntimeException("Failed connecting to local fixture: {$errorMessage}");
    }

    fwrite($client, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
    fclose($client);

    try {
        $server->waitForHandler();
        throw new RuntimeException('The parent ignored the failing child exit status.');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'Local HTTP fixture child exited unsuccessfully.') {
            throw $exception;
        }
    } finally {
        $server->close();
    }

    echo "child-failure-surfaced\n";
    exit(0);
}

if ($scenario === 'client-closes') {
    $server = new SlowChunkedBodyHttpServer;

    try {
        $server->serveInBackground(256 * 1024, 4096, 50_000);
    } catch (Throwable $exception) {
        if (getmypid() !== $originalPid) {
            file_put_contents($marker, "escaped-child\n", FILE_APPEND | LOCK_EX);
            exit(0);
        }

        throw $exception;
    }

    file_put_contents($marker, "after-serve\n", FILE_APPEND | LOCK_EX);
    $client = stream_socket_client('tcp://'.$server->host().':'.$server->port(), $errorCode, $errorMessage, 3);

    if ($client === false) {
        throw new RuntimeException("Failed connecting to chunked fixture: {$errorMessage}");
    }

    fwrite($client, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
    @stream_socket_shutdown($client, STREAM_SHUT_RDWR);
    fclose($client);

    // Closing the response before all chunks are written is expected, not a fixture failure.
    $server->close();

    if ($server->completionMarkerExists()) {
        throw new RuntimeException('Chunked fixture unexpectedly completed after client close.');
    }

    echo "benign-client-close\n";
    exit(0);
}

fwrite(STDERR, "Unknown scenario.\n");
exit(2);
