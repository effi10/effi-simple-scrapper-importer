<?php
/**
 * Plugin Name: effiSimplyScrapperImporter
 * Description: Importe des contenus Markdown issus de SimplyScrapper vers WordPress avec conversion en blocs Gutenberg natifs
 * Version: 1.0.2
 * Author: Cédric GIRARD
 * Author URI: https://www.effi10.com
 * License: GPL v2 or later
 * Text Domain: effisimplyscrapper
 * Domain Path: /languages
 */

// Sécurité : empêcher l'accès direct
if (!defined('ABSPATH')) {
    exit;
}

// Définir les constantes du plugin
define('EFFISSI_VERSION', '1.0.2');
define('EFFISSI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EFFISSI_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Classe principale du plugin
 */
class EffiSimplyScrapperImporter {
    
    private static $instance = null;
    
    /**
     * Singleton
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructeur
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }
    
    /**
     * Charger les dépendances
     */
    private function load_dependencies() {
        require_once EFFISSI_PLUGIN_DIR . 'includes/class-parsedown-loader.php';
        require_once EFFISSI_PLUGIN_DIR . 'includes/class-markdown-parser.php';
        require_once EFFISSI_PLUGIN_DIR . 'includes/class-gutenberg-converter.php';
        require_once EFFISSI_PLUGIN_DIR . 'includes/class-importer.php';
    }
    
    /**
     * Initialiser les hooks
     */
    private function init_hooks() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_ajax_effissi_import_file', array($this, 'ajax_import_file'));
    }
    
    /**
     * Ajouter le menu d'administration
     */
    public function add_admin_menu() {
        add_management_page(
            __('SimplyScrapper Importer', 'effisimplyscrapper'),
            __('SimplyScrapper Importer', 'effisimplyscrapper'),
            'manage_options',
            'effissi-importer',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Charger les assets (CSS/JS)
     */
    public function enqueue_admin_assets($hook) {
        if ('tools_page_effissi-importer' !== $hook) {
            return;
        }
        
        wp_enqueue_style(
            'effissi-admin-style',
            EFFISSI_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            EFFISSI_VERSION
        );
        
        wp_enqueue_script(
            'effissi-admin-script',
            EFFISSI_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            EFFISSI_VERSION,
            true
        );
        
        wp_localize_script('effissi-admin-script', 'effissiAjax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('effissi_import_nonce'),
            'strings' => array(
                'processing' => __('Traitement du fichier', 'effisimplyscrapper'),
                'of' => __('sur', 'effisimplyscrapper'),
                'completed' => __('Import terminé !', 'effisimplyscrapper'),
                'error' => __('Erreur', 'effisimplyscrapper'),
                'success' => __('articles importés avec succès', 'effisimplyscrapper'),
                'failed' => __('échecs', 'effisimplyscrapper')
            )
        ));
    }
    
    /**
     * Afficher la page d'administration
     */
    public function render_admin_page() {
        ?>
        <div class="wrap effissi-admin-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="effissi-card">
                <form id="effissi-import-form" enctype="multipart/form-data">
                    
                    <div class="effissi-form-group">
                        <label for="effissi-files">
                            <strong><?php _e('Fichiers Markdown (.md)', 'effisimplyscrapper'); ?></strong>
                        </label>
                        <input 
                            type="file" 
                            id="effissi-files" 
                            name="effissi_files[]" 
                            accept=".md" 
                            multiple 
                            required
                        />
                        <p class="description">
                            <?php _e('Sélectionnez un ou plusieurs fichiers .md à importer', 'effisimplyscrapper'); ?>
                        </p>
                    </div>
                    
                    <div class="effissi-form-group">
                        <label>
                            <strong><?php _e('Statut des articles', 'effisimplyscrapper'); ?></strong>
                        </label>
                        <div class="effissi-radio-group">
                            <label>
                                <input type="radio" name="post_status" value="draft" checked>
                                <?php _e('Brouillon', 'effisimplyscrapper'); ?>
                            </label>
                            <label>
                                <input type="radio" name="post_status" value="publish">
                                <?php _e('Publié', 'effisimplyscrapper'); ?>
                            </label>
                        </div>
                    </div>
                    
                    <div class="effissi-form-group">
                        <label>
                            <input type="checkbox" name="attach_images" id="effissi-attach-images" value="1">
                            <?php _e('Rattacher les photos par numérotation', 'effisimplyscrapper'); ?>
                        </label>
                        <p class="description">
                            <?php _e('Si le slug contient un numéro (ex: recettes-c123), cherche une image 123.jpg dans la bibliothèque média et la définit comme image à la une.', 'effisimplyscrapper'); ?>
                        </p>
                    </div>
                    
                    <div class="effissi-form-group">
                        <button type="submit" class="button button-primary button-large" id="effissi-import-btn">
                            <?php _e('Importer', 'effisimplyscrapper'); ?>
                        </button>
                    </div>
                    
                </form>
                
                <div id="effissi-progress-container" style="display:none;">
                    <div class="effissi-progress-info">
                        <span id="effissi-progress-text"><?php _e('Préparation...', 'effisimplyscrapper'); ?></span>
                    </div>
                    <div class="effissi-progress-bar">
                        <div class="effissi-progress-fill" id="effissi-progress-fill"></div>
                    </div>
                    <div class="effissi-progress-stats" id="effissi-progress-stats"></div>
                </div>
                
                <div id="effissi-results" style="display:none;"></div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Handler AJAX pour l'import d'un fichier
     */
    public function ajax_import_file() {
        check_ajax_referer('effissi_import_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission refusée', 'effisimplyscrapper')));
        }
        
        if (!isset($_FILES['file']) || !isset($_POST['post_status'])) {
            wp_send_json_error(array('message' => __('Données manquantes', 'effisimplyscrapper')));
        }
        
        $file = $_FILES['file'];
        $post_status = sanitize_text_field($_POST['post_status']);
        $attach_images = isset($_POST['attach_images']) && $_POST['attach_images'] === '1';
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(array('message' => __('Erreur lors du téléversement', 'effisimplyscrapper')));
        }
        
        // Vérifier l'extension
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($file_ext !== 'md') {
            wp_send_json_error(array('message' => __('Format de fichier invalide', 'effisimplyscrapper')));
        }
        
        try {
            $importer = new EFFISSI_Importer();
            $result = $importer->import_file($file['tmp_name'], $post_status, $attach_images);
            
            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result);
            }
            
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => $e->getMessage()
            ));
        }
    }
}

// Initialiser le plugin
function effissi_init() {
    return EffiSimplyScrapperImporter::get_instance();
}
add_action('plugins_loaded', 'effissi_init');

/**
 * Activation du plugin
 */
register_activation_hook(__FILE__, function() {
    // Créer le dossier des assets si nécessaire
    $upload_dir = wp_upload_dir();
    $effissi_dir = $upload_dir['basedir'] . '/effisimplyscrapper';
    
    if (!file_exists($effissi_dir)) {
        wp_mkdir_p($effissi_dir);
    }
});

/**
 * Désactivation du plugin
 */
register_deactivation_hook(__FILE__, function() {
    // Nettoyage si nécessaire
});