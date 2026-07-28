<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\Abilities;

use ApexToolbox\Lib\Action;
use ApexToolbox\Mcp\Content\ContentList;
use ApexToolbox\Mcp\Content\ContentReader;
use ApexToolbox\Mcp\OAuth\Scopes;
use ApexToolbox\Mcp\Seo\Detect;
use ApexToolbox\Mcp\Seo\SeoMeta;
use ApexToolbox\Mcp\Seo\YoastSettings;

/**
 * Registers the agency's own abilities with the WordPress Abilities API.
 *
 * Read abilities require only the WP capability; write abilities additionally require the
 * confirmation flag (see ConfirmationGuard). SEO abilities are registered only when an SEO plugin
 * is detected, so a site without one never exposes them.
 *
 * This is a representative set wired end-to-end; further agency abilities follow the same shape.
 */
final class AbilitiesController
{
    private const CATEGORY = 'apex';
    private const CAPABILITY = 'manage_options';

    #[Action('wp_abilities_api_categories_init')]
    public function registerCategory(): void
    {
        if (! function_exists('wp_register_ability_category')) {
            return;
        }

        wp_register_ability_category(
            self::CATEGORY,
            array(
                'label' => __('Apex Toolbox', 'apex_toolbox'),
                'description' => __('Agency tools exposed to the Limelight Digital AI assistant.', 'apex_toolbox'),
            ),
        );
    }

    #[Action('wp_abilities_api_init')]
    public function registerAbilities(): void
    {
        if (! function_exists('wp_register_ability')) {
            return;
        }

        $this->registerDeploymentMetadata();
        $this->registerHealthProbe();
        $this->registerGetPostContent();
        $this->registerListContent();
        $this->registerFlushCache();

        if (Detect::detect() !== null) {
            $this->registerSeoAbilities();
        }

        // Site-wide settings are Yoast-specific (storage differs per plugin), so gate on Yoast.
        if (Detect::detect() === 'yoast') {
            $this->registerSeoSettingsAbilities();
        }
    }

    // ------------------------------------------------------------------ read abilities

    /** @return array<string, mixed> */
    public function executeDeploymentMetadata(): array
    {
        return array(
            'gitSha' => defined('APEX_TOOLBOX_GIT_SHA') ? (string) constant('APEX_TOOLBOX_GIT_SHA') : null,
            'buildTime' => defined('APEX_TOOLBOX_BUILD_TIME') ? (string) constant('APEX_TOOLBOX_BUILD_TIME') : null,
        );
    }

    /** @return array<string, mixed> */
    public function executeHealthProbe(): array
    {
        return array(
            'homeReachable' => (string) home_url() !== '',
            // get_users() works in any context; wp_count_users() lives in wp-admin/includes and
            // is not loaded on REST/front-end requests (it fataled the MCP tool call).
            'usersExist' => count(get_users(array('number' => 1, 'fields' => 'ids'))) > 0,
        );
    }

