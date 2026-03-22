<?php
defined( 'ABSPATH' ) || exit;

/**
 * GitHub-based automatic updater.
 *
 * Checks the latest GitHub release for a newer version and plugs into
 * WordPress' native update flow (transient, plugins_api, upgrader).
 *
 * Requires tagged GitHub releases with a `wp-analytics-datalayer.zip` asset
 * attached (produced by the GitHub Actions workflow).
 */
class WADL_Updater {

	private const GITHUB_USER = 'webAnalyste';
	private const GITHUB_REPO = 'WP-Analytics-GTM-datalayer';
	private const CACHE_KEY   = 'wadl_github_release';
	private const CACHE_TTL   = 12 * HOUR_IN_SECONDS;

	private string $plugin_basename;
	private string $plugin_slug = 'wp-analytics-datalayer';

	public function __construct() {
		$this->plugin_basename = plugin_basename( WADL_PLUGIN_FILE );

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
		add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 20, 3 );
		add_filter( 'upgrader_source_selection',             [ $this, 'fix_source_dir' ], 10, 4 );
	}

	// ------------------------------------------------------------------
	// GitHub API
	// ------------------------------------------------------------------

	/**
	 * Fetch (or return cached) latest release data from GitHub.
	 */
	private function get_release(): ?object {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return $cached ?: null; // '' stored on error to avoid hammering the API
		}

		$url      = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			self::GITHUB_USER,
			self::GITHUB_REPO
		);
		$response = wp_remote_get( $url, [
			'timeout'    => 10,
			'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
		] );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::CACHE_KEY, '', self::CACHE_TTL );
			return null;
		}

		$body    = json_decode( wp_remote_retrieve_body( $response ) );
		$release = is_object( $body ) ? $body : null;

		set_transient( self::CACHE_KEY, $release ?: '', self::CACHE_TTL );
		return $release;
	}

	/**
	 * Extract the plugin zip download URL from release assets.
	 * Falls back to GitHub's auto-generated zipball if no asset is attached.
	 */
	private function get_download_url( object $release ): string {
		if ( ! empty( $release->assets ) ) {
			foreach ( $release->assets as $asset ) {
				if ( str_ends_with( (string) $asset->name, '.zip' ) ) {
					return (string) $asset->browser_download_url;
				}
			}
		}
		return (string) ( $release->zipball_url ?? '' );
	}

	/**
	 * Parse "v1.2.3" → "1.2.3".
	 */
	private function parse_version( string $tag ): string {
		return ltrim( $tag, 'v' );
	}

	// ------------------------------------------------------------------
	// WordPress update hooks
	// ------------------------------------------------------------------

	/**
	 * Inject update info into the WordPress update transient.
	 *
	 * @param object $transient
	 * @return object
	 */
	public function check_for_update( object $transient ): object {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_release();
		if ( ! $release || empty( $release->tag_name ) ) {
			return $transient;
		}

		$remote_version = $this->parse_version( (string) $release->tag_name );

		if ( version_compare( $remote_version, WADL_VERSION, '>' ) ) {
			$transient->response[ $this->plugin_basename ] = (object) [
				'id'            => $this->plugin_basename,
				'slug'          => $this->plugin_slug,
				'plugin'        => $this->plugin_basename,
				'new_version'   => $remote_version,
				'url'           => sprintf( 'https://github.com/%s/%s', self::GITHUB_USER, self::GITHUB_REPO ),
				'package'       => $this->get_download_url( $release ),
				'tested'        => '6.7',
				'requires_php'  => '8.0',
				'icons'         => [],
				'banners'       => [],
				'banners_rtl'   => [],
				'upgrade_notice'=> sanitize_text_field( $release->name ?? '' ),
			];
		} else {
			// No update — register as "no update available" to avoid stale entries.
			$transient->no_update[ $this->plugin_basename ] = (object) [
				'id'          => $this->plugin_basename,
				'slug'        => $this->plugin_slug,
				'plugin'      => $this->plugin_basename,
				'new_version' => WADL_VERSION,
				'url'         => sprintf( 'https://github.com/%s/%s', self::GITHUB_USER, self::GITHUB_REPO ),
				'package'     => '',
			];
		}

		return $transient;
	}

	/**
	 * Populate the "View details" popup in the Plugins screen.
	 *
	 * @param false|object|array $result
	 * @param string             $action
	 * @param object             $args
	 * @return false|object
	 */
	public function plugin_info( $result, string $action, object $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( ( $args->slug ?? '' ) !== $this->plugin_slug ) {
			return $result;
		}

		$release = $this->get_release();
		if ( ! $release ) {
			return $result;
		}

		$remote_version = $this->parse_version( (string) ( $release->tag_name ?? '' ) );
		$changelog      = $this->format_changelog( (string) ( $release->body ?? '' ) );

		return (object) [
			'name'          => 'WP Analytics GTM DataLayer',
			'slug'          => $this->plugin_slug,
			'version'       => $remote_version,
			'author'        => '<a href="https://www.webanalyste.com">webAnalyste</a>',
			'homepage'      => sprintf( 'https://github.com/%s/%s', self::GITHUB_USER, self::GITHUB_REPO ),
			'requires'      => '6.0',
			'requires_php'  => '8.0',
			'tested'        => '6.7',
			'downloaded'    => 0,
			'last_updated'  => sanitize_text_field( (string) ( $release->published_at ?? '' ) ),
			'sections'      => [
				'description' => '<p>Lightweight plugin to configure a clean analytics dataLayer for GTM/GA4.</p>'
					. '<p>By <a href="https://www.webanalyste.com">webAnalyste</a> — data, AI &amp; no-code automation agency.</p>',
				'changelog'   => $changelog,
			],
			'download_link' => $this->get_download_url( $release ),
		];
	}

	/**
	 * Rename the extracted GitHub zip folder to the correct plugin slug.
	 *
	 * GitHub zips extract as "{repo}-{tag}/" — WordPress expects "{slug}/".
	 *
	 * @param string      $source        Extracted source path.
	 * @param string      $remote_source Temp path.
	 * @param object      $upgrader      WP_Upgrader instance.
	 * @param array       $hook_extra    Extra data.
	 * @return string|\WP_Error
	 */
	public function fix_source_dir( string $source, string $remote_source, object $upgrader, array $hook_extra ) {
		// Only act on our plugin.
		if ( ( $hook_extra['plugin'] ?? '' ) !== $this->plugin_basename ) {
			return $source;
		}

		$correct = trailingslashit( $remote_source ) . $this->plugin_slug . '/';

		// Source is already correctly named (e.g. from our Actions zip).
		if ( trailingslashit( $source ) === $correct ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem->move( $source, $correct ) ) {
			return new \WP_Error( 'wadl_rename_failed', __( 'Could not rename plugin directory during update.', 'wp-analytics-datalayer' ) );
		}

		return $correct;
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Convert GitHub-flavoured markdown release notes to basic HTML.
	 */
	private function format_changelog( string $markdown ): string {
		if ( ! $markdown ) {
			return '<p>' . esc_html__( 'See GitHub releases for changelog.', 'wp-analytics-datalayer' ) . '</p>';
		}
		// Convert ## headings → <h4>, * bullets → <li>, line breaks → <br>.
		$html = preg_replace( '/^#{1,3}\s+(.+)$/m', '<h4>$1</h4>', $markdown );
		$html = preg_replace( '/^\*\s+(.+)$/m', '<li>$1</li>', (string) $html );
		$html = wpautop( (string) $html );
		return wp_kses( (string) $html, [ 'h4' => [], 'p' => [], 'ul' => [], 'li' => [], 'br' => [], 'strong' => [], 'em' => [], 'code' => [] ] );
	}
}
