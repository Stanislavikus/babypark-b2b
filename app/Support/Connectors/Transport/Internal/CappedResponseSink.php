<?php

namespace App\Support\Connectors\Transport\Internal;

use Psr\Http\Message\StreamInterface;

final class CappedResponseSink
{
    private int $bytesWritten = 0;

    public function __construct(
        private readonly int $maxBytes,
        private readonly string $path,
    ) {}

    public function write(string $chunk): void
    {
        $length = strlen($chunk);
        if ($this->bytesWritten + $length > $this->maxBytes) {
            throw new ResponseSizeExceededAbort;
        }

        $written = file_put_contents($this->path, $chunk, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            throw new ResponseSizeExceededAbort;
        }

        $this->bytesWritten += $length;
    }

    public function bytesWritten(): int
    {
        return $this->bytesWritten;
    }

    public function contents(): string
    {
        $contents = file_get_contents($this->path);

        return $contents === false ? '' : $contents;
    }

    public function cleanup(): void
    {
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

final class CountingStreamWrapper
{
    public static function create(StreamInterface $stream, CappedResponseSink $sink): StreamInterface
    {
        return new class($stream, $sink) implements StreamInterface
        {
            public function __construct(
                private readonly StreamInterface $inner,
                private readonly CappedResponseSink $sink,
            ) {}

            public function __toString(): string
            {
                return $this->getContents();
            }

            public function close(): void
            {
                $this->inner->close();
            }

            public function detach()
            {
                return $this->inner->detach();
            }

            public function getSize(): ?int
            {
                return $this->inner->getSize();
            }

            public function tell(): int
            {
                return $this->inner->tell();
            }

            public function eof(): bool
            {
                return $this->inner->eof();
            }

            public function isSeekable(): bool
            {
                return $this->inner->isSeekable();
            }

            public function seek(int $offset, int $whence = SEEK_SET): void
            {
                $this->inner->seek($offset, $whence);
            }

            public function rewind(): void
            {
                $this->inner->rewind();
            }

            public function isWritable(): bool
            {
                return $this->inner->isWritable();
            }

            public function write(string $string): int
            {
                return $this->inner->write($string);
            }

            public function isReadable(): bool
            {
                return $this->inner->isReadable();
            }

            public function read(int $length): string
            {
                $data = $this->inner->read($length);
                if ($data !== '') {
                    $this->sink->write($data);
                }

                return $data;
            }

            public function getContents(): string
            {
                $remaining = $this->inner->getContents();
                if ($remaining !== '') {
                    $this->sink->write($remaining);
                }

                return $remaining;
            }

            public function getMetadata(?string $key = null)
            {
                return $this->inner->getMetadata($key);
            }
        };
    }
}
