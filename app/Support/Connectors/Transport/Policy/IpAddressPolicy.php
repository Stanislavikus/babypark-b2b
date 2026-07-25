<?php

namespace App\Support\Connectors\Transport\Policy;

final class IpAddressPolicy
{
    /**
     * @var list<array{network: string, prefixLength: int, globallyReachable: bool}>
     */
    private readonly array $ipv4Entries;

    /**
     * @var list<array{network: string, prefixLength: int, globallyReachable: bool}>
     */
    private readonly array $ipv6Entries;

    public function __construct()
    {
        $this->ipv4Entries = $this->parseEntries(IanaSpecialPurposeRegistry::ipv4Entries(), 4);
        $this->ipv6Entries = $this->parseEntries(IanaSpecialPurposeRegistry::ipv6Entries(), 16);
    }

    public function isGloballyReachable(string $address): bool
    {
        if (str_contains($address, '%')) {
            return false;
        }

        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 16 && $this->isIpv4Mapped($packed)) {
            $embeddedIpv4 = substr($packed, 12);

            return $this->matchesRegistry($embeddedIpv4, $this->ipv4Entries);
        }

        if (strlen($packed) === 4) {
            return $this->matchesRegistry($packed, $this->ipv4Entries);
        }

        return $this->matchesRegistry($packed, $this->ipv6Entries);
    }

    public function normalize(string $address): ?string
    {
        if (str_contains($address, '%')) {
            return null;
        }

        $packed = @inet_pton($address);
        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 16 && $this->isIpv4Mapped($packed)) {
            $packed = substr($packed, 12);
        }

        $normalized = inet_ntop($packed);

        return $normalized === false ? null : $normalized;
    }

    /**
     * @param  list<string>  $addresses
     * @return list<string> normalized, deduplicated addresses sorted for pinning
     */
    public function filterAndSortReachable(array $addresses): array
    {
        $normalized = [];

        foreach ($addresses as $address) {
            if (! $this->isGloballyReachable($address)) {
                throw new \InvalidArgumentException('unsafe');
            }

            $canonical = $this->normalize($address);
            if ($canonical === null) {
                throw new \InvalidArgumentException('malformed');
            }

            $packed = inet_pton($canonical);
            if ($packed === false) {
                throw new \InvalidArgumentException('malformed');
            }

            $normalized[bin2hex($packed)] = $canonical;
        }

        if ($normalized === []) {
            return [];
        }

        $sorted = array_values($normalized);
        usort($sorted, function (string $a, string $b): int {
            $packedA = inet_pton($a);
            $packedB = inet_pton($b);
            if ($packedA === false || $packedB === false) {
                return $a <=> $b;
            }

            $familyRankA = strlen($packedA) === 4 ? 0 : 1;
            $familyRankB = strlen($packedB) === 4 ? 0 : 1;
            if ($familyRankA !== $familyRankB) {
                return $familyRankA <=> $familyRankB;
            }

            return strcmp($packedA, $packedB);
        });

        return $sorted;
    }

    /**
     * @param  list<array{prefix: string, globallyReachable: bool}>  $entries
     * @return list<array{network: string, prefixLength: int, globallyReachable: bool}>
     */
    private function parseEntries(array $entries, int $bytes): array
    {
        $parsed = [];

        foreach ($entries as $entry) {
            [$network, $prefixLength] = explode('/', $entry['prefix'], 2);
            $packed = inet_pton($network);
            if ($packed === false || strlen($packed) !== $bytes) {
                continue;
            }

            $parsed[] = [
                'network' => $packed,
                'prefixLength' => (int) $prefixLength,
                'globallyReachable' => $entry['globallyReachable'],
            ];
        }

        return $parsed;
    }

    /**
     * @param  list<array{network: string, prefixLength: int, globallyReachable: bool}>  $registry
     */
    private function matchesRegistry(string $packed, array $registry): bool
    {
        $bestMatch = null;
        $bestPrefixLength = -1;

        foreach ($registry as $entry) {
            if ($this->addressInPrefix($packed, $entry['network'], $entry['prefixLength'])) {
                if ($entry['prefixLength'] > $bestPrefixLength) {
                    $bestPrefixLength = $entry['prefixLength'];
                    $bestMatch = $entry;
                }
            }
        }

        if ($bestMatch === null) {
            return true;
        }

        return $bestMatch['globallyReachable'];
    }

    private function addressInPrefix(string $address, string $network, int $prefixLength): bool
    {
        $fullBytes = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;

        if (strncmp($address, $network, $fullBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($address[$fullBytes]) & $mask) === (ord($network[$fullBytes]) & $mask);
    }

    private function isIpv4Mapped(string $packed): bool
    {
        if (strlen($packed) !== 16) {
            return false;
        }

        return substr($packed, 0, 10) === str_repeat("\0", 10)
            && substr($packed, 10, 2) === "\xff\xff";
    }
}
