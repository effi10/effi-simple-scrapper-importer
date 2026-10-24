<?php
/**
 * Plugin Name:       effi SimplyScrapper Importer
 * Plugin URI:        https://github.com/effi10/effi-simple-scrapper-importer
 * Description:       Importe des fichiers Markdown issus de SimplyScrapper sous forme d'articles WordPress en blocs Gutenberg natifs : slug d'origine conservé, image à la une retrouvée par numéro, images importables dans la médiathèque.
 * Version:           1.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Cédric Girard
 * Author URI:        https://www.effi10.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       effi-simple-scrapper-importer
 * Update URI:        https://github.com/effi10/effi-simple-scrapper-importer
 *
 * @package EffiSimplyScrapperImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EFFISSI_VERSION', '1.1.0' );
define( 'EFFISSI_PLUGIN_FILE', __FILE__ );
define( 'EFFISSI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EFFISSI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once EFFISSI_PLUGIN_DIR . 'includes/class-parsedown-loader.php';
require_once EFFISSI_PLUGIN_DIR . 'includes/class-markdown-parser.php';
require_once EFFISSI_PLUGIN_DIR . 'includes/class-gutenberg-converter.php';
require_once EFFISSI_PLUGIN_DIR . 'includes/class-image-importer.php';
require_once EFFISSI_PLUGIN_DIR . 'includes/class-upload-validator.php';
require_once EFFISSI_PLUGIN_DIR . 'includes/class-importer.php';

/**
 * Classe principale : page d'outil et point d'entrée AJAX.
 */
final class EFFISSI_Plugin {

	/** Slug de la page d'outil. */
	const PAGE_SLUG = 'effissi-importer';

	/** Action AJAX et action du nonce. */
	const AJAX_ACTION = 'effissi_import_file';
	const NONCE       = 'effissi_import_nonce';

	/** @var EFFISSI_Plugin|null */
	private static $instance = null;

