<?php

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

trait CleansTemporaryDirectories
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function trackTemporaryDirectory(string $path): string
    {
        $this->temporaryDirectories[] = $path;

        return $path;
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryDirectories) as $path) {
            $this->removeTemporaryDirectory($path);
        }

        $this->temporaryDirectories = [];

        parent::tearDown();
    }

    private function removeTemporaryDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());

                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }
}
