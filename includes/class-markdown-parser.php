<?php
/**
 * FICHIER: includes/class-markdown-parser.php
 * Parse le contenu Markdown et extrait les métadonnées
 */

if (!defined('ABSPATH')) {
    exit;
}

class EFFISSI_Markdown_Parser {
    
    /**
     * Parser un fichier Markdown
     * 
     * @param string $file_path Chemin du fichier
     * @return array Données parsées (title, slug, content)
     */
    public function parse_file($file_path) {
        if (!file_exists($file_path)) {
            throw new Exception(__('Fichier introuvable', 'effisimplyscrapper'));
        }
        
        $content = file_get_contents($file_path);
        
        if (empty($content)) {
            throw new Exception(__('Fichier vide', 'effisimplyscrapper'));
        }
        
        return $this->parse_content($content);
    }
    
    /**
     * Parser le contenu Markdown
     * 
     * @param string $content Contenu brut
     * @return array Données parsées
     */
    public function parse_content($content) {
        $lines = explode("\n", $content);
        
        if (count($lines) < 3) {
            throw new Exception(__('Format de fichier invalide', 'effisimplyscrapper'));
        }
        
        // Extraire le titre et le slug des 2 premières lignes
        $title_line = trim($lines[0]);
        $url_line = trim($lines[1]);
        
        $title = $this->extract_title($title_line);
        $slug = $this->extract_slug($url_line);
        
        // Le contenu commence à partir de la ligne 2 (index 2)
        $content_lines = array_slice($lines, 2);
        $markdown_content = trim(implode("\n", $content_lines));
        
        return array(
            'title' => $title,
            'slug' => $slug,
            'markdown' => $markdown_content
        );
    }
    
    /**
     * Extraire le titre depuis la première ligne
     * Format: # [Titre](url)
     */
    private function extract_title($line) {
        // Pattern: # [Texte](url)
        if (preg_match('/^#\s*\[(.*?)\]\(.*?\)/', $line, $matches)) {
            return trim($matches[1]);
        }
        
        // Fallback: essayer de trouver un titre entre crochets
        if (preg_match('/\[(.*?)\]/', $line, $matches)) {
            return trim($matches[1]);
        }
        
        // Dernier recours: nettoyer la ligne
        return trim(str_replace(array('#', '[', ']'), '', $line));
    }
    
    /**
     * Extraire le slug depuis la deuxième ligne
     * Format: _https://example.com/blog/slug-article_
     * Le slug extrait correspond exactement au dernier segment de l'URL
     */
    private function extract_slug($line) {
        // Nettoyer les underscores et espaces
        $line = trim($line, '_ ');
        
        // Extraire le dernier segment de l'URL (le slug exact)
        if (preg_match('/https?:\/\/[^\s]+\/([^\s\/]+)$/', $line, $matches)) {
            $slug = $matches[1];
            
            // Retourner le slug exact, juste sanitizé pour WordPress
            return sanitize_title($slug);
        }
        
        // Fallback: utiliser la ligne nettoyée
        return sanitize_title($line);
    }
}