	/**
	 * @return EFFISSI_Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_import_file' ) );
	}

	/**
	 * Capacité requise pour utiliser l'outil.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Capacité requise pour accéder à l'importeur (manage_options par défaut).
		 *
		 * @param string $capability Capacité.
		 */
		return (string) apply_filters( 'effissi_required_capability', 'manage_options' );
	}

	public function add_admin_menu() {
		add_management_page(
			__( 'SimplyScrapper Importer', 'effi-simple-scrapper-importer' ),
			__( 'SimplyScrapper Importer', 'effi-simple-scrapper-importer' ),
			self::capability(),
			self::PAGE_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * @param string $hook Page d'administration courante.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'tools_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'effissi-admin', EFFISSI_PLUGIN_URL . 'assets/css/admin.css', array(), EFFISSI_VERSION );
		wp_enqueue_script( 'effissi-admin', EFFISSI_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), EFFISSI_VERSION, true );

		$max_file_size = EFFISSI_Upload_Validator::max_file_size();

		wp_localize_script(
			'effissi-admin',
			'effissiAjax',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'action'           => self::AJAX_ACTION,
				'nonce'            => wp_create_nonce( self::NONCE ),
				'maxFileSize'      => $max_file_size,
				'maxFileSizeLabel' => size_format( $max_file_size ),
				'strings'          => array(
					/* translators: 1: rang du fichier, 2: nombre de fichiers, 3: nom du fichier */
					'processing'   => __( 'Traitement du fichier %1$s sur %2$s : %3$s', 'effi-simple-scrapper-importer' ),
					'starting'     => __( 'Démarrage de l\'import...', 'effi-simple-scrapper-importer' ),
					'running'      => __( 'Import en cours...', 'effi-simple-scrapper-importer' ),
					'submit'       => __( 'Importer', 'effi-simple-scrapper-importer' ),
					'completed'    => __( 'Import terminé.', 'effi-simple-scrapper-importer' ),
					'noFile'       => __( 'Veuillez sélectionner au moins un fichier .md.', 'effi-simple-scrapper-importer' ),
					'oneFile'      => __( '1 fichier sélectionné', 'effi-simple-scrapper-importer' ),
					/* translators: %s: nombre de fichiers */
					'manyFiles'    => __( '%s fichiers sélectionnés', 'effi-simple-scrapper-importer' ),
					'badExtension' => __( 'Extension refusée : seuls les fichiers .md sont acceptés.', 'effi-simple-scrapper-importer' ),
					/* translators: %s: taille maximale */
					'tooBig'       => __( 'Fichier trop volumineux (maximum %s).', 'effi-simple-scrapper-importer' ),
					'emptyFile'    => __( 'Fichier vide.', 'effi-simple-scrapper-importer' ),
					'timeout'      => __( 'Délai dépassé : le serveur n\'a pas répondu à temps.', 'effi-simple-scrapper-importer' ),
					'forbidden'    => __( 'Session expirée ou droits insuffisants : rechargez la page.', 'effi-simple-scrapper-importer' ),
					'unexpected'   => __( 'Réponse inattendue du serveur (consultez le journal PHP).', 'effi-simple-scrapper-importer' ),
					'network'      => __( 'Erreur réseau', 'effi-simple-scrapper-importer' ),
					'unknown'      => __( 'Erreur inconnue', 'effi-simple-scrapper-importer' ),
					/* translators: 1: traités, 2: total, 3: importés, 4: ignorés, 5: échecs */
					'stats'        => __( 'Progression : %1$s / %2$s · importés : %3$s · ignorés : %4$s · échecs : %5$s', 'effi-simple-scrapper-importer' ),
					'titleOk'      => __( 'Import terminé', 'effi-simple-scrapper-importer' ),
					'titlePartial' => __( 'Import terminé avec des fichiers ignorés ou en échec', 'effi-simple-scrapper-importer' ),
					'titleError'   => __( 'Échec de l\'import', 'effi-simple-scrapper-importer' ),
					'imported'     => __( 'Importé', 'effi-simple-scrapper-importer' ),
					'skipped'      => __( 'Ignoré', 'effi-simple-scrapper-importer' ),
					'failed'       => __( 'Échec', 'effi-simple-scrapper-importer' ),
					'edit'         => __( 'Modifier', 'effi-simple-scrapper-importer' ),
				),
			)
		);
	}

	public function render_admin_page() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas les droits suffisants pour accéder à cette page.', 'effi-simple-scrapper-importer' ) );
		}

		$can_publish = current_user_can( 'publish_posts' );
		$can_upload  = current_user_can( 'upload_files' );
		?>
		<div class="wrap effissi-admin-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'N\'importez que des contenus et des images que vous avez le droit de réutiliser (vos propres sites, contenus sous licence ou avec l\'autorisation écrite de leur auteur).', 'effi-simple-scrapper-importer' ); ?></p>
			</div>

			<noscript>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'JavaScript est nécessaire pour utiliser l\'importeur.', 'effi-simple-scrapper-importer' ); ?></p></div>
			</noscript>

			<div class="effissi-card">
				<form id="effissi-import-form" enctype="multipart/form-data">

					<div class="effissi-form-group">
						<label for="effissi-files"><strong><?php esc_html_e( 'Fichiers Markdown (.md)', 'effi-simple-scrapper-importer' ); ?></strong></label>
						<input type="file" id="effissi-files" name="effissi_files[]" accept=".md,text/markdown" multiple required />
						<p class="description">
							<?php
							printf(
								/* translators: %s: taille maximale */
								esc_html__( 'Un ou plusieurs fichiers .md encodés en UTF-8, traités l\'un après l\'autre. Taille maximale par fichier : %s.', 'effi-simple-scrapper-importer' ),
								esc_html( size_format( EFFISSI_Upload_Validator::max_file_size() ) )
							);
							?>
						</p>
					</div>

					<fieldset class="effissi-form-group">
						<legend><strong><?php esc_html_e( 'Statut des articles', 'effi-simple-scrapper-importer' ); ?></strong></legend>
						<div class="effissi-radio-group">
							<label><input type="radio" name="post_status" value="draft" checked /> <?php esc_html_e( 'Brouillon', 'effi-simple-scrapper-importer' ); ?></label>
							<label><input type="radio" name="post_status" value="pending" /> <?php esc_html_e( 'En attente de relecture', 'effi-simple-scrapper-importer' ); ?></label>
							<?php if ( $can_publish ) : ?>
								<label><input type="radio" name="post_status" value="publish" /> <?php esc_html_e( 'Publié', 'effi-simple-scrapper-importer' ); ?></label>
							<?php endif; ?>
						</div>
					</fieldset>

					<fieldset class="effissi-form-group">
						<legend><strong><?php esc_html_e( 'Si le slug existe déjà', 'effi-simple-scrapper-importer' ); ?></strong></legend>
						<label class="effissi-block-label"><input type="radio" name="duplicates" value="skip" checked /> <?php esc_html_e( 'Ignorer le fichier et le signaler (recommandé)', 'effi-simple-scrapper-importer' ); ?></label>
						<label class="effissi-block-label"><input type="radio" name="duplicates" value="import" /> <?php esc_html_e( 'Importer quand même et le signaler (WordPress ajoutera un suffixe au slug à la publication)', 'effi-simple-scrapper-importer' ); ?></label>
					</fieldset>

					<div class="effissi-form-group">
						<label class="effissi-block-label">
							<input type="checkbox" name="featured_by_number" id="effissi-featured-by-number" value="1" />
							<?php esc_html_e( 'Rattacher l\'image à la une par numérotation', 'effi-simple-scrapper-importer' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Si le slug se termine par un numéro (ex. : mon-article-c123), l\'image 123.jpg (ou .jpeg, .png, .gif, .webp, .avif) déjà présente dans la médiathèque devient l\'image à la une.', 'effi-simple-scrapper-importer' ); ?></p>
					</div>

					<div class="effissi-form-group">
						<label class="effissi-block-label">
							<input type="checkbox" name="import_images" id="effissi-import-images" value="1" <?php disabled( ! $can_upload ); ?> />
							<?php esc_html_e( 'Importer les images dans la médiathèque', 'effi-simple-scrapper-importer' ); ?>
						</label>
						<p class="description">
							<?php
							printf(
								/* translators: 1: nombre maximal d'images, 2: taille maximale */
								esc_html__( 'Décoché (par défaut), les images restent chargées depuis le site source. Coché, jusqu\'à %1$s images JPEG, PNG, GIF, WebP ou AVIF par article (%2$s maximum chacune) sont copiées dans la médiathèque ; les autres restent liées au site source.', 'effi-simple-scrapper-importer' ),
								esc_html( number_format_i18n( EFFISSI_Image_Importer::max_images() ) ),
								esc_html( size_format( EFFISSI_Image_Importer::max_bytes() ) )
							);
							?>
						</p>
					</div>

					<div class="effissi-form-group">
						<button type="submit" class="button button-primary button-large" id="effissi-import-btn"><?php esc_html_e( 'Importer', 'effi-simple-scrapper-importer' ); ?></button>
					</div>
				</form>

				<div id="effissi-progress-container" hidden>
					<div class="effissi-progress-info"><span id="effissi-progress-text"></span></div>
					<div class="effissi-progress-bar"><div class="effissi-progress-fill" id="effissi-progress-fill"></div></div>
					<div class="effissi-progress-stats" id="effissi-progress-stats" aria-live="polite"></div>
				</div>

				<div id="effissi-results" hidden></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Point d'entrée AJAX : importe un fichier par requête (le navigateur
	 * envoie les fichiers l'un après l'autre).
	 */
	public function ajax_import_file() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( self::capability() ) || ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission refusée.', 'effi-simple-scrapper-importer' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- entrée de téléversement validée par EFFISSI_Upload_Validator.
		$file = isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : array();
		$tmp  = '';

		try {
			$validator = new EFFISSI_Upload_Validator();
			$tmp       = $validator->validate( $file );

			$importer = new EFFISSI_Importer();
			$options  = $importer->normalize_options(
				array(
					'post_status'        => self::post_key( 'post_status', 'draft' ),
					'duplicates'         => self::post_key( 'duplicates', 'skip' ),
					'featured_by_number' => '1' === self::post_key( 'featured_by_number', '0' ),
					'import_images'      => '1' === self::post_key( 'import_images', '0' ),
				)
			);

			$result = $importer->import_file( $tmp, $options );
		} catch ( Throwable $e ) {
			$this->delete_temp_file( $tmp );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}

		$this->delete_temp_file( $tmp );
		wp_send_json_success( $result );
	}

	/**
	 * Lit un champ POST simple (clé alphanumérique), le nonce ayant été vérifié.
	 *
	 * @param string $key     Nom du champ.
	 * @param string $default Valeur par défaut.
	 * @return string
	 */
	private static function post_key( $key, $default ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié dans ajax_import_file().
		if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
			return $default;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié dans ajax_import_file().
		return sanitize_key( wp_unslash( $_POST[ $key ] ) );
	}

	/**
	 * Supprime le fichier temporaire téléversé.
	 *
	 * @param string $path Chemin.
	 */
	private function delete_temp_file( $path ) {
		if ( '' !== $path && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}
}

/*
 * Compatibilité : ancien nom de la classe principale (versions <= 1.0.2).
 */
class_alias( 'EFFISSI_Plugin', 'EffiSimplyScrapperImporter' );

/**
 * Démarre l'extension (administration et requêtes AJAX uniquement).
 *
 * @return EFFISSI_Plugin|null
 */
function effissi_init() {
	if ( ! is_admin() ) {
		return null;
	}

	return EFFISSI_Plugin::get_instance();
}
add_action( 'plugins_loaded', 'effissi_init' );
