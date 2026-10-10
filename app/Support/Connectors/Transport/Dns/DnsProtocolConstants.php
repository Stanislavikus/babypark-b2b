<?php

namespace App\Support\Connectors\Transport\Dns;

final class DnsProtocolConstants
{
    public const VERSION = 1;

    public const MAX_STDIN_BYTES = 1024;

    public const MAX_STDOUT_BYTES = 65536;

    public const MAX_STDERR_BYTES = 4096;

    public const MAX_TOTAL_DNS_RECORDS = 64;

    public const MAX_TERMINAL_ADDRESSES = 64;

    public const MAX_CNAME_DEPTH = 8;

    public const CLEANUP_DIAGNOSTIC_THRESHOLD_MS = 250;

    public const SUPERVISION_POLL_INTERVAL_MS = 10;

    /**
     * @var list<string>
     */
    public const ERROR_REASONS = [
        'lookup_failed',
        'loop_detected',
        'depth_exceeded',
        'no_terminal_address',
        'multiple_cname_targets',
        'cname_with_address_records',
        'invalid_record',
    ];
}
