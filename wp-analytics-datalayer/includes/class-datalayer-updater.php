<?php
defined( 'ABSPATH' ) || exit;

/**
 * GitHub-based automatic updater — raw file strategy.
 *
 * Version detection : reads the Version header directly from the plugin's main
 * PHP file on raw.githubusercontent.com  → no GitHub API, no rate limit.
 *
 * Download URL      : wp-analytics-datalayer.zip committed on the main branch,
 * also served via raw.githubusercontent.com.
 *
 * To trigger an update: bump WADL_VERSION, commit, push to main, and make sure
 * the ZIP in the repo root is rebuilt (GitHub Actions handles this automatically).
 */
class WADL_Updater {

	private const GITHUB_USER   = 'webAnalyste';
	private const GITHUB_REPO   = 'WP-Analytics-GTM-datalayer';
	private const GITHUB_BRANCH = 'main';
	public const  CACHE_KEY     = 'wadl_github_release';
	private const CACHE_TTL     = 6 * HOUR_IN_SECONDS;
	private const CACHE_TTL_ERR = 5 * MINUTE_IN_SECONDS;

	private string $plugin_basename;
	private string $plugin_slug = 'wp-analytics-datalayer';

	public function __construct() {
		$this->plugin_basename = plugin_basename( WADL_PLUGIN_FILE );

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
		add_filter( 'site_transient_update_plugins',         [ $this, 'check_for_update' ] );
		add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 20, 3 );
		add_filter( 'upgrader_source_selection',             [ $this, 'fix_source_dir' ], 10, 4 );

		add_action( 'delete_site_transient_update_plugins', [ $this, 'clear_cache' ] );
		add_action( 'admin_post_wadl_force_update_check',   [ $this, 'force_check_action' ] );
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

		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		wp_safe_redirect( add_query_arg( [ 'page' => 'wadl-dashboard', 'update_checked' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ------------------------------------------------------------------
	// Remote version fetch — reads plugin header from raw GitHub file
	// ------------------------------------------------------------------

	/**
	 * Fetch (or return cached) version info directly from the plugin's main PHP
	 * file on raw.githubusercontent.com.  No GitHub API, no rate limit.
	 *
	 * Returns a plain object with:
	 *   ->tag_name    string  e.g. "1.3.10"
	 *   ->download_url string  raw ZIP URL on main branch
	 */
	private function get_release(): ?object {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			// Return null for both '' (legacy) and error objects (no tag_name).
			return ( is_object( $cached ) && ! empty( $cached->tag_name ) ) ? $cached : null;
		}

		// Read the plugin's main PHP file to extract the Version header.
		$php_url = sprintf(
			'https://raw.githubusercontent.com/%s/%s/%s/wp-analytics-datalayer/wp-analytics-datalayer.php',
			self::GITHUB_USER,
			self::GITHUB_REPO,
			self::GITHUB_BRANCH
		);

		$args = [
			'timeout'    => 10,
			'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
		];

		$response = wp_remote_get( $php_url, $args );

		// SSL fallback — retry without certificate verification on some hosts.
		if ( is_wp_error( $response ) ) {
			$response = wp_remote_get( $php_url, array_merge( $args, [ 'sslverify' => false ] ) );
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( is_wp_error( $response ) ) {
			$error = (object) [ 'error' => $response->get_error_message() ];
			set_transient( self::CACHE_KEY, $error, self::CACHE_TTL_ERR );
			return null;
		}

		if ( 200 !== (int) $code ) {
			$error = (object) [ 'error' => 'HTTP ' . $code ];
			set_transient( self::CACHE_KEY, $error, self::CACHE_TTL_ERR );
			return null;
		}

		$body = wp_remote_retrieve_body( $response );

		// Parse " * Version: x.y.z" from the plugin header.
		if ( ! preg_match( '/^\s*\*\s*Version:\s*(.+)$/m', $body, $matches ) ) {
			set_transient( self::CACHE_KEY, '', self::CACHE_TTL_ERR );
			return null;
		}

		$version = trim( $matches[1] );

		$release = (object) [
			'tag_name'     => $version,
			'download_url' => sprintf(
				'https://raw.githubusercontent.com/%s/%s/%s/wp-analytics-datalayer-%s.zip',
				self::GITHUB_USER,
				self::GITHUB_REPO,
				self::GITHUB_BRANCH,
				$version
			),
			'name'        => 'v' . $version,
			'body'        => '',
			'published_at' => '',
		];

		set_transient( self::CACHE_KEY, $release, self::CACHE_TTL );
		return $release;
	}

	private function get_download_url( object $release ): string {
		return $release->download_url ?? '';
	}

	private function parse_version( string $tag ): string {
		return ltrim( $tag, 'v' );
	}

	// ------------------------------------------------------------------
	// WordPress update hooks
	// ------------------------------------------------------------------

	public function check_for_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
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
			'name'           => 'WP Analytics GTM DataLayer',
			'slug'           => $this->plugin_slug,
			'version'        => $this->parse_version( (string) $release->tag_name ),
			'author'         => '<a href="https://www.webanalyste.com" target="_blank">webAnalyste</a>',
			'author_profile' => 'https://www.webanalyste.com',
			'homepage'       => sprintf( 'https://github.com/%s/%s', self::GITHUB_USER, self::GITHUB_REPO ),
			'requires'       => '6.0',
			'requires_php'   => '8.0',
			'tested'         => '6.7',
			'last_updated'   => '',
			'sections'       => [
				'description'  => $this->get_description_html(),
				'installation' => $this->get_installation_html(),
				'changelog'    => '<p><a href="' . esc_url( sprintf( 'https://github.com/%s/%s/releases', self::GITHUB_USER, self::GITHUB_REPO ) ) . '" target="_blank">Voir le changelog sur GitHub</a></p>',
			],
			'download_link'  => $this->get_download_url( $release ),
		];
	}

	/**
	 * Rename the extracted folder to the correct plugin slug if needed.
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
}
