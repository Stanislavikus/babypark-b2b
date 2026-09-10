<?php

namespace App\Support\Connectors\Transport\Policy;

/**
 * IANA Special-Purpose Address Registry snapshot.
 *
 * IPv4 source: https://www.iana.org/assignments/iana-ipv4-special-registry/iana-ipv4-special-registry.xhtml
 * IPv6 source: https://www.iana.org/assignments/iana-ipv6-special-registry/iana-ipv6-special-registry.xhtml
 * Snapshot/review date: 2026-07-24
 */
final class IanaSpecialPurposeRegistry
{
    /**
     * @var list<array{prefix: string, globallyReachable: bool}>
     */
    private const IPV4_ENTRIES = [
        ['prefix' => '0.0.0.0/8', 'globallyReachable' => false],
        ['prefix' => '10.0.0.0/8', 'globallyReachable' => false],
        ['prefix' => '100.64.0.0/10', 'globallyReachable' => false],
        ['prefix' => '127.0.0.0/8', 'globallyReachable' => false],
        ['prefix' => '169.254.0.0/16', 'globallyReachable' => false],
        ['prefix' => '172.16.0.0/12', 'globallyReachable' => false],
        ['prefix' => '192.0.0.0/24', 'globallyReachable' => true],
        ['prefix' => '192.0.0.0/29', 'globallyReachable' => false],
        ['prefix' => '192.0.0.170/31', 'globallyReachable' => false],
        ['prefix' => '192.0.0.171/32', 'globallyReachable' => false],
        ['prefix' => '192.0.2.0/24', 'globallyReachable' => false],
        ['prefix' => '192.88.99.0/24', 'globallyReachable' => true],
        ['prefix' => '192.168.0.0/16', 'globallyReachable' => false],
        ['prefix' => '198.18.0.0/15', 'globallyReachable' => false],
        ['prefix' => '198.51.100.0/24', 'globallyReachable' => false],
        ['prefix' => '203.0.113.0/24', 'globallyReachable' => false],
        ['prefix' => '240.0.0.0/4', 'globallyReachable' => false],
        ['prefix' => '255.255.255.255/32', 'globallyReachable' => false],
    ];

    /**
     * @var list<array{prefix: string, globallyReachable: bool}>
     */
    private const IPV6_ENTRIES = [
        ['prefix' => '::/128', 'globallyReachable' => false],
        ['prefix' => '::1/128', 'globallyReachable' => false],
        ['prefix' => '::ffff:0:0/96', 'globallyReachable' => true],
        ['prefix' => '64:ff9b::/96', 'globallyReachable' => true],
        ['prefix' => '100::/64', 'globallyReachable' => false],
        ['prefix' => '2001::/23', 'globallyReachable' => true],
        ['prefix' => '2001::/32', 'globallyReachable' => true],
        ['prefix' => '2001:2::/48', 'globallyReachable' => false],
        ['prefix' => '2001:db8::/32', 'globallyReachable' => false],
        ['prefix' => '2001:10::/28', 'globallyReachable' => false],
        ['prefix' => '2001:20::/28', 'globallyReachable' => false],
        ['prefix' => '2002::/16', 'globallyReachable' => true],
        ['prefix' => 'fc00::/7', 'globallyReachable' => false],
        ['prefix' => 'fe80::/10', 'globallyReachable' => false],
        ['prefix' => 'ff00::/8', 'globallyReachable' => false],
    ];

    /**
     * @return list<array{prefix: string, globallyReachable: bool}>
     */
    public static function ipv4Entries(): array
    {
        return self::IPV4_ENTRIES;
    }

    /**
     * @return list<array{prefix: string, globallyReachable: bool}>
     */
    public static function ipv6Entries(): array
    {
        return self::IPV6_ENTRIES;
    }
}
