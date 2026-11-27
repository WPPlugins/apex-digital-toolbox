<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp;

/**
 * The allow-list of abilities exposed to Geoff, and the read/write classification Geoff pushes
 * with it. Single source of truth, written only by the exposure-sync endpoint; read by the MCP
 * server (tools/list), the manifest, and the write-confirmation layer.
 *
 * Backed by the `apex_mcp_exposed_abilities` option: a list of {name, isWrite}.
 */
final class ExposedAbilities
{
    public const OPTION = 'apex_mcp_exposed_abilities';

    /**
     * Pure reconciliation (no I/O) — testable in isolation.
     *
     * @param list<array{name?:string,isWrite?:bool}> $incoming
     * @param list<string> $registeredNames
     * @return array{accepted:list<array{name:string,isWrite:bool}>,ignored:list<string>}
     */
    public static function reconcile(array $incoming, array $registeredNames): array
    {
        $registered = array_flip($registeredNames);
        $accepted = array();
        $ignored = array();

        foreach ($incoming as $entry) {
            $name = isset($entry['name'])
                ? (string) $entry['name']
                : '';
            if ($name === '') {
                continue;
            }

            if (! isset($registered[ $name ])) {
                $ignored[] = $name;

                continue;
            }

            $accepted[] = array('name' => $name, 'isWrite' => ! empty($entry['isWrite']));
        }

        return array('accepted' => $accepted, 'ignored' => $ignored);
    }

    /** @return list<array{name:string,isWrite:bool}> */
    public function all(): array
    {
        $stored = get_option(self::OPTION, array());

        return is_array($stored)
            ? array_values($stored)
            : array();
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn (array $e): string => (string) $e['name'], $this->all());
    }

    public function isWrite(string $name): bool
    {
        foreach ($this->all() as $entry) {
            if ($entry['name'] === $name) {
                return ! empty($entry['isWrite']);
            }
        }

        return false;
    }

    /**
     * Replace the allow-list with Geoff's payload, dropping any entry whose name isn't currently
     * registered on the site. Returns the accepted + ignored split for the response.
     *
     * @param list<array{name?:string,isWrite?:bool}> $incoming
     * @param list<string> $registeredNames
     * @return array{accepted:list<array{name:string,isWrite:bool}>,ignored:list<string>}
     */
    public function sync(array $incoming, array $registeredNames): array
    {
        $result = self::reconcile($incoming, $registeredNames);

        update_option(self::OPTION, $result['accepted'], false);

        return array('accepted' => $result['accepted'], 'ignored' => $result['ignored']);
    }
}
