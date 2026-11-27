<?php

namespace App\Console\Commands;

use App\Support\CanonicalRegistry\CanonicalRegistryValidator;
use Illuminate\Console\Command;

class ValidateCanonicalRegistry extends Command
{
    protected $signature = 'canonical-registry:validate
                            {--data-path= : Path to docs/data directory}
                            {--registry-document= : Path to CANONICAL_PRODUCT_FIELD_REGISTRY.md}
                            {--strict-evidence : Fail on warnings (missing source evidence)}';

    protected $description = 'Validate canonical product field registry CSV files against the machine validation contract';

    public function handle(): int
    {
        $dataPath = $this->option('data-path') ?? config('canonical_registry.data_path');
        $registryDocumentPath = $this->option('registry-document') ?? config('canonical_registry.registry_document_path');

        $validator = new CanonicalRegistryValidator($dataPath, $registryDocumentPath);
        $result = $validator->validate();

        $this->info('Canonical Product Field Registry validation');
        $this->line('Data path: '.$dataPath);
        $this->line('Registry document: '.$registryDocumentPath);
        $this->newLine();

        $this->info('Metrics (row counts):');
        foreach (['fields', 'mappings', 'aliases', 'sources', 'options', 'option_mappings', 'constraints', 'applicability', 'channel_decisions'] as $key) {
            $count = $result['metrics'][$key] ?? 0;
            $this->line(sprintf('  %-18s %d', $key.':', $count));
        }

        $this->newLine();
        $this->line('Errors: '.count($result['errors']));
        foreach ($result['errors'] as $error) {
            $this->error('  '.$error);
        }

        $this->newLine();
        $this->line('Warnings: '.count($result['warnings']));
        foreach ($result['warnings'] as $warning) {
            $this->warn('  '.$warning);
        }

        $hasErrors = count($result['errors']) > 0;
        $hasWarnings = count($result['warnings']) > 0;

        if ($this->option('strict-evidence')) {
            return ($hasErrors || $hasWarnings) ? self::FAILURE : self::SUCCESS;
        }

        return $hasErrors ? self::FAILURE : self::SUCCESS;
    }
}
