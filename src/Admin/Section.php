<?php
declare(strict_types=1);

namespace IfsDeploy\Admin;

/**
 * Headings inside a tab panel.
 *
 * The plugin name lives once in the screen header (Admin\Screen); a tab supplies its
 * own title and, beneath it, sub-section headings that separate groups of controls.
 * Both are here so every tab is laid out identically.
 */
final class Section {

	/**
	 * The tab's own title, with an optional sentence explaining what it does.
	 */
	public static function title( string $title, string $description = '' ): void {
		echo '<div class="dp-section-intro">';
		printf( '<h2 class="dp-section-title">%s</h2>', esc_html( $title ) );

		if ( '' !== $description ) {
			printf( '<p class="dp-section-description">%s</p>', esc_html( $description ) );
		}

		echo '</div>';
	}

	/**
	 * A sub-section heading — label plus a hairline rule that runs to the edge.
	 *
	 * @param string $label  Heading text.
	 * @param string $suffix Optional pre-escaped HTML pinned to the right (a count
	 *                       badge, a filter control). Callers must escape it.
	 */
	public static function heading( string $label, string $suffix = '' ): void {
		echo '<div class="dp-block-heading">';
		printf( '<h3>%s</h3>', esc_html( $label ) );

		if ( '' !== $suffix ) {
			// Caller-escaped by contract; this is a slot for already-built markup.
			echo '<div class="dp-block-heading-aside">' . $suffix . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}

		echo '</div>';
	}
}
