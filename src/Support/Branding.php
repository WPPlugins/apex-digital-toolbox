<?php

declare(strict_types=1);

namespace ApexToolbox\Support;

/**
 * Single source of truth for the plugin's toolbox mark, shared by the OAuth consent screen and the
 * wp-admin menu so the icon only has to be defined (and tweaked) in one place.
 */
final class Branding
{
    /**
     * The toolbox glyph as an inline <svg> element — a SOLID silhouette so it matches the other
     * (filled) admin-menu icons. The handle opening and the lid/body seam are transparent cut-outs
     * (negative space), so they show the background through rather than drawing visible lines.
     *
     * @param int $size Width/height in px.
     * @param string $color Fill colour ('currentColor' to inherit, or an explicit value).
     */
    public static function toolboxSvg(int $size = 22, string $color = 'currentColor'): string
    {
        // xmlns is REQUIRED when this SVG is used as a background-image data URI (the wp-admin menu
        // icon); without it the browser won't render the standalone SVG document.
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" '
                . 'width="%1$d" height="%1$d" fill="%2$s" fill-rule="evenodd" aria-hidden="true">'
                . '<path d="M9 4a2 2 0 0 0-2 2V7H4a2 2 0 0 0-2 2V11H22V9a2 2 0 0 0-2-2H17V6a2 2 0 0 0-2-2H9Z'
                . 'M9 5.5H15V7H9Z"/>'
                . '<path d="M4 13H20a2 2 0 0 1 2 2V19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V15a2 2 0 0 1 2-2Z"/>'
                . '</svg>',
            $size,
            $color,
        );
    }

    /**
     * Base64-encoded data URI of the toolbox icon for add_menu_page().
     *
     * Coloured #a7aaad — the default admin-menu icon grey — so it reads correctly on the dark menu.
     * (Background-image menu icons aren't auto-recoloured by WordPress, so we set a fixed colour.)
     */
    public static function adminMenuIcon(): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::toolboxSvg(20, '#a7aaad'));
    }
}
