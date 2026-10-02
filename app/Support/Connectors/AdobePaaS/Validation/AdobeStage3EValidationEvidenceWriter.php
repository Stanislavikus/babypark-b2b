<?php

namespace App\Support\Connectors\AdobePaaS\Validation;

use Illuminate\Support\Facades\Storage;
use JsonException;

final class AdobeStage3EValidationEvidenceWriter
{
    /** @var list<array<string, mixed>> */
    private array $httpEvents = [];

    /** @var list<array<string, mixed>> */
    private array $scenarioEvents = [];

    /**
     * @param  array<string, mixed>  $event
     */
    public function recordHttpEvent(array $event): void
    {
        $this->httpEvents[] = $event;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function recordScenarioEvent(array $event): void
    {
        $this->scenarioEvents[] = $event;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function write(string $runId, array $document): string
    {
        $payload = array_merge($document, [
            'http_events' => $this->httpEvents,
            'scenario_events' => $this->scenarioEvents,
        ]);

        try {
            $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Stage 3E validation evidence encoding failed.', 0, $exception);
        }

        $relativePath = trim((string) config('adobe_stage3e_validation.artifact_directory', 'stage3e-validation'), '/')
            .'/'.$runId.'.json';

        Storage::disk('local')->put($relativePath, $encoded);

        return Storage::disk('local')->path($relativePath);
    }
}
