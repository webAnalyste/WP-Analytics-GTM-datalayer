<?php
defined( 'ABSPATH' ) || exit;

class WADL_Admin {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_post_wadl_save_settings',   [ $this, 'save_settings' ] );
		add_action( 'admin_post_wadl_export_settings', [ $this, 'export_settings' ] );
		add_action( 'admin_post_wadl_import_settings', [ $this, 'import_settings' ] );
		add_action( 'admin_post_wadl_reset_settings',  [ $this, 'reset_settings' ] );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'DataLayer Analytics', 'wp-analytics-datalayer' ),
			__( 'DataLayer', 'wp-analytics-datalayer' ),
			'manage_options',
			'wadl-dashboard',
			[ $this, 'render_dashboard' ],
			'dashicons-chart-line',
			81
		);
		add_submenu_page( 'wadl-dashboard', __( 'Dashboard', 'wp-analytics-datalayer' ), __( 'Dashboard', 'wp-analytics-datalayer' ), 'manage_options', 'wadl-dashboard', [ $this, 'render_dashboard' ] );
		add_submenu_page( 'wadl-dashboard', __( 'Contenu', 'wp-analytics-datalayer' ), __( 'Contenu', 'wp-analytics-datalayer' ), 'manage_options', 'wadl-content', [ $this, 'render_content' ] );
		add_submenu_page( 'wadl-dashboard', __( 'Utilisateur', 'wp-analytics-datalayer' ), __( 'Utilisateur', 'wp-analytics-datalayer' ), 'manage_options', 'wadl-user', [ $this, 'render_user' ] );
		add_submenu_page( 'wadl-dashboard', __( 'Événements GA4', 'wp-analytics-datalayer' ), __( 'Événements GA4', 'wp-analytics-datalayer' ), 'manage_options', 'wadl-events', [ $this, 'render_events' ] );
		add_submenu_page( 'wadl-dashboard', __( 'Debug JSON', 'wp-analytics-datalayer' ), __( 'Debug JSON', 'wp-analytics-datalayer' ), 'manage_options', 'wadl-debug', [ $this, 'render_debug' ] );
	}

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, 'wadl' ) === false ) return;
		wp_enqueue_style( 'wadl-admin', WADL_PLUGIN_URL . 'admin/assets/admin.css', [], WADL_VERSION );
		wp_enqueue_script( 'wadl-admin', WADL_PLUGIN_URL . 'admin/assets/admin.js', [], WADL_VERSION, true );
	}

	/**
	 * Map each admin page to the key prefix it owns.
	 * A save from page X must ONLY update keys with the matching prefix.
	 * All other keys are left untouched in the DB — this prevents cross-page reset.
	 */
	private const PAGE_PREFIXES = [
		'wadl-content' => 'content_',
		'wadl-user'    => 'user_',
		'wadl-events'  => 'event_',
	];

	public function save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission refusée.', 'wp-analytics-datalayer' ) );
		}
		check_admin_referer( 'wadl_save_settings', 'wadl_nonce' );

		$redirect_page = sanitize_key( $_POST['_redirect_page'] ?? 'wadl-dashboard' );
		$prefix        = self::PAGE_PREFIXES[ $redirect_page ] ?? null;

		// Start from the currently saved settings so unrelated pages are preserved.
		$saved    = get_option( WADL_OPTION_KEY, [] );
		$defaults = WADL_Core::get_defaults();

		foreach ( $defaults as $key => $default ) {
			// Only update keys that belong to the current form.
			if ( $prefix !== null && ! str_starts_with( $key, $prefix ) ) {
				continue;
			}
			$saved[ $key ] = isset( $_POST[ 'wadl_' . $key ] ) ? 1 : 0;
		}

		update_option( WADL_OPTION_KEY, $saved );

		wp_safe_redirect( add_query_arg( [ 'page' => $redirect_page, 'updated' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ---------- Export / Import / Reset ----------

	public function export_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission refusée.', 'wp-analytics-datalayer' ) );
		}
		check_admin_referer( 'wadl_export_settings', 'wadl_nonce' );

		$settings = WADL_Core::get_settings();
		$filename = 'wadl-settings-' . gmdate( 'Y-m-d' ) . '.json';

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_json_encode( $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		exit;
	}

	public function import_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission refusée.', 'wp-analytics-datalayer' ) );
		}
		check_admin_referer( 'wadl_import_settings', 'wadl_nonce' );

		if ( empty( $_FILES['wadl_import_file']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( [ 'page' => 'wadl-dashboard', 'import_error' => '1' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$tmp  = sanitize_text_field( $_FILES['wadl_import_file']['tmp_name'] );
		$json = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! $json ) {
			wp_safe_redirect( add_query_arg( [ 'page' => 'wadl-dashboard', 'import_error' => '2' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		$imported = json_decode( $json, true );
		if ( ! is_array( $imported ) ) {
			wp_safe_redirect( add_query_arg( [ 'page' => 'wadl-dashboard', 'import_error' => '3' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		// Only keep known keys; cast all values to int (0 or 1).
		$defaults = WADL_Core::get_defaults();
		$clean    = [];
		foreach ( $defaults as $key => $default ) {
			$clean[ $key ] = isset( $imported[ $key ] ) ? (int) (bool) $imported[ $key ] : $default;
		}

		update_option( WADL_OPTION_KEY, $clean );
		wp_safe_redirect( add_query_arg( [ 'page' => 'wadl-dashboard', 'updated' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function reset_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission refusée.', 'wp-analytics-datalayer' ) );
		}
		check_admin_referer( 'wadl_reset_settings', 'wadl_nonce' );

		delete_option( WADL_OPTION_KEY );
		wp_safe_redirect( add_query_arg( [ 'page' => 'wadl-dashboard', 'reset' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ---------- Render helpers ----------

	private function render_header( string $title, string $subtitle = '' ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$updated        = isset( $_GET['updated'] )        && '1' === $_GET['updated'];
		$reset          = isset( $_GET['reset'] )          && '1' === $_GET['reset'];
		$update_checked = isset( $_GET['update_checked'] ) && '1' === $_GET['update_checked'];
		$import_error   = isset( $_GET['import_error'] )   ? (int) $_GET['import_error'] : 0;
		// phpcs:enable
		?>
		<div class="wadl-wrap">
		<div class="wadl-header">
			<div class="wadl-header__logo">
				<span class="wadl-header__icon dashicons dashicons-chart-line"></span>
				<div>
					<h1 class="wadl-header__title"><?php echo esc_html( $title ); ?></h1>
					<?php if ( $subtitle ) : ?>
					<p class="wadl-header__subtitle"><?php echo esc_html( $subtitle ); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<span class="wadl-badge">v<?php echo esc_html( WADL_VERSION ); ?></span>
		</div>
		<?php if ( $updated ) : ?>
		<div class="wadl-notice wadl-notice--success">
			<span class="dashicons dashicons-yes-alt"></span>
			<?php esc_html_e( 'Réglages sauvegardés.', 'wp-analytics-datalayer' ); ?>
		</div>
		<?php elseif ( $reset ) : ?>
		<div class="wadl-notice wadl-notice--success">
			<span class="dashicons dashicons-yes-alt"></span>
			<?php esc_html_e( 'Réglages réinitialisés aux valeurs par défaut.', 'wp-analytics-datalayer' ); ?>
		</div>
		<?php elseif ( $update_checked ) : ?>
		<div class="wadl-notice wadl-notice--success">
			<span class="dashicons dashicons-update"></span>
			<?php esc_html_e( 'Vérification forcée — WordPress va re-contrôler les mises à jour.', 'wp-analytics-datalayer' ); ?>
		</div>
		<?php elseif ( $import_error ) : ?>
		<div class="wadl-notice wadl-notice--warning">
			<span class="dashicons dashicons-warning"></span>
			<?php esc_html_e( 'Erreur lors de l\'import — vérifiez que le fichier est un JSON valide exporté depuis ce plugin.', 'wp-analytics-datalayer' ); ?>
		</div>
		<?php endif; ?>
		<?php $this->render_nav(); ?>
		<?php
	}

	private function render_footer(): void {
		echo '</div><!-- .wadl-wrap -->';
	}

	private function render_nav(): void {
		$current = sanitize_key( $_GET['page'] ?? 'wadl-dashboard' ); // phpcs:ignore
		$items   = [
			'wadl-dashboard' => [ 'icon' => 'dashicons-dashboard', 'label' => 'Dashboard' ],
			'wadl-content'   => [ 'icon' => 'dashicons-media-document', 'label' => 'Contenu' ],
			'wadl-user'      => [ 'icon' => 'dashicons-admin-users', 'label' => 'Utilisateur' ],
			'wadl-events'    => [ 'icon' => 'dashicons-tag', 'label' => 'Événements GA4' ],
			'wadl-debug'     => [ 'icon' => 'dashicons-editor-code', 'label' => 'Debug JSON' ],
		];
		echo '<nav class="wadl-nav">';
		foreach ( $items as $slug => $item ) {
			$active = ( $current === $slug ) ? ' wadl-nav__item--active' : '';
			printf(
				'<a href="%s" class="wadl-nav__item%s"><span class="dashicons %s"></span> %s</a>',
				esc_url( admin_url( 'admin.php?page=' . $slug ) ),
				esc_attr( $active ),
				esc_attr( $item['icon'] ),
				esc_html( $item['label'] )
			);
		}
		echo '</nav>';
	}

	private function toggle( string $key, string $label, string $description, bool $checked ): void {
		$id = 'wadl_field_' . esc_attr( $key );
		?>
		<label class="wadl-toggle" for="<?php echo esc_attr( $id ); ?>">
			<div class="wadl-toggle__info">
				<span class="wadl-toggle__label"><?php echo esc_html( $label ); ?></span>
				<code class="wadl-toggle__key"><?php echo esc_html( $key ); ?></code>
				<span class="wadl-toggle__desc"><?php echo esc_html( $description ); ?></span>
			</div>
			<div class="wadl-toggle__switch">
				<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="wadl_<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $checked ); ?>>
				<span class="wadl-toggle__slider"></span>
			</div>
		</label>
		<?php
	}

	private function form_open( string $page ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wadl_save_settings">
			<input type="hidden" name="_redirect_page" value="<?php echo esc_attr( $page ); ?>">
			<?php wp_nonce_field( 'wadl_save_settings', 'wadl_nonce' ); ?>
		<?php
	}

	private function form_close( string $label = '' ): void {
		$label = $label ?: __( 'Enregistrer les réglages', 'wp-analytics-datalayer' );
		?>
			<div class="wadl-form-footer">
				<button type="submit" class="wadl-btn wadl-btn--primary">
					<span class="dashicons dashicons-saved"></span>
					<?php echo esc_html( $label ); ?>
				</button>
			</div>
		</form>
		<?php
	}

	// ---------- Pages ----------

	public function render_dashboard(): void {
		$settings = WADL_Core::get_settings();

		$active_content = 0;
		$active_user    = 0;
		$active_events  = 0;
		foreach ( $settings as $key => $val ) {
			if ( str_starts_with( $key, 'content_' ) && $val ) $active_content++;
			if ( str_starts_with( $key, 'user_' )    && $val ) $active_user++;
			if ( str_starts_with( $key, 'event_' )   && $val ) $active_events++;
		}

		$woo_active = function_exists( 'WC' );

		$this->render_header( 'DataLayer Analytics', 'Gouvernance du dataLayer analytics pour GTM / GA4' );
		?>
		<div class="wadl-dashboard">

			<div class="wadl-cards">
				<div class="wadl-card">
					<div class="wadl-card__icon wadl-card__icon--blue"><span class="dashicons dashicons-media-document"></span></div>
					<div class="wadl-card__body">
						<div class="wadl-card__value"><?php echo esc_html( $active_content ); ?></div>
						<div class="wadl-card__label"><?php esc_html_e( 'Champs contenu actifs', 'wp-analytics-datalayer' ); ?></div>
					</div>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wadl-content' ) ); ?>" class="wadl-card__link"><?php esc_html_e( 'Configurer', 'wp-analytics-datalayer' ); ?> →</a>
				</div>
				<div class="wadl-card">
					<div class="wadl-card__icon wadl-card__icon--green"><span class="dashicons dashicons-admin-users"></span></div>
					<div class="wadl-card__body">
						<div class="wadl-card__value"><?php echo esc_html( $active_user ); ?></div>
						<div class="wadl-card__label"><?php esc_html_e( 'Champs utilisateur actifs', 'wp-analytics-datalayer' ); ?></div>
					</div>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wadl-user' ) ); ?>" class="wadl-card__link"><?php esc_html_e( 'Configurer', 'wp-analytics-datalayer' ); ?> →</a>
				</div>
				<div class="wadl-card">
					<div class="wadl-card__icon wadl-card__icon--orange"><span class="dashicons dashicons-tag"></span></div>
					<div class="wadl-card__body">
						<div class="wadl-card__value"><?php echo esc_html( $active_events ); ?></div>
						<div class="wadl-card__label"><?php esc_html_e( 'Événements GA4 actifs', 'wp-analytics-datalayer' ); ?></div>
					</div>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wadl-events' ) ); ?>" class="wadl-card__link"><?php esc_html_e( 'Configurer', 'wp-analytics-datalayer' ); ?> →</a>
				</div>
				<div class="wadl-card">
					<div class="wadl-card__icon <?php echo $woo_active ? 'wadl-card__icon--purple' : 'wadl-card__icon--grey'; ?>">
						<span class="dashicons dashicons-cart"></span>
					</div>
					<div class="wadl-card__body">
						<div class="wadl-card__value"><?php echo $woo_active ? esc_html__( 'Actif', 'wp-analytics-datalayer' ) : esc_html__( 'Inactif', 'wp-analytics-datalayer' ); ?></div>
						<div class="wadl-card__label">WooCommerce</div>
					</div>
				</div>
			</div>

			<div class="wadl-panel">
				<h2 class="wadl-panel__title"><?php esc_html_e( 'Démarrage rapide', 'wp-analytics-datalayer' ); ?></h2>
				<ol class="wadl-steps">
					<li class="wadl-step">
						<span class="wadl-step__num">1</span>
						<div class="wadl-step__content">
							<strong><?php esc_html_e( 'Choisir les métadonnées à exposer', 'wp-analytics-datalayer' ); ?></strong>
							<p><?php esc_html_e( 'Activez uniquement les champs nécessaires à votre analytics. Moins = mieux.', 'wp-analytics-datalayer' ); ?></p>
						</div>
					</li>
					<li class="wadl-step">
						<span class="wadl-step__num">2</span>
						<div class="wadl-step__content">
							<strong><?php esc_html_e( 'Sélectionner les événements GA4', 'wp-analytics-datalayer' ); ?></strong>
							<p><?php esc_html_e( 'Si WooCommerce est actif, choisissez les événements e-commerce à pousser dans le dataLayer.', 'wp-analytics-datalayer' ); ?></p>
						</div>
					</li>
					<li class="wadl-step">
						<span class="wadl-step__num">3</span>
						<div class="wadl-step__content">
							<strong><?php esc_html_e( 'Vérifier dans l\'écran Debug', 'wp-analytics-datalayer' ); ?></strong>
							<p><?php esc_html_e( 'Visualisez le payload JSON qui sera injecté. Vérifiez en console navigateur.', 'wp-analytics-datalayer' ); ?></p>
						</div>
					</li>
				</ol>
			</div>

		</div>

			<div class="wadl-panel wadl-panel--tools">
				<h2 class="wadl-panel__title"><?php esc_html_e( 'Outils', 'wp-analytics-datalayer' ); ?></h2>
				<div class="wadl-tools">

					<!-- Export -->
					<div class="wadl-tool">
						<div class="wadl-tool__info">
							<strong><?php esc_html_e( 'Exporter les réglages', 'wp-analytics-datalayer' ); ?></strong>
							<p><?php esc_html_e( 'Télécharge la configuration actuelle au format JSON.', 'wp-analytics-datalayer' ); ?></p>
						</div>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="wadl_export_settings">
							<?php wp_nonce_field( 'wadl_export_settings', 'wadl_nonce' ); ?>
							<button type="submit" class="wadl-btn wadl-btn--secondary">
								<span class="dashicons dashicons-download"></span>
								<?php esc_html_e( 'Exporter', 'wp-analytics-datalayer' ); ?>
							</button>
						</form>
					</div>

					<!-- Import -->
					<div class="wadl-tool">
						<div class="wadl-tool__info">
							<strong><?php esc_html_e( 'Importer des réglages', 'wp-analytics-datalayer' ); ?></strong>
							<p><?php esc_html_e( 'Restaure une configuration depuis un fichier JSON précédemment exporté.', 'wp-analytics-datalayer' ); ?></p>
						</div>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="wadl-import-form">
							<input type="hidden" name="action" value="wadl_import_settings">
							<?php wp_nonce_field( 'wadl_import_settings', 'wadl_nonce' ); ?>
							<label class="wadl-file-label">
								<span class="dashicons dashicons-upload"></span>
								<span class="wadl-file-label__text"><?php esc_html_e( 'Choisir un fichier JSON…', 'wp-analytics-datalayer' ); ?></span>
								<input type="file" name="wadl_import_file" accept=".json" class="wadl-file-input">
							</label>
							<button type="submit" class="wadl-btn wadl-btn--secondary" id="wadl-import-btn" disabled>
								<?php esc_html_e( 'Importer', 'wp-analytics-datalayer' ); ?>
							</button>
						</form>
					</div>

					<!-- Force update check -->
					<div class="wadl-tool">
						<div class="wadl-tool__info">
							<strong><?php esc_html_e( 'Vérifier les mises à jour', 'wp-analytics-datalayer' ); ?></strong>
							<p><?php esc_html_e( 'Force WordPress à re-contrôler immédiatement si une nouvelle version est disponible.', 'wp-analytics-datalayer' ); ?></p>
						</div>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="wadl_force_update_check">
							<?php wp_nonce_field( 'wadl_force_update_check', 'wadl_nonce' ); ?>
							<button type="submit" class="wadl-btn wadl-btn--secondary">
								<span class="dashicons dashicons-update"></span>
								<?php esc_html_e( 'Vérifier maintenant', 'wp-analytics-datalayer' ); ?>
							</button>
						</form>
					</div>

					<!-- Reset -->
					<div class="wadl-tool wadl-tool--danger">
						<div class="wadl-tool__info">
							<strong><?php esc_html_e( 'Réinitialiser', 'wp-analytics-datalayer' ); ?></strong>
							<p><?php esc_html_e( 'Remet tous les réglages aux valeurs par défaut. Action irréversible.', 'wp-analytics-datalayer' ); ?></p>
						</div>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php esc_attr_e( 'Réinitialiser tous les réglages ?', 'wp-analytics-datalayer' ); ?>')">
							<input type="hidden" name="action" value="wadl_reset_settings">
							<?php wp_nonce_field( 'wadl_reset_settings', 'wadl_nonce' ); ?>
							<button type="submit" class="wadl-btn wadl-btn--danger">
								<span class="dashicons dashicons-trash"></span>
								<?php esc_html_e( 'Réinitialiser', 'wp-analytics-datalayer' ); ?>
							</button>
						</form>
					</div>

				</div>
			</div>

		</div>
		<?php
		$this->render_footer();
	}

	public function render_content(): void {
		$settings = WADL_Core::get_settings();
		$this->render_header( 'Contenu', 'Métadonnées de page et de contenu injectées dans le dataLayer' );
		$this->form_open( 'wadl-content' );
		?>
		<div class="wadl-sections">

			<div class="wadl-section">
				<div class="wadl-section__header">
					<span class="dashicons dashicons-admin-page"></span>
					<div>
						<h2><?php esc_html_e( 'Contexte de page', 'wp-analytics-datalayer' ); ?></h2>
						<p><?php esc_html_e( 'Décrit le type de page chargée — template WordPress et nature analytics.', 'wp-analytics-datalayer' ); ?></p>
					</div>
				</div>
				<div class="wadl-toggles">
					<?php
					$this->toggle( 'page_type',     'Type de page (analytics)', 'Nature business de la page : article, product, cart, checkout…', (bool) $settings['content_page_type'] );
					$this->toggle( 'page_template', 'Template WordPress',       'Template hiérarchique utilisé : single, archive, taxonomy…',     (bool) $settings['content_page_template'] );
					$this->toggle( 'page_title',    'Titre de la page',         'Titre HTML de la page courante.',                                  (bool) $settings['content_page_title'] );
					$this->toggle( 'page_url',      'URL de la page',           'URL complète de la page, sans paramètres de tracking.',            (bool) $settings['content_page_url'] );
					$this->toggle( 'post_type',     'Post type',                'Type de contenu WordPress : post, page, product…',                 (bool) $settings['content_post_type'] );
					$this->toggle( 'post_id',       'ID du post',               'Identifiant interne WordPress du contenu.',                         (bool) $settings['content_post_id'] );
					$this->toggle( 'post_slug',     'Slug du post',             'Identifiant URL du contenu.',                                       (bool) $settings['content_post_slug'] );
					?>
				</div>
			</div>

			<div class="wadl-section">
				<div class="wadl-section__header">
					<span class="dashicons dashicons-category"></span>
					<div>
						<h2><?php esc_html_e( 'Classification éditoriale', 'wp-analytics-datalayer' ); ?></h2>
						<p><?php esc_html_e( 'Catégories, tags et informations éditoriales liées au contenu.', 'wp-analytics-datalayer' ); ?></p>
					</div>
				</div>
				<div class="wadl-toggles">
					<?php
					$this->toggle( 'categories',        'Catégories',       'Catégories WordPress associées à l\'article ou la page.',   (bool) $settings['content_categories'] );
					$this->toggle( 'tags',              'Tags',             'Tags associés à l\'article.',                                (bool) $settings['content_tags'] );
					$this->toggle( 'main_taxonomy',     'Taxonomie principale', 'Première taxonomie associée au type de contenu.',        (bool) $settings['content_main_taxonomy'] );
					$this->toggle( 'author_id_hashed',  'Auteur (hashé)',   'Identifiant auteur anonymisé (SHA-256, non réversible).',     (bool) $settings['content_author_id_hashed'] );
					$this->toggle( 'publish_date',      'Date de publication', 'Date de publication au format AAAA-MM-JJ.',               (bool) $settings['content_publish_date'] );
					$this->toggle( 'modified_date',     'Date de modification', 'Date de dernière modification au format AAAA-MM-JJ.',    (bool) $settings['content_modified_date'] );
					?>
				</div>
			</div>

			<div class="wadl-section">
				<div class="wadl-section__header">
					<span class="dashicons dashicons-search"></span>
					<div>
						<h2><?php esc_html_e( 'Contexte de navigation', 'wp-analytics-datalayer' ); ?></h2>
						<p><?php esc_html_e( 'Données liées à la navigation : pagination et recherche interne.', 'wp-analytics-datalayer' ); ?></p>
					</div>
				</div>
				<div class="wadl-toggles">
					<?php
					$this->toggle( 'pagination_number',    'Numéro de page',        'Page courante dans une liste paginée.',                   (bool) $settings['content_pagination_number'] );
					$this->toggle( 'search_term',          'Terme de recherche',    'Mot-clé saisi dans la recherche interne (pages search).', (bool) $settings['content_search_term'] );
					$this->toggle( 'search_results_count', 'Résultats de recherche','Nombre de résultats retournés par la recherche interne.', (bool) $settings['content_search_results_count'] );
					?>
				</div>
			</div>

		</div>
		<?php
		$this->form_close();
		$this->render_footer();
	}

	public function render_user(): void {
		$settings = WADL_Core::get_settings();
		$this->render_header( 'Utilisateur', 'Métadonnées utilisateur injectées dans le dataLayer — données minimales, anonymisées' );
		?>
		<div class="wadl-notice wadl-notice--warning">
			<span class="dashicons dashicons-shield"></span>
			<?php esc_html_e( 'Aucune donnée personnelle brute n\'est exposée. Les identifiants sont hashés de façon non réversible. Respectez votre politique de confidentialité.', 'wp-analytics-datalayer' ); ?>
		</div>
		<?php
		$this->form_open( 'wadl-user' );
		?>
		<div class="wadl-sections">
			<div class="wadl-section">
				<div class="wadl-section__header">
					<span class="dashicons dashicons-admin-users"></span>
					<div>
						<h2><?php esc_html_e( 'Données utilisateur', 'wp-analytics-datalayer' ); ?></h2>
						<p><?php esc_html_e( 'Champs activables — seules les informations non personnelles sont exposées.', 'wp-analytics-datalayer' ); ?></p>
					</div>
				</div>
				<div class="wadl-toggles">
					<?php
					$this->toggle( 'user_logged_in',     'Connecté / déconnecté',    'Booléen : true si l\'utilisateur est connecté.',                      (bool) $settings['user_logged_in'] );
					$this->toggle( 'user_role',          'Rôle utilisateur',         'Rôle WordPress : subscriber, customer, editor…',                      (bool) $settings['user_role'] );
					$this->toggle( 'user_id_hashed',     'ID utilisateur (hashé)',   'Identifiant anonymisé (SHA-256 + salt, non réversible).',             (bool) $settings['user_id_hashed'] );
					?>

					<?php if ( function_exists( 'WC' ) ) : ?>
					<?php $this->toggle( 'user_customer_status', 'Statut client WooCommerce', 'new ou returning — basé sur l\'historique des commandes.', (bool) $settings['user_customer_status'] ); ?>
					<?php else : ?>
					<div class="wadl-toggle wadl-toggle--disabled">
						<div class="wadl-toggle__info">
							<span class="wadl-toggle__label"><?php esc_html_e( 'Statut client WooCommerce', 'wp-analytics-datalayer' ); ?></span>
							<code class="wadl-toggle__key">user_customer_status</code>
							<span class="wadl-toggle__desc"><?php esc_html_e( 'WooCommerce n\'est pas activé sur ce site.', 'wp-analytics-datalayer' ); ?></span>
						</div>
						<div class="wadl-toggle__switch wadl-toggle__switch--na">
							<span class="wadl-badge wadl-badge--grey"><?php esc_html_e( 'Non disponible', 'wp-analytics-datalayer' ); ?></span>
						</div>
					</div>
					<?php endif; ?>

				</div>
			</div>
		</div>
		<?php
		$this->form_close();
		$this->render_footer();
	}

	public function render_events(): void {
		$settings   = WADL_Core::get_settings();
		$woo_active = function_exists( 'WC' );
		$this->render_header( 'Événements GA4', 'Sélectionnez les événements e-commerce WooCommerce à pousser dans le dataLayer' );

		if ( ! $woo_active ) : ?>
		<div class="wadl-notice wadl-notice--warning">
			<span class="dashicons dashicons-info"></span>
			<?php esc_html_e( 'WooCommerce n\'est pas actif. Les événements GA4 e-commerce ne seront pas déclenchés.', 'wp-analytics-datalayer' ); ?>
		</div>
		<?php endif;

		$this->form_open( 'wadl-events' );
		?>
		<div class="wadl-sections">

			<div class="wadl-section">
				<div class="wadl-section__header">
					<span class="dashicons dashicons-store"></span>
					<div>
						<h2><?php esc_html_e( 'Catalogue produits', 'wp-analytics-datalayer' ); ?></h2>
						<p><?php esc_html_e( 'Événements déclenchés lors de la navigation dans les listes et fiches produit.', 'wp-analytics-datalayer' ); ?></p>
					</div>
				</div>
				<div class="wadl-toggles">
					<?php
					$this->toggle( 'event_view_item_list', 'view_item_list', 'Déclenché sur les pages liste/shop/catégorie.', (bool) $settings['event_view_item_list'] );
					$this->toggle( 'event_select_item',    'select_item',    'Déclenché au clic sur un produit dans une liste.', (bool) $settings['event_select_item'] );
					$this->toggle( 'event_view_item',      'view_item',      'Déclenché sur la fiche produit.', (bool) $settings['event_view_item'] );
					?>
				</div>
			</div>

			<div class="wadl-section">
				<div class="wadl-section__header">
					<span class="dashicons dashicons-cart"></span>
					<div>
						<h2><?php esc_html_e( 'Panier', 'wp-analytics-datalayer' ); ?></h2>
						<p><?php esc_html_e( 'Événements liés aux actions sur le panier.', 'wp-analytics-datalayer' ); ?></p>
					</div>
				</div>
				<div class="wadl-toggles">
					<?php
					$this->toggle( 'event_add_to_cart',      'add_to_cart',      'Déclenché à l\'ajout d\'un produit au panier.',     (bool) $settings['event_add_to_cart'] );
					$this->toggle( 'event_remove_from_cart', 'remove_from_cart', 'Déclenché à la suppression d\'un produit du panier.',(bool) $settings['event_remove_from_cart'] );
					$this->toggle( 'event_view_cart',        'view_cart',        'Déclenché à l\'affichage du panier.',                (bool) $settings['event_view_cart'] );
					$this->toggle( 'event_cart_all_items',   'cart.items',       'Expose le contenu complet du panier dans cart.items à chaque page et dans les events add_to_cart / remove_from_cart.', (bool) $settings['event_cart_all_items'] );
					?>
				</div>
			</div>

			<div class="wadl-section">
				<div class="wadl-section__header">
					<span class="dashicons dashicons-migrate"></span>
					<div>
						<h2><?php esc_html_e( 'Tunnel de conversion', 'wp-analytics-datalayer' ); ?></h2>
						<p><?php esc_html_e( 'Événements déclenchés lors du processus de commande.', 'wp-analytics-datalayer' ); ?></p>
					</div>
				</div>
				<div class="wadl-toggles">
					<?php
					$this->toggle( 'event_begin_checkout',    'begin_checkout',    'Déclenché à l\'arrivée sur la page checkout.',     (bool) $settings['event_begin_checkout'] );
					$this->toggle( 'event_add_shipping_info', 'add_shipping_info', 'Déclenché à la saisie des infos de livraison.',     (bool) $settings['event_add_shipping_info'] );
					$this->toggle( 'event_add_payment_info',  'add_payment_info',  'Déclenché à la saisie des infos de paiement.',      (bool) $settings['event_add_payment_info'] );
					?>
				</div>
			</div>

			<div class="wadl-section wadl-section--highlight">
				<div class="wadl-section__header">
					<span class="dashicons dashicons-awards"></span>
					<div>
						<h2><?php esc_html_e( 'Achat', 'wp-analytics-datalayer' ); ?></h2>
						<p><?php esc_html_e( 'Événement de confirmation de commande — le plus important du tunnel.', 'wp-analytics-datalayer' ); ?></p>
					</div>
				</div>
				<div class="wadl-toggles">
					<?php $this->toggle( 'event_purchase', 'purchase', 'Déclenché sur la page de confirmation de commande. Contient transaction_id, value, items.', (bool) $settings['event_purchase'] ); ?>
				</div>
			</div>

			<div class="wadl-section">
				<div class="wadl-section__header">
					<span class="dashicons dashicons-plus-alt2"></span>
					<div>
						<h2><?php esc_html_e( 'Événements optionnels', 'wp-analytics-datalayer' ); ?></h2>
						<p><?php esc_html_e( 'Événements secondaires — activez uniquement si vous en avez un besoin analytics réel.', 'wp-analytics-datalayer' ); ?></p>
					</div>
				</div>
				<div class="wadl-toggles">
					<?php
					$this->toggle( 'event_search',          'search',          'Déclenché sur les pages de résultats de recherche.', (bool) $settings['event_search'] );
					$this->toggle( 'event_login',           'login',           'Déclenché à la connexion d\'un utilisateur.',        (bool) $settings['event_login'] );
					$this->toggle( 'event_sign_up',         'sign_up',         'Déclenché à la création de compte.',                 (bool) $settings['event_sign_up'] );
					$this->toggle( 'event_add_to_wishlist', 'add_to_wishlist', 'Déclenché à l\'ajout à une liste de souhaits.',      (bool) $settings['event_add_to_wishlist'] );
					$this->toggle( 'event_view_promotion',  'view_promotion',  'Déclenché à l\'affichage d\'une promotion.',         (bool) $settings['event_view_promotion'] );
					$this->toggle( 'event_select_promotion','select_promotion','Déclenché au clic sur une promotion.',              (bool) $settings['event_select_promotion'] );
					?>
				</div>
			</div>

		</div>
		<?php
		$this->form_close();
		$this->render_footer();
	}

	public function render_debug(): void {
		$this->render_header( 'Debug JSON', 'Aperçu du payload dataLayer qui sera injecté en front-end' );
		$payload = WADL_Core::get_preview_payload();
		$json    = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		?>
		<div class="wadl-debug">
			<div class="wadl-debug__bar">
				<span class="dashicons dashicons-editor-code"></span>
				<strong><?php esc_html_e( 'Payload dataLayer (page courante admin)', 'wp-analytics-datalayer' ); ?></strong>
				<button class="wadl-btn wadl-btn--sm wadl-copy-btn" data-target="wadl-json-output">
					<span class="dashicons dashicons-clipboard"></span>
					<?php esc_html_e( 'Copier', 'wp-analytics-datalayer' ); ?>
				</button>
			</div>
			<pre class="wadl-debug__code" id="wadl-json-output"><?php echo esc_html( $json ); ?></pre>

			<div class="wadl-panel wadl-panel--info">
				<h3><?php esc_html_e( 'Comment vérifier en production ?', 'wp-analytics-datalayer' ); ?></h3>
				<ol>
					<li><?php esc_html_e( 'Ouvrez la console navigateur sur n\'importe quelle page.', 'wp-analytics-datalayer' ); ?></li>
					<li><?php esc_html_e( 'Saisissez :', 'wp-analytics-datalayer' ); ?> <code>console.log(window.dataLayer)</code></li>
					<li><?php esc_html_e( 'Ou utilisez l\'extension GTM Preview / Tag Assistant.', 'wp-analytics-datalayer' ); ?></li>
				</ol>
			</div>

			<div class="wadl-panel">
				<h3><?php esc_html_e( 'Champs actifs', 'wp-analytics-datalayer' ); ?></h3>
				<div class="wadl-field-list">
					<?php foreach ( $payload as $key => $value ) : ?>
					<div class="wadl-field-item">
						<code class="wadl-field-item__key"><?php echo esc_html( $key ); ?></code>
						<span class="wadl-field-item__value"><?php echo esc_html( is_array( $value ) ? '[ ' . count( $value ) . ' item(s) ]' : ( is_bool( $value ) ? ( $value ? 'true' : 'false' ) : $value ) ); ?></span>
					</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
		$this->render_footer();
	}
}
