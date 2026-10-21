<?php

namespace App\Support\Connectors\Transport\Dns;

final readonly class DnsResolutionResult
{
    /**
     * @param  list<array{owner: string, target: string}>  $cnameChain
     * @param  list<string>  $addresses
     */
    public function __construct(
        public bool $success,
        public ?string $requestedHostname,
        public array $cnameChain,
        public ?string $terminalOwner,
        public array $addresses,
        public ?string $errorReason,
        public bool $timedOut,
        public bool $protocolFailed,
        public bool $cleanupFailed,
    ) {}

    public static function timeout(): self
    {
        return new self(
            success: false,
            requestedHostname: null,
            cnameChain: [],
            terminalOwner: null,
            addresses: [],
            errorReason: null,
            timedOut: true,
            protocolFailed: false,
            cleanupFailed: false,
        );
    }

    public static function protocolFailed(): self
    {
        return new self(
            success: false,
            requestedHostname: null,
            cnameChain: [],
            terminalOwner: null,
            addresses: [],
            errorReason: null,
            timedOut: false,
            protocolFailed: true,
            cleanupFailed: false,
        );
    }

    public static function cleanupFailed(): self
    {
        return new self(
            success: false,
            requestedHostname: null,
            cnameChain: [],
            terminalOwner: null,
            addresses: [],
            errorReason: null,
            timedOut: false,
            protocolFailed: false,
            cleanupFailed: true,
        );
    }

    /**
     * @param  list<array{owner: string, target: string}>  $cnameChain
     * @param  list<string>  $addresses
     */
    public static function ok(string $requestedHostname, array $cnameChain, string $terminalOwner, array $addresses): self
    {
        return new self(
            success: true,
            requestedHostname: $requestedHostname,
            cnameChain: $cnameChain,
            terminalOwner: $terminalOwner,
            addresses: $addresses,
            errorReason: null,
            timedOut: false,
            protocolFailed: false,
            cleanupFailed: false,
        );
    }

    public static function dnsError(string $reason): self
    {
        return new self(
            success: false,
            requestedHostname: null,
            cnameChain: [],
            terminalOwner: null,
            addresses: [],
            errorReason: $reason,
            timedOut: false,
            protocolFailed: false,
            cleanupFailed: false,
        );
    }
}
