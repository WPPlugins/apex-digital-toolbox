<?php
/**
 * Tests for toolboxHookYoast::defaultOpenGraphImage and the three filter callbacks it registers.
 *
 * The filter callbacks substitute Yoast's configured default OG image whenever the current post
 * has no featured image. WPSEO_Options is stubbed in tests/stubs/wpseo-options.php so we can drive
 * the og_default_image_id lookup without the real Yoast plugin installed.
 */

require_once dirname( __DIR__ ) . '/hooks/hook_yoast.php';

class Test_Default_Open_Graph_Image extends WP_UnitTestCase {

    /** @var toolboxHookYoast */
    private $hookController;

    /** @var int Attachment ID used as a stand-in for the default OG image */
    private $defaultOgAttachmentId;

    public function set_up() {
        parent::set_up();
        WPSEO_Options::reset();
        $toolbox              = new toolboxController();
        $this->hookController = new toolboxHookYoast( $toolbox );

        // A real attachment so wp_get_attachment_image_url() resolves to a URL
        $this->defaultOgAttachmentId = self::factory()->attachment->create_object(
            'og-default.jpg',
            0,
            array(
                'post_mime_type' => 'image/jpeg',
                'post_type'      => 'attachment',
            )
        );
    }

    public function tear_down() {
        WPSEO_Options::reset();
        parent::tear_down();
    }

    private function makePresentationFor( int $post_id ): object {
        $p         = new stdClass();
        $p->source = get_post( $post_id );
        return $p;
    }

    // ===== defaultOpenGraphImage(): filter registration =====

    public function test_defaultOpenGraphImage_registers_three_filters() {
        // Start clean - no toolboxHookYoast filters yet
        remove_all_filters( 'wpseo_opengraph_image' );
        remove_all_filters( 'wpseo_schema_webpage' );
        remove_all_filters( 'wpseo_schema_main_image' );

        $this->hookController->defaultOpenGraphImage();

        $this->assertNotFalse( has_filter( 'wpseo_opengraph_image', array( $this->hookController, 'filterOpenGraphImage' ) ) );
        $this->assertNotFalse( has_filter( 'wpseo_schema_webpage', array( $this->hookController, 'filterSchemaWebpage' ) ) );
        $this->assertNotFalse( has_filter( 'wpseo_schema_main_image', array( $this->hookController, 'filterSchemaMainImage' ) ) );
    }

    // ===== filterOpenGraphImage =====

    public function test_filterOpenGraphImage_passthrough_when_post_has_thumbnail() {
        $post_id          = self::factory()->post->create();
        $existing_thumb   = self::factory()->attachment->create_object( 'thumb.jpg', $post_id, array( 'post_mime_type' => 'image/jpeg' ) );
        set_post_thumbnail( $post_id, $existing_thumb );
        WPSEO_Options::$values['og_default_image_id'] = $this->defaultOgAttachmentId;

        $result = $this->hookController->filterOpenGraphImage( 'https://yoast/whatever.jpg', $this->makePresentationFor( $post_id ) );

        $this->assertSame( 'https://yoast/whatever.jpg', $result, 'Should defer to Yoast when post has a featured image' );
    }

    public function test_filterOpenGraphImage_passthrough_when_no_default_image_configured() {
        $post_id = self::factory()->post->create();
        // Explicitly no og_default_image_id set in WPSEO_Options
        $result = $this->hookController->filterOpenGraphImage( 'https://yoast/default.jpg', $this->makePresentationFor( $post_id ) );

        $this->assertSame( 'https://yoast/default.jpg', $result );
    }

    public function test_filterOpenGraphImage_returns_default_when_no_thumbnail_and_valid_default_configured() {
        $post_id                                      = self::factory()->post->create();
        WPSEO_Options::$values['og_default_image_id'] = $this->defaultOgAttachmentId;
        $expected_url                                 = wp_get_attachment_image_url( $this->defaultOgAttachmentId, 'full' );

        $result = $this->hookController->filterOpenGraphImage( '', $this->makePresentationFor( $post_id ) );

        $this->assertSame( $expected_url, $result );
        $this->assertNotEmpty( $result );
    }

    public function test_filterOpenGraphImage_falls_back_to_yoast_when_default_attachment_invalid() {
        $post_id                                      = self::factory()->post->create();
        WPSEO_Options::$values['og_default_image_id'] = 999999; // non-existent attachment ID

        $result = $this->hookController->filterOpenGraphImage( 'https://yoast/fallback.jpg', $this->makePresentationFor( $post_id ) );

        $this->assertSame( 'https://yoast/fallback.jpg', $result );
    }

    // ===== filterSchemaWebpage =====

