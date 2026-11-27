<?php

declare(strict_types=1);

namespace ApexToolbox\Packages;

use Automatic_Upgrader_Skin;

/**
 * An automatic skin that keeps hold of the reason an install failed.
 *
 * WP_Upgrader::run() returns a WP_Error when the download fails, but
 * Plugin_Upgrader::upgrade() ignores that return and reports $this->result,
 * which on that path is still the empty string it was initialised to. So the
 * caller gets a falsy value carrying no reason at all, and every failure looks
 * the same.
 *
 * That matters here more than it would elsewhere. A refused checksum is not a
 * flaky download - it means the bytes did not match what the registry published,
 * and telling an operator only that "WordPress could not install the package"
 * hides the one failure worth investigating. The skin is handed every error on
 * its way past, so this keeps the first one.
 */
final class InstallerSkin extends Automatic_Upgrader_Skin
{
    private ?string $firstError = null;

    /**
     * @param string|\WP_Error $errors
     * @param mixed ...$args
     */
    public function error($errors, ...$args): void
    {
        if ($this->firstError === null) {
            $this->firstError = $this->describe($errors);
        }

        parent::error($errors, ...$args);
    }

    /** The first error this install reported, or null if it reported none. */
    public function firstError(): ?string
    {
        return $this->firstError;
    }

    /** @param mixed $error */
    private function describe($error): ?string
    {
        if ($error instanceof \WP_Error) {
            $message = $error->get_error_message();

            return $message !== ''
                ? $message
                : $error->get_error_code();
        }

        if (! is_string($error) || $error === '') {
            return null;
        }

        // The upgrader passes string KEYS into its own strings table, so resolve
        // one where it exists rather than showing the operator 'download_failed'.
        $strings = is_object($this->upgrader) && isset($this->upgrader->strings)
            ? (array) $this->upgrader->strings
            : array();

        return isset($strings[$error])
            ? (string) $strings[$error]
            : $error;
    }
}
