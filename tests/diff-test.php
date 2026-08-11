<?php
declare(strict_types=1);

// Minimal WP stubs — PackageDiff only reaches for wp_json_encode.
function wp_json_encode( $data, $flags = 0 ) {
	return json_encode( $data, $flags );
}

require __DIR__ . '/../src/Support/Json.php';
require __DIR__ . '/../src/Support/PackageDiff.php';

use IfsDeploy\Support\PackageDiff;

function pkg( array $overrides = array() ): array {
	return array_replace_recursive(
		array(
			'object'         => array(
				'post_title'     => 'Test Demo',
				'post_content'   => "<p>Line one.</p>\n<p>Line two.</p>",
				'post_excerpt'   => '',
				'post_status'    => 'publish',
				'post_name'      => 'test-demo',
				'post_type'      => 'page',
				'post_parent'    => 4,
				'menu_order'     => 0,
				'post_date'      => '2026-01-01 10:00:00',
				'post_date_gmt'  => '2026-01-01 10:00:00',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_password'  => '',
			),
			'meta'           => array(
				'hero_subtitle'  => array( 'Trusted since 1998' ),
				'_hero_subtitle' => array( 'field_abc123' ),
			),
			'taxonomies'     => array(),
			'featured_image' => null,
		),
		$overrides
	);
}

function report( string $name, array $staging, ?array $prod ): void {
	echo "=== {$name} ===\n";
	$fields = PackageDiff::compare( $staging, $prod );
	if ( ! $fields ) {
		echo "  (no differences)\n\n";
		return;
	}
	foreach ( $fields as $f ) {
		printf(
			"  [%-8s] %-14s %-16s  before=%s  after=%s\n",
			$f['change'],
			$f['group'],
			$f['label'],
			var_export( mb_strimwidth( $f['before'], 0, 34, '…' ), true ),
			var_export( mb_strimwidth( $f['after'], 0, 34, '…' ), true )
		);
	}
	echo "\n";
}

// 1. Title + one ACF field changed.
report(
	'title + ACF field changed',
	pkg( array( 'object' => array( 'post_title' => 'Test Demo 1' ), 'meta' => array( 'hero_subtitle' => array( 'Trusted since 1997' ) ) ) ),
	pkg()
);

// 2. Identical packages -> must be silent.
report( 'identical', pkg(), pkg() );

// 3. Brand new on Production.
report( 'not on production', pkg(), null );

// 4. Production has extra meta -> "kept", never "removed".
report(
	'production-only meta',
	pkg(),
	pkg( array( 'meta' => array( 'prod_only_note' => array( 'set by an editor on live' ) ) ) )
);

// 5. Taxonomy in the package -> removal is real. Taxonomy absent -> kept.
report(
	'taxonomy removal is real',
	pkg( array( 'taxonomies' => array( 'category' => array( array( 'slug' => 'news' ) ) ) ) ),
	pkg( array( 'taxonomies' => array( 'category' => array( array( 'slug' => 'news' ), array( 'slug' => 'events' ) ) ) ) )
);
report(
	'taxonomy absent from package is kept',
	pkg(),
	pkg( array( 'taxonomies' => array( 'post_tag' => array( array( 'slug' => 'live-only' ) ) ) ) )
);

// 6. Featured image removed on Staging -> kept (importer never clears one).
report(
	'featured image removed on staging',
	pkg(),
	pkg( array( 'featured_image' => array( 'filename' => 'hero.jpg', 'alt' => 'Hero' ) ) )
);

// 7. Repeater / array meta.
report(
	'array (repeater) meta changed',
	pkg( array( 'meta' => array( 'cards' => array( array( array( 'heading' => 'A' ), array( 'heading' => 'B' ) ) ) ) ) ),
	pkg( array( 'meta' => array( 'cards' => array( array( array( 'heading' => 'A' ) ) ) ) ) )
);

// 8. post_parent / post_date_gmt differ but must NOT be reported.
report(
	'excluded fields (parent, date_gmt) differ only',
	pkg( array( 'object' => array( 'post_parent' => 99, 'post_date_gmt' => '2026-02-02 00:00:00' ) ) ),
	pkg()
);

// 9. Multi-value meta.
report(
	'multi-value meta',
	pkg( array( 'meta' => array( 'gallery_ids' => array( '11', '12', '13' ) ) ) ),
	pkg( array( 'meta' => array( 'gallery_ids' => array( '11', '12' ) ) ) )
);

// 10. Parent re-parented: compared by slug, so it IS reported.
report(
	'parent changed (by slug)',
	array_merge( pkg(), array( 'parent_slug' => 'about-us' ) ),
	array_merge( pkg(), array( 'parent_slug' => 'company' ) )
);

// 11. Same parent slug, different parent IDs -> must stay silent.
report(
	'same parent slug, different parent IDs',
	array_merge( pkg( array( 'object' => array( 'post_parent' => 7 ) ) ), array( 'parent_slug' => 'about-us' ) ),
	array_merge( pkg( array( 'object' => array( 'post_parent' => 91 ) ) ), array( 'parent_slug' => 'about-us' ) )
);

// 12. parent_slug absent entirely (delete path / legacy) -> no parent row.
report( 'no parent_slug key supplied', pkg(), pkg() );
