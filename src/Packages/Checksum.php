<?php

declare(strict_types=1);

namespace ApexToolbox\Packages;

/**
 * Verify a downloaded package against the digest the manifest published for it.
 *
 * Deliberately tiny: this is the security-critical line in the feature, and it is easier to be
 * confident in four statements than in four statements buried in an installer.
 */
final class Checksum
{
    public static function verify(string $file, string $expected): bool
    {
        $expected = strtolower(trim($expected));
        if (! PackageRecord::isValidSha256($expected) || ! is_readable($file)) {
            return false;
        }

        $actual = hash_file('sha256', $file);

        return is_string($actual) && hash_equals($expected, $actual);
    }
}
