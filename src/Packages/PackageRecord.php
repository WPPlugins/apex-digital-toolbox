<?php

declare(strict_types=1);

namespace ApexToolbox\Packages;

/**
 * One entry from the registry manifest, normalised and validated.
 *
 * Pure: no WordPress state, no I/O. Everything the update filter, the admin table and the installer
 * read comes through here, so this is the one place a malformed or hostile manifest is stopped.
 *
 * The rule that matters most is the download_url check. The firewall's update-package download
 * window arms on whatever URL the upgrader is about to fetch - that is correct for a vendor whose
 * package URL we cannot predict, but OUR package URL is entirely predictable, so a record pointing
 * anywhere but the registry over https is a bug or an attack and is dropped rather than trusted.
 */
final class PackageRecord
{
    /**
     * A usable record, or null when the entry cannot be trusted. Callers drop nulls silently: one
     * bad entry must not cost a site every other package it is entitled to.
     *
     * @param mixed $raw
     * @return array<string, mixed>|null
     */
    public static function normalise($raw, string $registryBase = PackagesConfig::REGISTRY_BASE): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $slug = self::text($raw, 'slug');
        $version = self::text($raw, 'version');
        $sha256 = strtolower(self::text($raw, 'sha256'));
        $downloadUrl = self::text($raw, 'download_url');

        if (
            preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/i', $slug) !== 1
            || $version === ''
            || ! self::isValidSha256($sha256)
            || ! self::isRegistryUrl($downloadUrl, $registryBase)
        ) {
            return null;
        }

        return array(
            'slug' => $slug,
            'name' => self::text($raw, 'name') !== '' ? self::text($raw, 'name') : $slug,
            'description' => self::text($raw, 'description'),
            'version' => $version,
            'requires' => self::text($raw, 'requires'),
            'requires_php' => self::text($raw, 'requires_php'),
            'tested' => self::text($raw, 'tested'),
            'last_updated' => self::text($raw, 'last_updated'),
            'homepage' => self::text($raw, 'homepage'),
            'author' => self::text($raw, 'author'),
            'changelog' => self::text($raw, 'changelog'),
            'upgrade_notice' => self::text($raw, 'upgrade_notice'),
            'sha256' => $sha256,
            'download_url' => $downloadUrl,
            'download_expires' => self::timestamp($raw, 'download_expires'),
        );
    }

    /** A 64-character lowercase hex digest, and nothing else. */
    public static function isValidSha256(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    /**
     * Whether a URL sits on the registry this site actually talks to.
     *
     * Scheme, host AND port must all match the base - not a suffix match, and
     * not host alone. A lookalike host is not ours, and on a development copy
     * pointed at localhost the port is the only thing separating the registry
     * from every other service on the machine.
     */
    public static function isRegistryUrl(string $url, string $registryBase = PackagesConfig::REGISTRY_BASE): bool
    {
        if ($url === '') {
            return false;
        }

        $url = wp_parse_url($url);
        $base = wp_parse_url($registryBase);
        if (! is_array($url) || ! is_array($base)) {
            return false;
        }

        $scheme = static fn (array $p): string => strtolower((string) ($p['scheme'] ?? ''));
        $host = static fn (array $p): string => strtolower((string) ($p['host'] ?? ''));
        // An absent port means the scheme's default, so https://h and https://h:443 are one place.
        $port = static fn (array $p): int => isset($p['port'])
            ? (int) $p['port']
            : ($scheme($p) === 'http' ? 80 : 443);

        return $scheme($url) === $scheme($base)
            && $host($url) === $host($base)
            && $host($url) !== ''
            && $port($url) === $port($base);
    }

    /**
     * Whether the installed WordPress and PHP satisfy the record's minimums. An unmet requirement
     * is shown as "not compatible" rather than offered and then failed by core's own check.
     */
    public static function isCompatible(array $record, string $wpVersion, string $phpVersion): bool
    {
        foreach (array('requires' => $wpVersion, 'requires_php' => $phpVersion) as $key => $have) {
            $need = (string) ($record[$key] ?? '');
            if ($need !== '' && version_compare($have, $need, '<')) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $raw */
    private static function text(array $raw, string $key): string
    {
        $value = $raw[$key] ?? '';

        return is_scalar($value)
            ? trim((string) $value)
            : '';
    }

    /** @param array<string, mixed> $raw */
    private static function timestamp(array $raw, string $key): ?int
    {
        $value = $raw[$key] ?? null;

        return is_numeric($value)
            ? (int) $value
            : null;
    }
}
