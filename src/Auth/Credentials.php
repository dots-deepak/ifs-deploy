<?php
declare(strict_types=1);

namespace IfsDeploy\Auth;

/**
 * This site's own identity + secret. When the site acts as Production, these
 * are the credentials a Staging site must present.
 */
final class Credentials {

	private const OPTION = 'ifs_deploy_credentials';

	/**
	 * Generate credentials once, if absent.
	 */
	public static function ensure_exists(): void {
		$stored = get_option( self::OPTION );
		if ( is_array( $stored ) && ! empty( $stored['api_key'] ) && ! empty( $stored['secret_key'] ) ) {
			return;
		}

		self::regenerate();
	}

	/**
	 * Force-create a new set of credentials. Invalidates any previously shared
	 * keys.
	 *
	 * @return array{site_id:string,api_key:string,secret_key:string}
	 */
	public static function regenerate(): array {
		$creds = array(
			'site_id'    => wp_generate_uuid4(),
			'api_key'    => 'dpk_' . bin2hex( random_bytes( 16 ) ),
			'secret_key' => 'dps_' . bin2hex( random_bytes( 32 ) ),
		);

		update_option( self::OPTION, $creds, false );

		return $creds;
	}

	/**
	 * @return array{site_id:string,api_key:string,secret_key:string}
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) ) {
			$stored = self::regenerate();
		}

		return wp_parse_args(
			$stored,
			array(
				'site_id'    => '',
				'api_key'    => '',
				'secret_key' => '',
			)
		);
	}

	/**
	 * Resolve the secret for a presented API key. Returns null when the key does
	 * not match this site's credentials.
	 */
	public static function secret_for_api_key( string $api_key ): ?string {
		$creds = self::get();

		if ( '' === $api_key || '' === $creds['api_key'] ) {
			return null;
		}

		return hash_equals( $creds['api_key'], $api_key ) ? $creds['secret_key'] : null;
	}
}
