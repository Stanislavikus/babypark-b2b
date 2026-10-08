<?php

namespace App\Support\Media;

use Illuminate\Http\UploadedFile;

final readonly class PreparedOriginalImage
{
    public function __construct(
        public UploadedFile $file,
        public string $mimeType,
        public string $extension,
        public int $byteSize,
        public string $sha256,
        public int $widthPx,
        public int $heightPx,
    ) {}
}
