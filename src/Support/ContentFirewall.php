<?php
declare(strict_types=1);

namespace IfsDeploy\Support;

/**
 * Strips executable markup from imported content (SECURITY.md H-2 / H-2b).
 *
 * ── THE PROBLEM ────────────────────────────────────────────────────────────────
 *
 * WordPress runs `wp_filter_post_kses` over `post_content` on save for any user without
 * `unfiltered_html`. A REST import has NO logged-in user, so that filter never fires and
 * whatever arrives is stored verbatim. The consequence is a privilege problem more than an
 * XSS one: anything that can sign a request can store `<script>` on Production even though
 * **no user on Production is allowed to do that**.
 *
 * ── WHY NOT JUST wp_kses_post() ────────────────────────────────────────────────
 *
 * Because it was measured, and it destroys real content. Against core's own post allowlist:
 *
 *   preserved  block comments · block attribute JSON · nested blocks · data-* · inline
 *              style · shortcodes
 *   DESTROYED  img srcset and sizes (every responsive image, on every deploy) ·
 *              iframe (every embed) · form · inline svg
 *
 * So the allowlist here STARTS from `wp_kses_allowed_html( 'post' )` and adds back the
 * things that are legitimate content in a deployment but missing from core's editor-facing
 * list. Everything kses does for free still applies: any attribute not named is dropped —
 * which removes every `on*` handler without listing one — `wp_kses_bad_protocol()` runs on
 * the URI attributes (`href`, `src`, `action`, `formaction`, …), and `safecss_filter_attr()`
 * sanitises `style`.
 *
 * ── WHY THERE ARE THREE MODES ──────────────────────────────────────────────────
 *
 * Turning a filter on over live content is how a plugin silently eats someone's page. So an
 * existing install starts in `report`: it changes nothing and only records what `filter`
 * WOULD have removed. You look, then you decide.
 */
final class ContentFirewall {

	public const OPTION = 'ifs_deploy_content_firewall';

	/** Log what would be stripped, change nothing. Default for existing installs. */
	public const MODE_REPORT = 'report';

	/** Sanitise, and log every change. Default for new installs. */
	public const MODE_FILTER = 'filter';

	/** Trust the peer completely — the behaviour before this existed. */
	public const MODE_OFF = 'off';

	/**
	 * @return array<string,string> mode => label
	 */
	public static function modes(): array {
		return array(
			self::MODE_REPORT => __( 'Report only — log what would be removed, change nothing', 'ifs-deploy' ),
			self::MODE_FILTER => __( 'Filter — remove scripts and event handlers from imported content', 'ifs-deploy' ),
			self::MODE_OFF    => __( 'Off — store imported content exactly as sent', 'ifs-deploy' ),
		);
	}

	/**
	 * The active mode.
	 *
	 * Defaults to `report` when unset, which is the state every EXISTING install upgrades
	 * into: nothing about the next deploy changes, and the Logs screen starts telling you
	 * what `filter` would do. `Activator` writes `filter` explicitly on a fresh install, so
	 * a new site gets the secure default without anyone having to choose it.
	 */
	/**
	 * Two or three words for the same mode, for a status card or a badge.
	 *
	 * Separate from `modes()` because those labels explain the CHOICE and belong on the
	 * settings control; this one reports the STATE and has to fit in a card. Single-sourced
	 * here so the two can never drift into saying different things about the same value.
	 *
	 * @param string|null $mode Defaults to the stored mode.
	 */
	public static function short_label( ?string $mode = null ): string {
		$mode = null === $mode ? self::mode() : $mode;

		$labels = array(
			self::MODE_REPORT => __( 'Report only', 'ifs-deploy' ),
			self::MODE_FILTER => __( 'Filtered', 'ifs-deploy' ),
			self::MODE_OFF    => __( 'Not filtered', 'ifs-deploy' ),
		);

		return $labels[ $mode ] ?? $labels[ self::MODE_REPORT ];
	}

	public static function mode(): string {
		$stored = (string) get_option( self::OPTION, self::MODE_REPORT );

		return array_key_exists( $stored, self::modes() ) ? $stored : self::MODE_REPORT;
	}

	public static function set_mode( string $mode ): string {
		$mode = array_key_exists( $mode, self::modes() ) ? $mode : self::MODE_REPORT;

		update_option( self::OPTION, $mode );

		return $mode;
	}

