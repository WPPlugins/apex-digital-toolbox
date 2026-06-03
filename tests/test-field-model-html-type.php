<?php
/**
 * Regression tests for toolboxFieldModel's `html` field type, which is used by
 * read-only status panels on the settings page (e.g. the Elementor revisions panel).
 *
 * Bug history: the constructor used to call setValue() before setType(), so getType()
 * returned the 'text' fallback at that moment and every value got esc_attr()'d - which
 * turned <table>...</table> markup into &lt;table&gt;...&lt;/table&gt; in the rendered
 * output. These tests lock in the correct contract.
 */

class Test_Field_Model_Html_Type extends WP_UnitTestCase {

    public function test_html_type_preserves_raw_markup_through_constructor() {
        $html = '<table class="widefat"><tr><td>Hello</td></tr></table>';

        $field = new toolboxFieldModel( array(
            'name'  => 'panel_a',
            'label' => 'Panel A',
            'type'  => 'html',
            'value' => $html,
        ) );

        $this->assertSame( $html, $field->getValue() );
    }

    public function test_html_type_outputField_returns_raw_markup_not_entities() {
        $html = '<a href="https://example.test/admin?action=foo&_wpnonce=abc" class="button">Run</a>';

        $field = new toolboxFieldModel( array(
            'name'  => 'panel_b',
            'label' => 'Panel B',
            'type'  => 'html',
            'value' => $html,
        ) );

        $output = $field->outputField();

        $this->assertStringContainsString( '<a href="', $output );
        $this->assertStringContainsString( 'class="button"', $output );
        $this->assertStringNotContainsString( '&lt;a', $output );
        $this->assertStringNotContainsString( '&quot;', $output );
    }

    public function test_html_type_appends_description_after_markup() {
        $field = new toolboxFieldModel( array(
            'name'        => 'panel_c',
            'type'        => 'html',
            'value'       => '<p>Body</p>',
            'description' => 'Helper text below the panel.',
        ) );

        $output = $field->outputField();

        $this->assertStringContainsString( '<p>Body</p>', $output );
        $this->assertStringContainsString( 'Helper text below the panel.', $output );
        $this->assertStringContainsString( 'class="description"', $output );
        $this->assertLessThan(
            strpos( $output, 'Helper text below the panel.' ),
            strpos( $output, '<p>Body</p>' ),
            'Body should appear before the description'
        );
    }

    // ===== Non-html types must keep their existing escaping behavior =====

    public function test_text_type_still_escapes_attribute_value() {
        $field = new toolboxFieldModel( array(
            'name'  => 'username',
            'type'  => 'text',
            'value' => 'O\'Brien & "co"',
        ) );

        $output = $field->outputField();

        $this->assertStringContainsString( 'O&#039;Brien', $output, "Apostrophe should be escaped for the attribute" );
        $this->assertStringContainsString( '&amp;', $output, "Ampersand should be escaped for the attribute" );
        $this->assertStringContainsString( '&quot;', $output, "Quote should be escaped for the attribute" );
    }

    public function test_url_type_passes_value_through_esc_url() {
        $field = new toolboxFieldModel( array(
            'name'  => 'site_url',
            'type'  => 'url',
            'value' => 'https://example.test/path with space',
        ) );

        $output = $field->outputField();

        // esc_url replaces spaces with %20 in URL values
        $this->assertStringContainsString( 'path%20with%20space', $output );
    }
}