    public function test_filterSchemaWebpage_passthrough_when_not_singular() {
        // No is_singular state set - default test context is index
        $data                                         = array( 'foo' => 'bar' );
        WPSEO_Options::$values['og_default_image_id'] = $this->defaultOgAttachmentId;

        $this->assertSame( $data, $this->hookController->filterSchemaWebpage( $data ) );
    }

    public function test_filterSchemaWebpage_passthrough_when_singular_but_has_thumbnail() {
        $post_id = self::factory()->post->create();
        $thumb   = self::factory()->attachment->create_object( 'thumb.jpg', $post_id, array( 'post_mime_type' => 'image/jpeg' ) );
        set_post_thumbnail( $post_id, $thumb );
        WPSEO_Options::$values['og_default_image_id'] = $this->defaultOgAttachmentId;
        $this->go_to( get_permalink( $post_id ) );

        $data = array( 'foo' => 'bar' );

        $this->assertSame( $data, $this->hookController->filterSchemaWebpage( $data ) );
    }

    public function test_filterSchemaWebpage_adds_thumbnailUrl_when_singular_no_thumbnail_and_default_configured() {
        $post_id                                      = self::factory()->post->create();
        WPSEO_Options::$values['og_default_image_id'] = $this->defaultOgAttachmentId;
        $this->go_to( get_permalink( $post_id ) );
        $expected_url = wp_get_attachment_image_url( $this->defaultOgAttachmentId, 'full' );

        $data = array( 'foo' => 'bar' );

        $result = $this->hookController->filterSchemaWebpage( $data );

        $this->assertSame( 'bar', $result['foo'] );
        $this->assertSame( $expected_url, $result['thumbnailUrl'] );
    }

    public function test_filterSchemaWebpage_passthrough_when_default_attachment_invalid() {
        $post_id                                      = self::factory()->post->create();
        WPSEO_Options::$values['og_default_image_id'] = 999999;
        $this->go_to( get_permalink( $post_id ) );

        $data = array( 'foo' => 'bar' );

        $this->assertSame( $data, $this->hookController->filterSchemaWebpage( $data ) );
    }

    // ===== filterSchemaMainImage =====

    public function test_filterSchemaMainImage_passthrough_when_not_singular() {
        WPSEO_Options::$values['og_default_image_id'] = $this->defaultOgAttachmentId;
        $image                                        = array( 'url' => 'https://yoast/u.jpg', 'contentUrl' => 'https://yoast/c.jpg' );

        $this->assertSame( $image, $this->hookController->filterSchemaMainImage( $image, null ) );
    }

    public function test_filterSchemaMainImage_passthrough_when_singular_but_has_thumbnail() {
        $post_id = self::factory()->post->create();
        $thumb   = self::factory()->attachment->create_object( 'thumb.jpg', $post_id, array( 'post_mime_type' => 'image/jpeg' ) );
        set_post_thumbnail( $post_id, $thumb );
        WPSEO_Options::$values['og_default_image_id'] = $this->defaultOgAttachmentId;
        $this->go_to( get_permalink( $post_id ) );

        $image = array( 'url' => 'https://yoast/u.jpg', 'contentUrl' => 'https://yoast/c.jpg' );

        $this->assertSame( $image, $this->hookController->filterSchemaMainImage( $image, null ) );
    }

    public function test_filterSchemaMainImage_replaces_url_and_contentUrl_when_singular_no_thumbnail_and_default_configured() {
        $post_id                                      = self::factory()->post->create();
        WPSEO_Options::$values['og_default_image_id'] = $this->defaultOgAttachmentId;
        $this->go_to( get_permalink( $post_id ) );
        $expected_url = wp_get_attachment_image_url( $this->defaultOgAttachmentId, 'full' );

        $image = array( 'url' => 'https://yoast/u.jpg', 'contentUrl' => 'https://yoast/c.jpg', 'caption' => 'kept' );

        $result = $this->hookController->filterSchemaMainImage( $image, null );

        $this->assertSame( $expected_url, $result['url'] );
        $this->assertSame( $expected_url, $result['contentUrl'] );
        $this->assertSame( 'kept', $result['caption'], 'Unrelated keys should be preserved' );
    }

    public function test_filterSchemaMainImage_passthrough_when_default_attachment_invalid() {
        $post_id                                      = self::factory()->post->create();
        WPSEO_Options::$values['og_default_image_id'] = 999999;
        $this->go_to( get_permalink( $post_id ) );

        $image = array( 'url' => 'https://yoast/u.jpg', 'contentUrl' => 'https://yoast/c.jpg' );

        $this->assertSame( $image, $this->hookController->filterSchemaMainImage( $image, null ) );
    }
}