	/**
	 * Run a block of imported HTML through the firewall.
	 *
	 * @param string $html    The incoming markup.
	 * @param string $context What it is, for the log — e.g. `post_content`.
	 * @return array{html:string,changed:bool,removed:string[]}
	 *   `html` is what to store: the ORIGINAL in report/off mode, the filtered value in
	 *   filter mode. `removed` names the kinds of thing that were taken out.
	 */
	public static function apply( string $html, string $context = '' ): array {
		$unchanged = array( 'html' => $html, 'changed' => false, 'removed' => array() );

		if ( '' === trim( $html ) || self::MODE_OFF === self::mode() ) {
			return $unchanged;
		}

		/*
		 * NOTHING THAT LOOKS LIKE A TAG → do not filter it at all.
		 *
		 * Found by testing: `Just words, an & ampersand, and 3 < 4.` came back as
		 * `Just words, an &amp; ampersand, and 3 ` — kses read the bare `<` as the start of
		 * a tag and ate the rest of the sentence. Excerpts, widget titles and plain-text
		 * fields are full of prose like that, and silently truncating them is precisely the
		 * damage this class exists to prevent.
		 *
		 * Skipping is safe, not a hole: a `<` that is not followed by a letter, `!`, `/` or
		 * `?` does not open a tag for a browser either, so there is nothing here to execute.
		 */
		if ( ! preg_match( '#<[a-z!/?]#i', $html ) ) {
			return $unchanged;
		}

		$clean = self::filter( $html );

		if ( $clean === $html ) {
			return $unchanged;
		}

		/*
		 * Entity normalisation alone is NOT a removal.
		 *
		 * kses rewrites a bare `&` to `&amp;`, which is a change to the string but takes
		 * nothing out. Reporting it as "disallowed markup" would fill the log with noise on
		 * ordinary content and teach the reader to ignore the entries that matter.
		 */
		if ( html_entity_decode( $clean, ENT_QUOTES, 'UTF-8' ) === html_entity_decode( $html, ENT_QUOTES, 'UTF-8' ) ) {
			return array( 'html' => $clean, 'changed' => false, 'removed' => array() );
		}

		$removed = self::describe( $html, $clean );

		/*
		 * RE-SERIALISATION IS NOT A REMOVAL.
		 *
		 * kses does not hand back the string it was given even when it keeps everything:
		 * it rebuilds every tag, so attribute order, quoting and spacing can all change
		 * while the markup is identical in meaning. The entity check above catches one
		 * form of that; this catches the rest.
		 *
		 * Reported as a change, it produced a WARNING on every single deploy of the same
		 * page, reading "Removed disallowed markup" while removing nothing — which is
		 * worse than silence twice over. It is alarming and untrue, and a warning that
		 * cries wolf on every push is one nobody reads when it finally means something.
		 *
		 * `describe()` now returns an empty list only when nothing was taken out at all:
		 * no tag, no attribute, no text. Anything genuinely dropped is named.
		 */
		if ( empty( $removed ) ) {
			return array(
				'html'    => self::MODE_FILTER === self::mode() ? $clean : $html,
				'changed' => false,
				'removed' => array(),
			);
		}

		// report mode returns the ORIGINAL. The caller stores it untouched; only the log
		// records what filter mode would have done.
		return array(
			'html'    => self::MODE_FILTER === self::mode() ? $clean : $html,
			'changed' => true,
			'removed' => $removed,
		);
	}

	/**
	 * kses with the extended allowlist, then the iframe host check. No mode logic.
	 */
	public static function filter( string $html ): string {
		return self::restrict_iframes( wp_kses( $html, self::allowed() ) );
	}

	/**
	 * Remove iframes pointing anywhere but an allowed host.
	 *
	 * kses checks the PROTOCOL of `src` and nothing else, so without this any `https://`
	 * iframe survives — and an arbitrary iframe is still an injection surface, just a
	 * tidier-looking one. Host-restricting it is what turns it into an embed.
	 *
	 * Runs AFTER kses, which is why a regex is safe enough here: kses has already
	 * normalised every attribute to `name="value"` with double quotes and stripped anything
	 * it did not recognise, so there is no exotic quoting left to trip over.
	 */
	private static function restrict_iframes( string $html ): string {
		if ( false === stripos( $html, '<iframe' ) ) {
			return $html;
		}

		$hosts = self::allowed_iframe_hosts();

		return (string) preg_replace_callback(
			'#<iframe\b[^>]*>.*?</iframe>|<iframe\b[^>]*/?>#is',
			static function ( array $m ) use ( $hosts ): string {
				if ( ! preg_match( '#\ssrc="([^"]*)"#i', $m[0], $src ) ) {
					// No src at all: nothing to embed, so nothing worth keeping.
					return '';
				}

				$host = strtolower( (string) wp_parse_url( html_entity_decode( $src[1] ), PHP_URL_HOST ) );

				if ( '' === $host ) {
					return '';
				}

				foreach ( $hosts as $allowed ) {
					$allowed = strtolower( trim( (string) $allowed ) );

					// Suffix match on a DOT boundary, so `player.vimeo.com` matches
					// `vimeo.com` while `evil-vimeo.com` does not.
					if ( $host === $allowed || substr( $host, -strlen( '.' . $allowed ) ) === '.' . $allowed ) {
						return $m[0];
					}
				}

				return '';
			},
			$html
		);
	}

