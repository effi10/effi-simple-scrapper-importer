<?php
/**
 * FICHIER: includes/class-importer.php
 * Gère l'import des fichiers et la création des articles
 */

if (!defined('ABSPATH')) {
    exit;
}

class EFFISSI_Importer {
    
    private $parser;
    private $converter;
    
    public function __construct() {
        $this->parser = new EFFISSI_Markdown_Parser();
        $this->converter = new EFFISSI_Gutenberg_Converter();
    }
    
    /**
     * Importer un fichier
     * 
     * @param string $file_path Chemin du fichier
     * @param string $post_status Statut du post (draft/publish)
     * @param bool $attach_images Rattacher les images par numérotation
     * @return array Résultat de l'import
     */
    public function import_file($file_path, $post_status = 'draft', $attach_images = false) {
        try {
            // Parser le fichier
            $data = $this->parser->parse_file($file_path);
            
            // Convertir en blocs Gutenberg
            $blocks_content = $this->converter->convert_to_blocks($data['markdown']);
            
            // Créer l'article
            $post_id = $this->create_post($data, $blocks_content, $post_status);
            
            // Rattacher l'image à la une si demandé
            if ($attach_images) {
                $this->attach_featured_image($post_id, $data['slug']);
            }
            
            return array(
                'success' => true,
                'post_id' => $post_id,
                'title' => $data['title'],
                'message' => sprintf(
                    __('Article "%s" importé avec succès (ID: %d)', 'effisimplyscrapper'),
                    $data['title'],
                    $post_id
                )
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }
    
    /**
     * Créer un article WordPress
     */
    private function create_post($data, $content, $post_status) {
        $post_data = array(
            'post_title' => wp_strip_all_tags($data['title']),
            'post_name' => $data['slug'],
            'post_content' => $content,
            'post_status' => $post_status,
            'post_type' => 'post',
            'post_author' => get_current_user_id(),
        );
        
        $post_id = wp_insert_post($post_data, true);
        
        if (is_wp_error($post_id)) {
            throw new Exception($post_id->get_error_message());
        }
        
        return $post_id;
    }
    
    /**
     * Rattacher l'image à la une en fonction du numéro dans le slug
     * Exemple: recettes-autour-du-cafe-c123 → cherche 123.jpg
     * 
     * @param int $post_id ID de l'article
     * @param string $slug Slug de l'article
     */
    private function attach_featured_image($post_id, $slug) {
        // Extraire le numéro du slug
        $number = $this->extract_number_from_slug($slug);
        
        if (empty($number)) {
            return; // Pas de numéro trouvé
        }
        
        // Chercher l'image dans la bibliothèque média
        $attachment_id = $this->find_image_by_number($number);
        
        if ($attachment_id) {
            // Définir comme image à la une
            set_post_thumbnail($post_id, $attachment_id);
        }
    }
    
    /**
     * Extraire le numéro du slug
     * Supporte les formats: -c123, -n123, -123, etc.
     * 
     * @param string $slug
     * @return string|null Numéro extrait ou null
     */
    private function extract_number_from_slug($slug) {
        // Pattern: cherche un tiret suivi d'une lettre optionnelle et des chiffres à la fin
        // Exemples: -c123, -n456, -789
        if (preg_match('/-([a-z]?)(\d+)$/i', $slug, $matches)) {
            return $matches[2]; // Retourne uniquement le numéro
        }
        
        return null;
    }
    
    /**
     * Chercher une image dans la bibliothèque média par son numéro
     * Cherche les fichiers: {number}.jpg, {number}.jpeg, {number}.png, {number}.gif, {number}.webp
     * 
     * @param string $number Numéro à chercher
     * @return int|null ID de l'attachment ou null
     */
    private function find_image_by_number($number) {
        // Extensions supportées
        $extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp');
        
        foreach ($extensions as $ext) {
            $filename = $number . '.' . $ext;
            
            // Chercher dans la bibliothèque média
            $args = array(
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'posts_per_page' => 1,
                'meta_query' => array(
                    array(
                        'key' => '_wp_attached_file',
                        'value' => $filename,
                        'compare' => 'LIKE'
                    )
                )
            );
            
            $query = new WP_Query($args);
            
            if ($query->have_posts()) {
                return $query->posts[0]->ID;
            }
        }
        
        return null;
    }
}
