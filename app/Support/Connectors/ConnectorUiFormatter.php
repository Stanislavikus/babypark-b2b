<?php

namespace App\Support\Connectors;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

final class ConnectorUiFormatter
{
    public static function formatDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = $value instanceof CarbonInterface
            ? $value
            : Carbon::parse($value);

        return $date
            ->locale(app()->getLocale())
            ->isoFormat('L LT');
    }

    public static function formatDuration(?int $durationMs): ?string
    {
        if ($durationMs === null) {
            return null;
        }

        if ($durationMs < 1000) {
            return __('connectors.ui.duration.milliseconds', [
                'value' => Number::format($durationMs, locale: app()->getLocale()),
            ]);
        }

        $seconds = round($durationMs / 1000, 1);

        return __('connectors.ui.duration.seconds', [
            'value' => Number::format($seconds, maxPrecision: 1, locale: app()->getLocale()),
        ]);
    }
}
