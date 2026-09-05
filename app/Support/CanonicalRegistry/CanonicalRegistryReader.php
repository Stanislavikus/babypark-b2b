<?php

namespace App\Support\CanonicalRegistry;

class CanonicalRegistryReader
{
    /** @var array<string, list<array<string, string>>> */
    private array $datasets = [];

    private readonly string $dataPath;

    public function __construct(?string $dataPath = null)
    {
        $this->dataPath = $dataPath ?? config('canonical_registry.data_path');
    }

    /**
     * @return list<array<string, string>>
     */
    public function fields(): array
    {
        return $this->load('canonical_product_fields.csv');
    }

    /**
     * @return list<array<string, string>>
     */
    public function mappings(): array
    {
        return $this->load('canonical_product_field_mappings.csv');
    }

    /**
     * @return list<array<string, string>>
     */
    public function channelDecisions(): array
    {
        return $this->load('canonical_product_field_channel_decisions.csv');
    }

    /**
     * @return list<array<string, string>>
     */
    public function sources(): array
    {
        return $this->load('canonical_product_field_sources.csv');
    }

    /**
     * @return list<array<string, string>>
     */
    public function applicability(): array
    {
        return $this->load('canonical_product_field_applicability.csv');
    }

    /**
     * @return list<array{channel: string, channel_schema_version: string}>
     */
    public function channelColumns(): array
    {
        $columns = [];

        foreach (array_merge($this->mappings(), $this->channelDecisions()) as $row) {
            $key = $row['channel'].'|'.$row['channel_schema_version'];
            $columns[$key] = [
                'channel' => $row['channel'],
                'channel_schema_version' => $row['channel_schema_version'],
            ];
        }

        return array_values($columns);
    }

    /**
     * @return list<string>
     */
    public function schemaVersionsForChannel(string $channel): array
    {
        return collect($this->channelColumns())
            ->filter(fn (array $column): bool => $column['channel'] === $channel)
            ->pluck('channel_schema_version')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, string>>
     */
    private function load(string $filename): array
    {
        if (isset($this->datasets[$filename])) {
            return $this->datasets[$filename];
        }

        $path = rtrim($this->dataPath, '/').'/'.$filename;
        if (! is_file($path)) {
            return $this->datasets[$filename] = [];
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return $this->datasets[$filename] = [];
        }

        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);

            return $this->datasets[$filename] = [];
        }

        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            if ($data === [null] || $data === []) {
                continue;
            }

            if (count($data) !== count($headers)) {
                continue;
            }

            $row = array_combine($headers, $data);
            if ($row !== false) {
                $rows[] = $row;
            }
        }

        fclose($handle);

        return $this->datasets[$filename] = $rows;
    }
}
