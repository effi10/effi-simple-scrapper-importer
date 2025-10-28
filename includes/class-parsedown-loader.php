<?php
/**
 * FICHIER: includes/class-parsedown-loader.php
 * Chargeur pour la bibliothèque Parsedown
 */

if (!defined('ABSPATH')) {
    exit;
}

class EFFISSI_Parsedown_Loader {
    
    private static $parsedown = null;
    
    /**
     * Obtenir une instance de Parsedown
     */
    public static function get_parsedown() {
        if (null === self::$parsedown) {
            // Charger Parsedown depuis le dossier vendor
            $parsedown_file = EFFISSI_PLUGIN_DIR . 'vendor/parsedown/Parsedown.php';
            
            if (!file_exists($parsedown_file)) {
                throw new Exception(__('Parsedown non trouvé. Veuillez installer les dépendances.', 'effisimplyscrapper'));
            }
            
            require_once $parsedown_file;
            self::$parsedown = new Parsedown();
        }
        
        return self::$parsedown;
    }
}