    /** @return array<string, mixed> */
    public function executeFlushCache(): array
    {
        wp_cache_flush();

        return array('flushed' => true);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function executeGetPostContent(array $input): array|\WP_Error
    {
        $postId = (int) ($input['postId'] ?? 0);
        $format = (string) ($input['format'] ?? 'markdown');

        return (new ContentReader())->read($postId, $format);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function executeListContent(array $input): array
    {
        return (new ContentList(Detect::activeAdapter()))->list($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function executeGetSeoMeta(array $input): array|\WP_Error
    {
        $adapter = Detect::activeAdapter();
        if ($adapter === null) {
            return new \WP_Error('apex_mcp_no_seo', 'No supported SEO plugin is active.', array('status' => 409));
        }

        return $adapter->get((int) ($input['postId'] ?? 0))->toArray();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function executeUpdateSeoMeta(array $input): array|\WP_Error
    {
        $adapter = Detect::activeAdapter();
        if ($adapter === null) {
            return new \WP_Error('apex_mcp_no_seo', 'No supported SEO plugin is active.', array('status' => 409));
        }

        $postId = (int) ($input['postId'] ?? 0);
        $adapter->update($postId, SeoMeta::fromArray((array) ($input['fields'] ?? array())));

        return array('updated' => true, 'meta' => $adapter->get($postId)->toArray());
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function executeGetSeoSettings(array $input): array
    {
        $postTypes = $this->stringList($input['postTypes'] ?? null);
        $taxonomies = $this->stringList($input['taxonomies'] ?? null);

        return (new YoastSettings())->read($postTypes, $taxonomies);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function executeUpdateSeoSettings(array $input): array
    {
        $updated = (new YoastSettings())->update($input);

        return array('updated' => true, 'settings' => $updated);
    }

    /**
     * Normalise an optional list input to a clean string list, or null when absent/empty.
     *
     * @param mixed $value
     * @return list<string>|null
     */
    private function stringList($value): ?array
    {
        if (! is_array($value) || $value === array()) {
            return null;
        }

        return array_values(array_map('strval', $value));
    }

    private function registerDeploymentMetadata(): void
    {
        wp_register_ability(
            'apex/get-deployment-metadata',
            array(
                'label' => __('Get deployment metadata', 'apex_toolbox'),
                'description' => __('Returns the deployed git SHA and build timestamp.', 'apex_toolbox'),
                'category' => self::CATEGORY,
                'input_schema' => array('type' => 'object', 'properties' => new \stdClass()),
                'output_schema' => array('type' => 'object'),
                'execute_callback' => array($this, 'executeDeploymentMetadata'),
                'permission_callback' => ConfirmationGuard::read(self::CAPABILITY),
                'meta' => $this->meta(false, Scopes::READ_ABILITIES),
            ),
        );
    }

    private function registerHealthProbe(): void
    {
        wp_register_ability(
            'apex/health-probe',
            array(
                'label' => __('Health probe', 'apex_toolbox'),
                'description' => __('Runs lightweight synthetic checks and returns a status matrix.', 'apex_toolbox'),
                'category' => self::CATEGORY,
                'input_schema' => array('type' => 'object', 'properties' => new \stdClass()),
                'output_schema' => array('type' => 'object'),
                'execute_callback' => array($this, 'executeHealthProbe'),
                'permission_callback' => ConfirmationGuard::read(self::CAPABILITY),
                'meta' => $this->meta(false, Scopes::READ_ABILITIES),
            ),
        );
    }

    private function registerGetPostContent(): void
    {
        wp_register_ability(
            'apex/get-post-content',
            array(
                'label' => __('Get post content', 'apex_toolbox'),
                'description' => __('Reads a post/page/CPT body as Markdown, page-builder aware.', 'apex_toolbox'),
                'category' => self::CATEGORY,
                'input_schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'postId' => array('type' => 'integer'),
                        'format' => array(
                            'type' => 'string',
                            'enum' => array('markdown', 'text', 'html'),
                        ),
                    ),
                    'required' => array('postId'),
                ),
                'output_schema' => array('type' => 'object'),
                'execute_callback' => array($this, 'executeGetPostContent'),
                'permission_callback' => ConfirmationGuard::read(self::CAPABILITY),
                'meta' => $this->meta(false, Scopes::READ_ABILITIES),
            ),
        );
    }

    private function registerListContent(): void
    {
        wp_register_ability(
            'apex/list-content',
            array(
                'label' => __('List content', 'apex_toolbox'),
                'description' => __('Lists posts/pages/CPTs; filter to those missing SEO meta.', 'apex_toolbox'),
                'category' => self::CATEGORY,
                'input_schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'postType' => array('type' => array('string', 'array')),
                        'status' => array('type' => 'string'),
                        'search' => array('type' => 'string'),
                        'missing' => array(
                            'type' => 'string',
                            'enum' => array('title', 'description', 'either'),
                        ),
                        'page' => array('type' => 'integer'),
                        'perPage' => array('type' => 'integer'),
                    ),
                ),
                'output_schema' => array('type' => 'object'),
                'execute_callback' => array($this, 'executeListContent'),
                'permission_callback' => ConfirmationGuard::read(self::CAPABILITY),
                'meta' => $this->meta(false, Scopes::READ_ABILITIES),
            ),
        );
    }

    // ------------------------------------------------------------------ write abilities

    private function registerFlushCache(): void
    {
        wp_register_ability(
            'apex/flush-plugin-cache',
            array(
                'label' => __('Flush plugin cache', 'apex_toolbox'),
                'description' => __('Clears the plugin transients and object cache. [WRITE]', 'apex_toolbox'),
                'category' => self::CATEGORY,
                'input_schema' => array(
                    'type' => 'object',
                    'properties' => array('confirmed' => array('type' => 'boolean')),
                ),
                'output_schema' => array('type' => 'object'),
                'execute_callback' => array($this, 'executeFlushCache'),
                'permission_callback' => ConfirmationGuard::write(self::CAPABILITY),
                'meta' => $this->meta(true, Scopes::WRITE_CACHE),
            ),
        );
    }

    // ------------------------------------------------------------------ SEO abilities

    private function registerSeoAbilities(): void
    {
        wp_register_ability(
            'apex/get-seo-meta',
            array(
                'label' => __('Get SEO meta', 'apex_toolbox'),
                'description' => __('Reads SEO meta for a post via the active SEO plugin.', 'apex_toolbox'),
                'category' => self::CATEGORY,
                'input_schema' => array(
                    'type' => 'object',
                    'properties' => array('postId' => array('type' => 'integer')),
                    'required' => array('postId'),
                ),
                'output_schema' => array('type' => 'object'),
                'execute_callback' => array($this, 'executeGetSeoMeta'),
                'permission_callback' => ConfirmationGuard::read(self::CAPABILITY),
                'meta' => $this->meta(false, Scopes::READ_ABILITIES),
            ),
        );

        wp_register_ability(
            'apex/update-seo-meta',
            array(
                'label' => __('Update SEO meta', 'apex_toolbox'),
                'description' => __('Updates SEO meta for a post. [WRITE - confirmation required]', 'apex_toolbox'),
                'category' => self::CATEGORY,
                'input_schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'postId' => array('type' => 'integer'),
                        'fields' => array('type' => 'object'),
                        'confirmed' => array('type' => 'boolean'),
                    ),
                    'required' => array('postId', 'fields'),
                ),
                'output_schema' => array('type' => 'object'),
                'execute_callback' => array($this, 'executeUpdateSeoMeta'),
                'permission_callback' => ConfirmationGuard::write(self::CAPABILITY),
                'meta' => $this->meta(true, Scopes::WRITE_SEO),
            ),
        );
    }

    // -------------------------------------------------------------- SEO settings (Yoast) abilities

    private function registerSeoSettingsAbilities(): void
    {
        wp_register_ability(
            'apex/get-seo-settings',
            array(
                'label' => __('Get SEO settings', 'apex_toolbox'),
                'description' => __('Reads Yoast site-wide settings: indexing + default templates.', 'apex_toolbox'),
                'category' => self::CATEGORY,
                'input_schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'postTypes' => array('type' => 'array', 'items' => array('type' => 'string')),
                        'taxonomies' => array('type' => 'array', 'items' => array('type' => 'string')),
                    ),
                ),
                'output_schema' => array('type' => 'object'),
                'execute_callback' => array($this, 'executeGetSeoSettings'),
                'permission_callback' => ConfirmationGuard::read(self::CAPABILITY),
                'meta' => $this->meta(false, Scopes::READ_ABILITIES),
            ),
        );

        wp_register_ability(
            'apex/update-seo-settings',
            array(
                'label' => __('Update SEO settings', 'apex_toolbox'),
                'description' => __('Changes Yoast site-wide settings. [WRITE - confirm required]', 'apex_toolbox'),
                'category' => self::CATEGORY,
                'input_schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'postTypes' => array('type' => 'object'),
                        'taxonomies' => array('type' => 'object'),
                        'confirmed' => array('type' => 'boolean'),
                    ),
                ),
                'output_schema' => array('type' => 'object'),
                'execute_callback' => array($this, 'executeUpdateSeoSettings'),
                'permission_callback' => ConfirmationGuard::write(self::CAPABILITY),
                'meta' => $this->meta(true, Scopes::WRITE_SEO_SETTINGS),
            ),
        );
    }

    // ------------------------------------------------------------------ execute callbacks


    /** @return array<string, mixed> */
    private function meta(bool $isWrite, string $scope): array
    {
        return array(
            'show_in_rest' => true,
            'annotations' => array(
                'readOnlyHint' => ! $isWrite,
                'destructiveHint' => $isWrite,
            ),
            'apex' => array('isWrite' => $isWrite, 'scope' => $scope),
        );
    }
}