	/**
	 * The allowlist: core's post tags, plus what a deployment legitimately carries.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function allowed(): array {
		$allowed = wp_kses_allowed_html( 'post' );

		// Responsive images. Core's post allowlist omits these, so plain wp_kses_post()
		// silently degrades every image in every deploy.
		if ( isset( $allowed['img'] ) ) {
			$allowed['img'] += array(
				'srcset'   => true,
				'sizes'    => true,
				'decoding' => true,
			);
		}

		/*
		 * `<source>`, which core allows `<video>` and `<audio>` but NOT their sources.
		 *
		 * Found by testing rather than reading: a self-hosted video written as
		 * `<video><source src="…"></video>` came out as a `<video>` with nothing to play.
		 * Exactly the silent content loss this class exists to avoid.
		 */
		$allowed['source'] = array(
			'src'    => true,
			'type'   => true,
			'srcset' => true,
			'sizes'  => true,
			'media'  => true,
			'class'  => true,
			'id'     => true,
			'data-*' => true,
		);

		/*
		 * `<object>` is REMOVED, even though core permits it with `data` and `type`.
		 *
		 * It is the legacy plugin-embed tag, it has no role in deployed content now that
		 * iframes are handled properly and host-restricted, and core's own allowlist lets it
		 * name an arbitrary `data` URL. In testing it happened to come out inert — but
		 * relying on a quirk of how kses resolves `data` against the `data-*` wildcard is
		 * not a security position. Being explicit is.
		 */
		unset( $allowed['object'], $allowed['embed'], $allowed['applet'], $allowed['param'] );

		// Embeds. `src` is protocol-checked by kses and host-checked by us (see
		// `restrict_iframes()`); no event handler is listed, so none survives.
		$allowed['iframe'] = array(
			'src'             => true,
			'width'           => true,
			'height'          => true,
			'title'           => true,
			'class'           => true,
			'id'              => true,
			'style'           => true,
			'loading'         => true,
			'allow'           => true,
			'allowfullscreen' => true,
			'frameborder'     => true,
			'scrolling'       => true,
			'referrerpolicy'  => true,
			'sandbox'         => true,
			'data-*'          => true,
		);

		// Hand-coded forms in content. `action` and `formaction` are both in
		// `wp_kses_uri_attributes()`, so `javascript:` in either is neutralised by kses.
		$common = array( 'class' => true, 'id' => true, 'style' => true, 'data-*' => true );

		$allowed['form']     = $common + array( 'action' => true, 'method' => true, 'enctype' => true, 'target' => true, 'name' => true, 'novalidate' => true, 'accept-charset' => true );
		$allowed['input']    = $common + array( 'type' => true, 'name' => true, 'value' => true, 'placeholder' => true, 'required' => true, 'disabled' => true, 'readonly' => true, 'checked' => true, 'min' => true, 'max' => true, 'step' => true, 'pattern' => true, 'maxlength' => true, 'minlength' => true, 'autocomplete' => true, 'multiple' => true, 'accept' => true, 'size' => true );
		$allowed['textarea'] = $common + array( 'name' => true, 'rows' => true, 'cols' => true, 'placeholder' => true, 'required' => true, 'disabled' => true, 'readonly' => true, 'maxlength' => true );
		$allowed['select']   = $common + array( 'name' => true, 'required' => true, 'disabled' => true, 'multiple' => true, 'size' => true );
		$allowed['option']   = $common + array( 'value' => true, 'selected' => true, 'disabled' => true, 'label' => true );
		$allowed['optgroup'] = $common + array( 'label' => true, 'disabled' => true );
		$allowed['label']    = $common + array( 'for' => true );
		$allowed['fieldset'] = $common + array( 'disabled' => true, 'name' => true );
		$allowed['legend']   = $common;
		$allowed['button']   = $common + array( 'type' => true, 'name' => true, 'value' => true, 'disabled' => true, 'formaction' => true );
		$allowed['datalist'] = $common;
		$allowed['output']   = $common + array( 'for' => true, 'name' => true );
		$allowed['progress'] = $common + array( 'value' => true, 'max' => true );
		$allowed['meter']    = $common + array( 'value' => true, 'min' => true, 'max' => true, 'low' => true, 'high' => true, 'optimum' => true );

