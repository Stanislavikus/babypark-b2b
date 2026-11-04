<?php

namespace App\Console\Commands;

use App\Support\CanonicalCoverage\AmazonCoverage;
use Illuminate\Console\Command;

class BuildAmazonCanonicalCoverage extends Command
{
    protected $signature = 'canonical-coverage:amazon {--check : Validate committed artifacts without regenerating}';

    protected $description = 'Generate or validate Amazon provider coverage artifacts';

    public function handle(AmazonCoverage $coverage): int
    {
        $metrics = $this->option('check') ? $coverage->validate(base_path()) : $coverage->generate(base_path());
        foreach ($metrics as $key => $value) {
            $value = is_float($value) ? number_format($value, 6, '.', '') : (is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES) : $value);
            $this->line("$key=$value");
        }

        return self::SUCCESS;
    }
}
