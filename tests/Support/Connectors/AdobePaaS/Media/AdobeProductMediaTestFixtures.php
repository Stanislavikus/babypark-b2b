<?php

namespace Tests\Support\Connectors\AdobePaaS\Media;

final class AdobeProductMediaTestFixtures
{
    public static function jpegBytes(): string
    {
        return base64_decode(
            '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCwAA8A/9k=',
            true,
        ) ?: '';
    }

    public static function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        ) ?: '';
    }

    public static function gifBytes(): string
    {
        return base64_decode(
            'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7',
            true,
        ) ?: '';
    }

    public static function sha256(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    public static function filenameForBytes(string $bytes, string $extension): string
    {
        return 'b2b-'.self::sha256($bytes).'.'.$extension;
    }

    /**
     * @return array<string, mixed>
     */
    public static function remoteProductPayloadWithGallery(string $sku, array $galleryEntries): array
    {
        return [
            'sku' => $sku,
            'name' => 'Test Product',
            'attribute_set_id' => 4,
            'type_id' => 'simple',
            'status' => 1,
            'visibility' => 4,
            'price' => 100,
            'custom_attributes' => [],
            'media_gallery_entries' => $galleryEntries,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function remoteMediaMetadataEntry(
        int $id,
        string $file,
        string $label,
        int $position,
        array $types = [],
        bool $disabled = false,
    ): array {
        return [
            'id' => $id,
            'media_type' => 'image',
            'file' => $file,
            'label' => $label,
            'position' => $position,
            'disabled' => $disabled,
            'types' => $types,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function remoteMediaContentPayload(
        int $entryId,
        string $bytes,
        string $mimeType,
        string $filename,
    ): array {
        return [
            'entry' => [
                'id' => $entryId,
                'media_type' => 'image',
                'file' => '/'.$filename,
                'label' => 'Test Product',
                'position' => 1,
                'disabled' => false,
                'types' => ['image', 'small_image', 'thumbnail'],
                'content' => [
                    'base64_encoded_data' => base64_encode($bytes),
                    'type' => $mimeType,
                    'name' => $filename,
                ],
            ],
        ];
    }
}
