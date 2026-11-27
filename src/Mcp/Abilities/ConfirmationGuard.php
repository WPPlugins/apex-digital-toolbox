<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\Abilities;

/**
 * Builds the permission callbacks for agency abilities.
 *
 * Defence-in-depth for writes: a write ability returns a structured `confirmation_required`
 * error on first invocation unless the input carries `confirmed: true`. Geoff also enforces
 * confirmation at its end — this is the plugin-side backstop so a write can never run silently.
 */
final class ConfirmationGuard
{
    /**
     * Permission callback for a read ability — just the WP capability check.
     */
    public static function read(string $capability): callable
    {
        return static function ($input = null) use ($capability) {
            unset($input);

            return current_user_can($capability)
                ? true
                : new \WP_Error('apex_mcp_forbidden', 'Insufficient capability.', array('status' => 403));
        };
    }

    /**
     * Permission callback for a write ability — capability check PLUS confirmation gate.
     */
    public static function write(string $capability): callable
    {
        return static function ($input = null) use ($capability) {
            if (! current_user_can($capability)) {
                return new \WP_Error('apex_mcp_forbidden', 'Insufficient capability.', array('status' => 403));
            }

            return self::confirmationDecision(is_array($input) ? $input : array());
        };
    }

    /**
     * Pure confirmation decision: true when confirmed, else a structured WP_Error (testable).
     *
     * @param array<string, mixed> $input
     * @return true|\WP_Error
     */
    public static function confirmationDecision(array $input): bool|\WP_Error
    {
        if (! empty($input['confirmed'])) {
            return true;
        }

        return new \WP_Error(
            'confirmation_required',
            'This is a write operation and requires confirmation.',
            array(
                'status' => 412,
                'instruction' => 'Re-invoke with confirmed: true to proceed.',
                'plannedInput' => $input,
            ),
        );
    }
}
