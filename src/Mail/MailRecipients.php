<?php

declare(strict_types=1);

namespace ApexToolbox\Mail;

/**
 * Pulls deliverable addresses out of a wp_mail() argument list or a FluentSMTP log row.
 *
 * Both shapes carry `to` and `headers`, but the headers are not the same thing. wp_mail()
 * passes raw header lines (`Cc: a@b.com`). FluentSMTP stores a parsed array whose `cc` and
 * `bcc` keys hold `{email, name}` rows, and whose `to` column is a serialized list of the
 * same. Reply-To and From are not recipients: an email with only those still has nobody to
 * deliver to.
 *
 * A serialized value is decoded with classes disallowed. A log row is data, and FluentSMTP
 * itself refuses objects when it unserializes one.
 */
final class MailRecipients
{
    /**
     * Whether $source has at least one address wp_mail() could deliver to.
     *
     * @param array<string, mixed> $source A wp_mail() $atts array, or a FluentSMTP log row.
     */
    public static function hasDeliverableRecipient(array $source): bool
    {
        return self::deliverableAddresses($source) !== array();
    }

    /**
     * Every deliverable address on $source, To plus Cc and Bcc.
     *
     * @param array<string, mixed> $source
     * @return list<string>
     */
    public static function deliverableAddresses(array $source): array
    {
        return self::unique(array_merge(
            self::addresses($source['to'] ?? null),
            self::copyAddresses($source['headers'] ?? null),
        ));
    }

    /**
     * Decode one layer of PHP serialization. Anything that is not a serialized string is
     * returned unchanged, including a truncated blob is_serialized() rejects.
     */
    public static function unwrap(mixed $value): mixed
    {
        if (! is_string($value) || ! function_exists('is_serialized') || ! is_serialized($value)) {
            return $value;
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
        $decoded = unserialize(trim($value), array('allowed_classes' => false));

        // unserialize() returns false both for the serialized boolean false and for garbage
        // that slipped past is_serialized(). Only the real boolean is a successful decode.
        if ($decoded === false && $value !== 'b:0;') {
            return $value;
        }

        return $decoded;
    }

    /**
     * Addresses in a To value: a string, a list of strings, or FluentSMTP's `{email, name}` rows.
     *
     * @return list<string>
     */
    public static function addresses(mixed $value): array
    {
        $value = self::unwrap($value);

        if (is_string($value) || is_int($value) || is_float($value)) {
            return self::emailsInString((string) $value);
        }

        if (! is_array($value)) {
            return array();
        }

        $found = array();

        if (isset($value['email']) && is_string($value['email'])) {
            $found = array_merge($found, self::emailsInString($value['email']));
        }

        foreach ($value as $key => $item) {
            if ($key === 'email') {
                continue;
            }

            $found = array_merge($found, self::addresses($item));
        }

        return self::unique($found);
    }

    /**
     * Cc and Bcc addresses from either raw header lines or FluentSMTP's parsed header array.
     *
     * @return list<string>
     */
    public static function copyAddresses(mixed $headers): array
    {
        $headers = self::unwrap($headers);

        if (is_string($headers)) {
            return self::fromHeaderLines($headers);
        }

        if (! is_array($headers)) {
            return array();
        }

        $found = array();

        foreach ($headers as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), array('cc', 'bcc'), true)) {
                $found = array_merge($found, self::addresses($value));

                continue;
            }

            if (is_int($key) && is_string($value)) {
                $found = array_merge($found, self::fromHeaderLines($value));
            }
        }

        return self::unique($found);
    }

    /** @return list<string> */
    private static function fromHeaderLines(string $block): array
    {
        $found = array();
        $lines = preg_split("/\r\n|\n|\r/", $block);

        if (! is_array($lines)) {
            return array();
        }

        foreach ($lines as $line) {
            if (! is_string($line) || ! preg_match('/^\s*(cc|bcc)\s*:\s*(.*)$/i', $line, $matches)) {
                continue;
            }

            $found = array_merge($found, self::emailsInString($matches[2]));
        }

        return $found;
    }

    /** @return list<string> */
    private static function emailsInString(string $value): array
    {
        $found = array();

        if (preg_match_all('/<([^<>]+)>/', $value, $matches)) {
            foreach ($matches[1] as $address) {
                $found[] = trim($address);
            }

            $value = preg_replace('/<[^<>]+>/', ' ', $value) ?? $value;
            $value = preg_replace('/"[^"]*"/', ' ', $value) ?? $value;
        }

        $parts = preg_split('/[\s,;]+/', $value);

        if (! is_array($parts)) {
            $parts = array();
        }

        foreach ($parts as $part) {
            if (! is_string($part)) {
                continue;
            }

            $part = trim($part, " \t\n\r\0\x0B<>\"'");

            if ($part !== '') {
                $found[] = $part;
            }
        }

        $emails = array();

        foreach ($found as $candidate) {
            if (is_email($candidate)) {
                $emails[] = $candidate;
            }
        }

        return self::unique($emails);
    }

    /**
     * @param list<string> $emails
     * @return list<string>
     */
    private static function unique(array $emails): array
    {
        $seen = array();

        foreach ($emails as $email) {
            $seen[strtolower($email)] = $email;
        }

        return array_values($seen);
    }
}
