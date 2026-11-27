<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\Redirects;

/**
 * CRUD over the Redirection plugin's redirects, in terms an agent can reason about.
 *
 * Redirection's own model is a faithful reflection of its UI: a redirect carries `url`,
 * `match_type`, `action_type`, `action_code` and a polymorphic `action_data` whose shape depends
 * on the action. An agent asked to "redirect /old to /new" should not have to know any of that, and
 * making it guess would produce silently broken redirects (an `action_type` of `url` with no
 * `action_data.url` is accepted by the plugin and redirects to `/`).
 *
 * So this class owns a small, stable contract - source, target, code, group, title, regex, enabled
 * - and maps it onto the plugin's model in one place. Responses carry BOTH the friendly fields and
 * the plugin's own JSON, so an agent gets something readable while nothing is hidden from it.
 *
 * Everything is routed through Red_Item rather than direct SQL: the plugin caches redirects per
 * group and flushes those caches inside create/update/delete. Writing to the table behind its back
 * leaves stale caches and redirects that do not take effect until something else flushes them.
 */
final class RedirectManager
{
    /** Redirection's own ceiling; asking for more is silently clamped by the plugin. */
    public const MAX_PER_PAGE = 200;
    public const DEFAULT_PER_PAGE = 25;

    /**
     * Status codes the plugin accepts for a URL redirect.
     *
     * A method rather than an array class constant: phpcbf mis-splits multi-line array constants
     * into one broken `const` per element (see the phpcs caveat in CLAUDE.md).
     *
     * @return list<int>
     */
    public static function redirectCodes(): array
    {
        return array(300, 301, 302, 303, 304, 307, 308);
    }

