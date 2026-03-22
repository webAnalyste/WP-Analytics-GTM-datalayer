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

	private const GITHUB_USER    = 'webAnalyste';
	private const GITHUB_REPO    = 'WP-Analytics-GTM-datalayer';
	private const CACHE_KEY      = 'wadl_github_release';
	private const CACHE_TTL      = 6 * HOUR_IN_SECONDS;
	private const CACHE_TTL_ERR  = 30 * MINUTE_IN_SECONDS; // short TTL on API error

	private string $plugin_basename;
	private string $plugin_slug = 'wp-analytics-datalayer';

	public function __construct() {
		$this->plugin_basename = plugin_basename( WADL_PLUGIN_FILE );

		// Inject update when WP writes the transient (standard check cycle).
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
		// Inject update when WP reads the transient (covers cached results).
		add_filter( 'site_transient_update_plugins',         [ $this, 'check_for_update' ] );
		add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 20, 3 );
		add_filter( 'upgrader_source_selection',             [ $this, 'fix_source_dir' ], 10, 4 );

		// Clear our cache whenever WordPress forces an update check.
		add_action( 'delete_site_transient_update_plugins', [ $this, 'clear_cache' ] );

		// Admin action: force-check from the dashboard.
		add_action( 'admin_post_wadl_force_update_check', [ $this, 'force_check_action' ] );
	}

	// ------------------------------------------------------------------
	// Cache management
	// ------------------------------------------------------------------

	public function clear_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	public function force_check_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission refusée.', 'wp-analytics-datalayer' ) );
		}
		check_admin_referer( 'wadl_force_update_check', 'wadl_nonce' );

		$this->clear_cache();
		delete_site_transient( 'update_plugins' );

		// Force WordPress to run the update check synchronously right now.
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		wp_safe_redirect( add_query_arg( [ 'page' => 'wadl-dashboard', 'update_checked' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ------------------------------------------------------------------
	// GitHub API
	// ------------------------------------------------------------------

	/**
	 * Fetch (or return cached) latest release data from GitHub.
	 * Uses a short TTL on errors to allow faster recovery.
	 */
	private function get_release(): ?object {
		$cached = get_transient( self::CACHE_KEY );

		if ( false !== $cached ) {
			// '' is stored on error — treat as no release
			return is_object( $cached ) ? $cached : null;
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

		$code = wp_remote_retrieve_response_code( $response );

		if ( is_wp_error( $response ) || 200 !== (int) $code ) {
			// Cache the failure for a short time only — allows retry within the hour.
			set_transient( self::CACHE_KEY, '', self::CACHE_TTL_ERR );
			return null;
		}

		$body    = json_decode( wp_remote_retrieve_body( $response ) );
		$release = is_object( $body ) && ! empty( $body->tag_name ) ? $body : null;

		set_transient( self::CACHE_KEY, $release ?? '', self::CACHE_TTL );
		return $release;
	}

	/**
	 * Return the plugin zip download URL.
	 * Prefers the attached release asset; falls back to GitHub's zipball.
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

	private function parse_version( string $tag ): string {
		return ltrim( $tag, 'v' );
	}

	// ------------------------------------------------------------------
	// WordPress update hooks
	// ------------------------------------------------------------------

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
				'id'             => $this->plugin_basename,
				'slug'           => $this->plugin_slug,
				'plugin'         => $this->plugin_basename,
				'new_version'    => $remote_version,
				'url'            => sprintf( 'https://github.com/%s/%s', self::GITHUB_USER, self::GITHUB_REPO ),
				'package'        => $this->get_download_url( $release ),
				'tested'         => '6.7',
				'requires_php'   => '8.0',
				'icons'          => [],
				'banners'        => [],
				'upgrade_notice' => sanitize_text_field( $release->name ?? '' ),
			];
			// Remove from no_update if present from a previous check.
			unset( $transient->no_update[ $this->plugin_basename ] );
		} else {
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

	public function plugin_info( $result, string $action, object $args ) {
		if ( 'plugin_information' !== $action || ( $args->slug ?? '' ) !== $this->plugin_slug ) {
			return $result;
		}

		$release = $this->get_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) [
			'name'          => 'WP Analytics GTM DataLayer',
			'slug'          => $this->plugin_slug,
			'version'       => $this->parse_version( (string) $release->tag_name ),
			'author'        => '<a href="https://www.webanalyste.com" target="_blank">webAnalyste</a>',
			'author_profile'=> 'https://www.webanalyste.com',
			'homepage'      => sprintf( 'https://github.com/%s/%s', self::GITHUB_USER, self::GITHUB_REPO ),
			'requires'      => '6.0',
			'requires_php'  => '8.0',
			'tested'        => '6.7',
			'last_updated'  => sanitize_text_field( (string) ( $release->published_at ?? '' ) ),
			'sections'      => [
				'description' => $this->get_description_html(),
				'installation'=> $this->get_installation_html(),
				'changelog'   => $this->format_changelog( (string) ( $release->body ?? '' ) ),
			],
			'download_link' => $this->get_download_url( $release ),
		];
	}

	/**
	 * Rename the extracted folder to the correct plugin slug if needed.
	 * GitHub zipballs extract as "{repo}-{sha}/" — our Actions zip is already correct.
	 */
	public function fix_source_dir( string $source, string $remote_source, object $upgrader, array $hook_extra ) {
		if ( ( $hook_extra['plugin'] ?? '' ) !== $this->plugin_basename ) {
			return $source;
		}

		$correct = trailingslashit( $remote_source ) . $this->plugin_slug . '/';

		if ( trailingslashit( $source ) === $correct ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem->move( $source, $correct ) ) {
			return new \WP_Error(
				'wadl_rename_failed',
				__( 'Could not rename plugin directory during update.', 'wp-analytics-datalayer' )
			);
		}

		return $correct;
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	private function get_description_html(): string {
		return '
<p><strong>WP Analytics GTM DataLayer</strong> est un plugin de gouvernance du dataLayer analytics pour GTM / GA4.</p>
<p>Il injecte un <code>dataLayer.push()</code> propre et configurable sur chaque page WordPress, sans remplacer GTM.</p>

<h4>Ce que le plugin fait</h4>
<ul>
	<li>Injecte les métadonnées de page utiles à l\'analytics (<code>page_type</code>, <code>page_template</code>, <code>categories</code>…)</li>
	<li>Expose des données utilisateur minimales et anonymisées</li>
	<li>Pousse les événements GA4 e-commerce WooCommerce (<code>view_item</code>, <code>add_to_cart</code>, <code>purchase</code>…)</li>
	<li>Interface admin simple : toggles par groupe, aperçu JSON en temps réel</li>
	<li>Export / Import / Reset des réglages</li>
	<li>Mise à jour automatique depuis GitHub</li>
</ul>

<h4>Ce que le plugin ne fait pas</h4>
<ul>
	<li>Il ne remplace pas GTM ni Google Analytics</li>
	<li>Il n\'expose aucune donnée personnelle brute</li>
	<li>Il ne collecte et ne transmet aucune donnée à des tiers</li>
</ul>

<hr>

<h4>Développé par webAnalyste</h4>
<p>
	<a href="https://www.webanalyste.com" target="_blank"><strong>webAnalyste.com</strong></a> est une agence spécialisée en
	<strong>data, IA et automatisation no-code</strong>. Nous concevons des stacks analytics sur-mesure pour améliorer
	la performance digitale et le SEO de nos clients — de l\'architecture de tracking à l\'implémentation GA4 / GTM.
</p>
<p>
	<a href="https://www.formations-analytics.com" target="_blank"><strong>formations-analytics.com</strong></a> —
	Notre organisme de formation : GA4, GTM, Data Visualisation, IA appliquée au marketing digital.
	Des formations pratiques et opérationnelles sur les mêmes sujets.
</p>';
	}

	private function get_installation_html(): string {
		return '
<ol>
	<li>Téléchargez le zip depuis <a href="https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO . '/releases/latest" target="_blank">GitHub Releases</a>.</li>
	<li>Dans WordPress : <strong>Extensions → Ajouter → Téléverser</strong>, sélectionnez le zip.</li>
	<li>Activez le plugin.</li>
	<li>Allez dans <strong>DataLayer</strong> dans le menu admin.</li>
	<li>Configurez les champs et événements à exposer.</li>
</ol>
<p>Les champs suivants sont <strong>actifs par défaut</strong> dès l\'activation :<br>
<code>page_template</code>, <code>page_type</code>, <code>page_title</code>, <code>page_url</code>,
<code>categories</code>, <code>user_logged_in</code>, <code>view_item</code>,
<code>add_to_cart</code>, <code>begin_checkout</code>, <code>purchase</code>.</p>';
	}

	private function format_changelog( string $markdown ): string {
		if ( ! $markdown ) {
			return '<p>' . esc_html__( 'See GitHub releases for changelog.', 'wp-analytics-datalayer' ) . '</p>';
		}
		$html = preg_replace( '/^#{1,3}\s+(.+)$/m', '<h4>$1</h4>', $markdown );
		$html = preg_replace( '/^\*\s+(.+)$/m', '<li>$1</li>', (string) $html );
		$html = wpautop( (string) $html );
		return wp_kses( (string) $html, [ 'h4' => [], 'p' => [], 'ul' => [], 'li' => [], 'br' => [], 'strong' => [], 'em' => [], 'code' => [] ] );
	}
}
