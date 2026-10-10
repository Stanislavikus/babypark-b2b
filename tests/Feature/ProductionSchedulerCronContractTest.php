<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProductionSchedulerCronContractTest extends TestCase
{
    #[Test]
    public function pilot_scheduler_cron_uses_non_blocking_os_lock_before_laravel_boot(): void
    {
        $content = trim(File::get(base_path('ops/cron/babypark-scheduler.cron')));
        $lines = collect(preg_split('/\R/', $content))
            ->map(fn (string $line): string => trim($line))
            ->filter(fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'))
            ->values();

        $this->assertCount(1, $lines);

        $line = $lines->sole();

        $this->assertSame(
            '* * * * * cd /var/www/babypark-b2b && /usr/bin/flock -n /run/lock/babypark-scheduler.lock /usr/bin/php artisan schedule:run --no-interaction >> /dev/null 2>&1',
            $line,
        );
        $this->assertStringContainsString('/usr/bin/flock -n', $line);
        $this->assertLessThan(
            strpos($line, '/usr/bin/php artisan schedule:run'),
            strpos($line, '/usr/bin/flock -n'),
            'Host lock must be acquired before Laravel boots.',
        );
    }

    #[Test]
    public function deployment_docs_keep_merge_and_scheduler_activation_separate(): void
    {
        $deploy = File::get(base_path('DEPLOY.md'));
        $decision = File::get(base_path('docs/reviews/SCHEDULER_FLOCK_HARDENING_2026_10_06.md'));

        $this->assertStringContainsString('ops/cron/babypark-scheduler.cron', $deploy);
        $this->assertStringContainsString('/usr/bin/flock -n /run/lock/babypark-scheduler.lock', $deploy);
        $this->assertStringContainsString('requires separate authorization', $deploy);

        $this->assertStringContainsString('do not write application lock code', $decision);
        $this->assertStringContainsString('repository merge alone', $decision);
        $this->assertStringContainsString('Rollback:', $decision);
    }
}