    /**
     * List redirects, newest first by default.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function list(array $input): array|\WP_Error
    {
        $unavailable = $this->guardAvailable();
        if ($unavailable !== null) {
            return $unavailable;
        }

        $params = array(
            'per_page' => $this->perPage($input),
            'page' => max(0, (int) ($input['page'] ?? 0)),
        );

        $filterBy = array();
        if (isset($input['groupId']) && (int) $input['groupId'] > 0) {
            $filterBy['group'] = (int) $input['groupId'];
        }
        if (isset($input['search']) && (string) $input['search'] !== '') {
            $filterBy['url'] = (string) $input['search'];
        }
        if (isset($input['status']) && in_array($input['status'], array('enabled', 'disabled'), true)) {
            $filterBy['status'] = (string) $input['status'];
        }
        if ($filterBy !== array()) {
            $params['filterBy'] = $filterBy;
        }

        if (
            isset($input['orderBy'])
            && in_array($input['orderBy'], array('source', 'last_count', 'last_access', 'position'), true)
        ) {
            $params['orderby'] = (string) $input['orderBy'];
        }
        if (isset($input['direction']) && in_array($input['direction'], array('asc', 'desc'), true)) {
            $params['direction'] = (string) $input['direction'];
        }

        $result = \Red_Item::get_filtered($params);

        $items = array();
        foreach ((array) ($result['items'] ?? array()) as $row) {
            $items[] = $this->present((array) $row);
        }

        return array(
            'redirects' => $items,
            'total' => (int) ($result['total'] ?? 0),
            'page' => $params['page'],
            'perPage' => $params['per_page'],
            'pluginVersion' => RedirectionDetect::version(),
        );
    }

    /** @return array<string, mixed>|\WP_Error */
    public function get(int $id): array|\WP_Error
    {
        $item = $this->find($id);

        return $item instanceof \WP_Error
            ? $item
            : $this->present($item->to_json());
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function create(array $input): array|\WP_Error
    {
        $unavailable = $this->guardAvailable();
        if ($unavailable !== null) {
            return $unavailable;
        }

        $details = $this->toPluginDetails($input, null);
        if ($details instanceof \WP_Error) {
            return $details;
        }

        $created = \Red_Item::create($details);
        if ($created instanceof \WP_Error) {
            return $created;
        }

        return array('created' => true, 'redirect' => $this->present($created->to_json()));
    }

    /**
     * Partial update: only the keys present in $input change. Redirection's own update() replaces
     * the whole row from the details it is given, so anything omitted would be reset to a default -
     * a caller changing only the status code would silently blank the title and target. We merge
     * over the current state first.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function update(int $id, array $input): array|\WP_Error
    {
        $item = $this->find($id);
        if ($item instanceof \WP_Error) {
            return $item;
        }

        $details = $this->toPluginDetails($input, $item->to_json());
        if ($details instanceof \WP_Error) {
            return $details;
        }

        $result = $item->update($details);
        if ($result instanceof \WP_Error) {
            return $result;
        }

        // Enabling/disabling is NOT part of update(). Red_Item_Sanitize never emits a `status`
        // key, so the value we pass in $details is silently discarded and the row keeps its old
        // status - an "enabled: false" would report success and change nothing. The plugin exposes
        // enable()/disable() for this, which write the column directly.
        if (array_key_exists('enabled', $input)) {
            if ($this->toBool($input['enabled'])) {
                $item->enable();
            } else {
                $item->disable();
            }
        }

        $fresh = \Red_Item::get_by_id($id);

        return array(
            'updated' => true,
            'redirect' => $this->present($fresh === false ? array() : $fresh->to_json()),
        );
    }

    /**
     * Hard delete - Redirection has no trash for redirects. The confirmation gate on the ability is
     * the only thing between an agent and a permanently removed redirect, so we return the deleted
     * record for the audit trail / manual re-creation.
     *
     * @return array<string, mixed>|\WP_Error
     */
    public function delete(int $id): array|\WP_Error
    {
        $item = $this->find($id);
        if ($item instanceof \WP_Error) {
            return $item;
        }

        $deleted = $this->present($item->to_json());
        $item->delete();

        return array('deleted' => true, 'redirect' => $deleted);
    }

    /**
     * Redirect groups. A create needs a valid group id, so an agent needs to be able to see them.
     *
     * @return array<string, mixed>|\WP_Error
     */
    public function groups(): array|\WP_Error
    {
        $unavailable = $this->guardAvailable();
        if ($unavailable !== null) {
            return $unavailable;
        }

        $groups = array();
        foreach (\Red_Group::get_all() as $group) {
            $groups[] = array(
                'id' => (int) $group['id'],
                'name' => (string) $group['name'],
            );
        }

        return array('groups' => $groups, 'defaultGroupId' => $this->defaultGroupId());
    }

    // ------------------------------------------------------------------ mapping

    /**
     * Map the agent-facing contract onto Redirection's details array.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $current Existing redirect JSON on update; null on create.
     * @return array<string, mixed>|\WP_Error
     */
    public function toPluginDetails(array $input, ?array $current): array|\WP_Error
    {
        $isCreate = $current === null;
        $current ??= array();

        $source = $this->pick($input, array('source', 'url'), $current['url'] ?? '');
        $source = trim((string) $source);
        if ($source === '') {
            return new \WP_Error(
                'apex_mcp_redirect_invalid',
                'A source URL is required (for example "/old-page").',
                array('status' => 400),
            );
        }

        $actionType = (string) $this->pick($input, array('actionType'), $current['action_type'] ?? 'url');
        $matchType = (string) $this->pick($input, array('matchType'), $current['match_type'] ?? 'url');

        // action_data is polymorphic; for a url action it carries the destination.
        $currentTarget = is_array($current['action_data'] ?? null)
            ? ($current['action_data']['url'] ?? '')
            : (string) ($current['action_data'] ?? '');
        $target = trim((string) $this->pick($input, array('target', 'destination'), $currentTarget));

        if ($actionType === 'url' && $target === '') {
            // The plugin accepts this and quietly redirects to "/", which looks like data loss to
            // whoever visits the old URL. Refuse it instead.
            return new \WP_Error(
                'apex_mcp_redirect_invalid',
                'A target URL is required for a url redirect (for example "/new-page").',
                array('status' => 400),
            );
        }

        $code = (int) $this->pick($input, array('code', 'actionCode'), $current['action_code'] ?? 301);
        if ($actionType === 'url' && ! in_array($code, self::redirectCodes(), true)) {
            return new \WP_Error(
                'apex_mcp_redirect_invalid',
                sprintf('Unsupported redirect code %d. Use one of: %s.', $code, implode(', ', self::redirectCodes())),
                array('status' => 400),
            );
        }

        $groupId = (int) $this->pick($input, array('groupId', 'group_id'), $current['group_id'] ?? 0);
        if ($groupId <= 0) {
            $groupId = $this->defaultGroupId();
        }
        if ($groupId <= 0) {
            return new \WP_Error(
                'apex_mcp_redirect_no_group',
                'No redirect group exists to add this redirect to.',
                array('status' => 409),
            );
        }

        $enabled = array_key_exists('enabled', $input)
            ? $this->toBool($input['enabled'])
            : (bool) ($current['enabled'] ?? true);

        $details = array(
            'url' => $source,
            'match_type' => $matchType,
            'action_type' => $actionType,
            'action_code' => $code,
            'group_id' => $groupId,
            'title' => (string) $this->pick($input, array('title'), $current['title'] ?? ''),
            'regex' => (int) (bool) $this->pick($input, array('regex'), $current['regex'] ?? false),
            'status' => $enabled ? 'enabled' : 'disabled',
        );

        if ($actionType === 'url') {
            $details['action_data'] = array('url' => $target);
        }

        // Preserve ordering on update; a create lets the plugin append to the group.
        if (! $isCreate && isset($current['position'])) {
            $details['position'] = (int) $current['position'];
        }

        return $details;
    }

    /**
     * Response shape: friendly fields first, the plugin's own record kept alongside so nothing is
     * hidden and a caller can round-trip an unusual redirect we do not model.
     *
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    public function present(array $json): array
    {
        $actionData = $json['action_data'] ?? null;
        $target = is_array($actionData)
            ? (string) ($actionData['url'] ?? '')
            : (string) $actionData;

        return array(
            'id' => (int) ($json['id'] ?? 0),
            'source' => (string) ($json['url'] ?? ''),
            'target' => $target,
            'code' => (int) ($json['action_code'] ?? 0),
            'enabled' => (bool) ($json['enabled'] ?? false),
            'regex' => (bool) ($json['regex'] ?? false),
            'title' => (string) ($json['title'] ?? ''),
            'groupId' => (int) ($json['group_id'] ?? 0),
            'hits' => (int) ($json['hits'] ?? 0),
            'lastAccess' => (string) ($json['last_access'] ?? ''),
            'matchType' => (string) ($json['match_type'] ?? ''),
            'actionType' => (string) ($json['action_type'] ?? ''),
            'raw' => $json,
        );
    }

    // ------------------------------------------------------------------ internals

    /** @return \Red_Item|\WP_Error */
    private function find(int $id)
    {
        $unavailable = $this->guardAvailable();
        if ($unavailable !== null) {
            return $unavailable;
        }

        if ($id <= 0) {
            return new \WP_Error('apex_mcp_redirect_invalid', 'A redirect id is required.', array('status' => 400));
        }

        $item = \Red_Item::get_by_id($id);
        if ($item === false || ! ($item instanceof \Red_Item)) {
            return new \WP_Error(
                'apex_mcp_redirect_not_found',
                sprintf('No redirect found with id %d.', $id),
                array('status' => 404),
            );
        }

        return $item;
    }

    /**
     * A single, clearly-worded error for both "plugin missing" and "plugin present but never
     * activated" - an agent can act on it, where a raw SQL failure would just look like a bug.
     */
    private function guardAvailable(): ?\WP_Error
    {
        if (! RedirectionDetect::isActive()) {
            return new \WP_Error(
                'apex_mcp_no_redirection',
                'The Redirection plugin is not active on this site.',
                array('status' => 409),
            );
        }

        if (! RedirectionDetect::isInstalled()) {
            return new \WP_Error(
                'apex_mcp_redirection_not_installed',
                'The Redirection plugin is active but its database tables are missing; '
                    . 'open the plugin once to finish setup.',
                array('status' => 409),
            );
        }

        return null;
    }

    /** First available group id, or 0 when the site has none. */
    private function defaultGroupId(): int
    {
        foreach (\Red_Group::get_all() as $group) {
            return (int) $group['id'];
        }

        return 0;
    }

    /**
     * Interpret a boolean-ish input value.
     *
     * A plain (bool) cast is wrong here: every non-empty string is truthy in PHP, so an agent (or
     * a JSON client that stringifies) sending "false" would ENABLE a redirect it asked to disable.
     * filter_var reads the usual textual forms, and anything unrecognised resolves to false - the
     * safe direction, since a wrongly-disabled redirect is trivially reversible whereas a wrongly
     * re-enabled one silently resurrects a redirect somebody turned off.
     *
     * Shared by toPluginDetails() and update() so the two can never disagree about the same input.
     *
     * @param mixed $value
     */
    private function toBool($value): bool
    {
        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) $value;
    }

    /** @param array<string, mixed> $input */
    private function perPage(array $input): int
    {
        $perPage = (int) ($input['perPage'] ?? self::DEFAULT_PER_PAGE);
        if ($perPage <= 0) {
            $perPage = self::DEFAULT_PER_PAGE;
        }

        return min(self::MAX_PER_PAGE, $perPage);
    }

    /**
     * First present key wins, else the fallback. Lets the contract accept both our friendly names
     * and the plugin's own field names, so a caller echoing back a previous response still works.
     *
     * @param array<string, mixed> $input
     * @param list<string> $keys
     * @param mixed $fallback
     * @return mixed
     */
    private function pick(array $input, array $keys, $fallback)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $input) && $input[ $key ] !== null) {
                return $input[ $key ];
            }
        }

        return $fallback;
    }
}
