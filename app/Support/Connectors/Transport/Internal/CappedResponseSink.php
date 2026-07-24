<?php

namespace App\Support\Connectors\Transport\Internal;

use Psr\Http\Message\StreamInterface;

final class CappedResponseSink implements StreamInterface
{
    private int $bytesWritten = 0;

    /** @var resource */
    private $handle;

    private bool $closed = false;

    public function __construct(
        private readonly int $maxBytes,
        private readonly string $path,
    ) {
        $handle = fopen($this->path, 'wb');
        if ($handle === false) {
            throw new ResponseSizeExceededAbort;
        }

        $this->handle = $handle;
    }

    public function __toString(): string
    {
        return $this->contents();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        fclose($this->handle);
        $this->closed = true;
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return $this->bytesWritten;
    }

    public function tell(): int
    {
        return $this->bytesWritten;
    }

    public function eof(): bool
    {
        return true;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('Capped response sink is not seekable.');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('Capped response sink is not seekable.');
    }

    public function isWritable(): bool
    {
        return ! $this->closed;
    }

    public function write($string): int
    {
        if ($this->closed) {
            throw new ResponseSizeExceededAbort;
        }

        $length = strlen($string);
        if ($length === 0) {
            return 0;
        }

        if ($this->bytesWritten + $length > $this->maxBytes) {
            throw new ResponseSizeExceededAbort;
        }

        $written = fwrite($this->handle, $string);
        if ($written === false || $written === 0) {
            throw new ResponseSizeExceededAbort;
        }

        $this->bytesWritten += $written;

        return $written;
    }

    public function isReadable(): bool
    {
        return false;
    }

    public function read(int $length): string
    {
        throw new \RuntimeException('Capped response sink is not readable.');
    }

    public function getContents(): string
    {
        if ($this->bytesWritten > $this->maxBytes) {
            throw new ResponseSizeExceededAbort;
        }

        if (! $this->closed) {
            fflush($this->handle);
        }

        $contents = file_get_contents($this->path);

        return $contents === false ? '' : $contents;
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }

    public function bytesWritten(): int
    {
        return $this->bytesWritten;
    }

    public function cleanup(): void
    {
        if (! $this->closed) {
            $this->close();
        }

        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}

final class ResponseSizeExceededAbort extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Response size exceeded.');
    }
}