		/*
		 * Inline SVG — drawing primitives only.
		 *
		 * Three things are deliberately NOT here, and they are the whole reason inline SVG
		 * is normally refused:
		 *
		 *  - `<use>` / `href` / `xlink:href`. `xlink:href` is NOT in
		 *    `wp_kses_uri_attributes()`, so kses would not protocol-check it and
		 *    `xlink:href="data:text/html,<script>…"` would survive.
		 *  - `<script>` and `<foreignObject>`, which can host arbitrary markup.
		 *  - `<animate>` / `<set>`, which can rewrite another element's attributes at
		 *    runtime and so reintroduce anything removed above.
		 */
		$svg_common = array( 'class' => true, 'id' => true, 'style' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'stroke-dasharray' => true, 'opacity' => true, 'fill-opacity' => true, 'stroke-opacity' => true, 'transform' => true, 'clip-rule' => true, 'fill-rule' => true );

		$allowed['svg']            = $svg_common + array( 'viewbox' => true, 'width' => true, 'height' => true, 'xmlns' => true, 'preserveaspectratio' => true, 'role' => true, 'aria-hidden' => true, 'aria-label' => true, 'focusable' => true );
		$allowed['g']              = $svg_common;
		$allowed['path']           = $svg_common + array( 'd' => true );
		$allowed['circle']         = $svg_common + array( 'cx' => true, 'cy' => true, 'r' => true );
		$allowed['ellipse']        = $svg_common + array( 'cx' => true, 'cy' => true, 'rx' => true, 'ry' => true );
		$allowed['rect']           = $svg_common + array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true );
		$allowed['line']           = $svg_common + array( 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true );
		$allowed['polygon']        = $svg_common + array( 'points' => true );
		$allowed['polyline']       = $svg_common + array( 'points' => true );
		$allowed['defs']           = $svg_common;
		$allowed['clippath']       = $svg_common + array( 'clippathunits' => true );
		$allowed['lineargradient'] = $svg_common + array( 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'gradientunits' => true );
		$allowed['radialgradient'] = $svg_common + array( 'cx' => true, 'cy' => true, 'r' => true, 'gradientunits' => true );
		$allowed['stop']           = $svg_common + array( 'offset' => true, 'stop-color' => true, 'stop-opacity' => true );
		$allowed['text']           = $svg_common + array( 'x' => true, 'y' => true, 'dx' => true, 'dy' => true, 'text-anchor' => true, 'font-size' => true, 'font-family' => true, 'font-weight' => true );
		$allowed['tspan']          = $allowed['text'];

		/**
		 * Filter the tags and attributes imported content may contain.
		 *
		 * Adding `script`, `use` or `xlink:href` here reopens exactly what this exists to
		 * close. If a site needs raw markup, the `off` mode is the honest way to say so.
		 *
		 * @param array $allowed
		 */
		return (array) apply_filters( 'ifs_deploy_allowed_html', $allowed );
	}

	/**
	 * Which HOSTS an iframe may point at.
	 *
	 * kses only checks the protocol, so without this any `https://` iframe survives — and
	 * an arbitrary iframe is still an injection surface, just a tidier one. Restricting the
	 * host turns it into an embed.
	 *
	 * @return string[] Hosts, matched as a suffix so subdomains are covered.
	 */
	public static function allowed_iframe_hosts(): array {
		$hosts = array(
			'youtube.com',
			'youtube-nocookie.com',
			'youtu.be',
			'vimeo.com',
			'player.vimeo.com',
			'google.com',
			'maps.google.com',
			'openstreetmap.org',
			'dailymotion.com',
			'soundcloud.com',
			'spotify.com',
			'wistia.com',
			'loom.com',
			'figma.com',
		);

		// The site's OWN host is always allowed: a self-hosted embed is not a third party.
		$own = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		if ( '' !== $own ) {
			$hosts[] = $own;
		}

		/**
		 * Filter the hosts an imported iframe may point at.
		 *
		 * @param string[] $hosts
		 */
		return array_values( array_unique( (array) apply_filters( 'ifs_deploy_allowed_iframe_hosts', $hosts ) ) );
	}

	/**
	 * Name the kinds of thing that were removed, for the log.
	 *
	 * Deliberately a short list of categories rather than a diff. The point is to make the
	 * finding legible — "a script tag was removed from this page" — not to reproduce the
	 * markup, which would put the very payload being rejected into the log.
	 *
	 * @return string[]
	 */
	private static function describe( string $before, string $after ): array {
		$checks = array(
			'script tag'            => '#<\s*script\b#i',
			'style tag'             => '#<\s*style\b#i',
			'inline event handler'  => '#\son[a-z]+\s*=#i',
			'javascript: URL'       => '#javascript\s*:#i',
			'data: URL'             => '#data\s*:\s*text/html#i',
			'iframe'                => '#<\s*iframe\b#i',
			'form'                  => '#<\s*form\b#i',
			'object or embed'       => '#<\s*(object|embed|applet)\b#i',
			'svg use reference'     => '#<\s*use\b|xlink:href#i',
			'foreignObject'         => '#<\s*foreignobject\b#i',
		);

		$removed = array();

		foreach ( $checks as $label => $pattern ) {
			$was = preg_match_all( $pattern, $before );
			$is  = preg_match_all( $pattern, $after );

			if ( $was > $is ) {
				$removed[] = $label . ( $was - $is > 1 ? ' (×' . ( $was - $is ) . ')' : '' );
			}
		}

		if ( ! empty( $removed ) ) {
			return $removed;
		}

		/*
		 * None of the security patterns matched, so name what ACTUALLY went.
		 *
		 * This used to give up here and report the catch-all "disallowed markup", which
		 * was both unactionable and — far more often — untrue: kses rebuilds every tag it
		 * keeps, so an identical page comes back as a different string. The result was a
		 * WARNING on every deploy of the same page, naming nothing.
		 *
		 * Counting tags and attributes on both sides answers it properly. A stripped
		 * `srcset` now reads as `srcset`, and a page that merely got re-serialised
		 * produces an empty list, which apply() treats as no change at all.
		 */
		foreach ( self::tag_counts( $before ) as $tag => $was ) {
			$is = self::tag_counts( $after )[ $tag ] ?? 0;

			if ( $was > $is ) {
				$removed[] = '<' . $tag . '>' . self::times( $was - $is );
			}
		}

		$after_attributes = self::attribute_counts( $after );

		foreach ( self::attribute_counts( $before ) as $attribute => $was ) {
			$is = $after_attributes[ $attribute ] ?? 0;

			if ( $was > $is ) {
				$removed[] = $attribute . self::times( $was - $is );
			}
		}

		if ( ! empty( $removed ) ) {
			return $removed;
		}

		/*
		 * Last resort: the visible words.
		 *
		 * A tag can be dropped along with everything inside it while the tag counts still
		 * balance — `<form>` removed with its `<input>`s, say. Comparing the text with all
		 * markup and whitespace normalised away catches that without being fooled by
		 * reformatting.
		 */
		if ( self::text_of( $before ) !== self::text_of( $after ) ) {
			return array( 'text content' );
		}

		return array();
	}

	/** " (×3)", or "" for a single occurrence. */
	private static function times( int $count ): string {
		return $count > 1 ? ' (×' . $count . ')' : '';
	}

	/**
	 * How many times each tag NAME appears.
	 *
	 * @return array<string,int>
	 */
	private static function tag_counts( string $html ): array {
		$counts = array();

		if ( preg_match_all( '#<\s*([a-z][a-z0-9]*)\b#i', $html, $matches ) ) {
			foreach ( $matches[1] as $tag ) {
				$tag            = strtolower( $tag );
				$counts[ $tag ] = ( $counts[ $tag ] ?? 0 ) + 1;
			}
		}

		return $counts;
	}

	/**
	 * How many times each attribute NAME appears, counted only INSIDE tags.
	 *
	 * Scoped to tags on purpose: prose contains `x = 1` often enough, and counting that
	 * as an attribute would report phantom removals whenever a sentence changed.
	 *
	 * @return array<string,int>
	 */
	private static function attribute_counts( string $html ): array {
		$counts = array();

		if ( ! preg_match_all( '#<[a-z][^>]*>#i', $html, $tags ) ) {
			return $counts;
		}

		foreach ( $tags[0] as $tag ) {
			if ( ! preg_match_all( '#\s([a-z_:][-a-z0-9_:.]*)\s*=#i', $tag, $attributes ) ) {
				continue;
			}

			foreach ( $attributes[1] as $attribute ) {
				$attribute            = strtolower( $attribute );
				$counts[ $attribute ] = ( $counts[ $attribute ] ?? 0 ) + 1;
			}
		}

		return $counts;
	}

	/**
	 * The visible words, with markup, entities and whitespace normalised away.
	 */
	private static function text_of( string $html ): string {
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );

		return trim( (string) preg_replace( '#\s+#u', ' ', $text ) );
	}
}
