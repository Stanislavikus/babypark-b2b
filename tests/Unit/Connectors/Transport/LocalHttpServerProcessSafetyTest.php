<?php

namespace Tests\Unit\Connectors\Transport;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Connectors\Transport\Support\LocalHttpServer;

class LocalHttpServerProcessSafetyTest extends TestCase
{
    #[Test]
    public function unexpected_child_handler_failure_does_not_escape_into_phpunit(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This test requires pcntl_fork.');
        }

        $server = new LocalHttpServer(
            fn (string $request): string => throw new \RuntimeException('Intentional fixture handler error.'),
        );

        $server->serveOnceInBackground();

        $client = stream_socket_client(
            'tcp://'.$server->host().':'.$server->port(),
            $errorCode,
            $errorMessage,
            3,
        );

        $this->assertIsResource($client);
        fwrite($client, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
        fclose($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Local HTTP fixture child exited unsuccessfully.');

        // On the old fixture, this returns successfully and the forked child
        // continues PHPUnit instead. The parent must now reject the child status.
        $server->close();
    }
}
