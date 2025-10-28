<?php
/**
 * FICHIER: includes/class-gutenberg-converter.php
 * Convertit le HTML en blocs Gutenberg natifs
 */

if (!defined('ABSPATH')) {
    exit;
}

class EFFISSI_Gutenberg_Converter {
    
    /**
     * Convertir le Markdown en blocs Gutenberg
     * 
     * @param string $markdown Contenu Markdown
     * @return string Contenu au format blocs Gutenberg
     */
    public function convert_to_blocks($markdown) {
        // Convertir Markdown en HTML via Parsedown
        $parsedown = EFFISSI_Parsedown_Loader::get_parsedown();
        $html = $parsedown->text($markdown);
        
        // Parser le HTML et convertir en blocs
        return $this->html_to_blocks($html);
    }
    
    /**
     * Convertir HTML en blocs Gutenberg
     * 
     * @param string $html Contenu HTML
     * @return string Blocs Gutenberg
     */
    private function html_to_blocks($html) {
        // Utiliser DOMDocument pour parser le HTML
        $dom = new DOMDocument('1.0', 'UTF-8');
        
        // Supprimer les erreurs HTML
        libxml_use_internal_errors(true);
        
        // Charger le HTML avec encodage UTF-8
        // On enveloppe dans une div pour éviter les problèmes de parsing
        $wrapped_html = '<div>' . $html . '</div>';
        $dom->loadHTML('<?xml encoding="UTF-8">' . $wrapped_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        
        libxml_clear_errors();
        
        $blocks = array();
        
        // Trouver le nœud racine (body ou le wrapper div)
        $root_node = null;
        
        if ($dom->documentElement) {
            // Si c'est un body, utiliser directement
            if ($dom->documentElement->nodeName === 'body') {
                $root_node = $dom->documentElement;
            } 
            // Si c'est notre div wrapper, utiliser directement
            else if ($dom->documentElement->nodeName === 'div') {
                $root_node = $dom->documentElement;
            }
            // Sinon, essayer de trouver un body
            else {
                $bodies = $dom->getElementsByTagName('body');
                if ($bodies->length > 0) {
                    $root_node = $bodies->item(0);
                } else {
                    // Fallback sur documentElement
                    $root_node = $dom->documentElement;
                }
            }
        }
        
        // Parser chaque nœud enfant
        if ($root_node) {
            foreach ($root_node->childNodes as $node) {
                $block = $this->node_to_block($node);
                if (!empty($block)) {
                    $blocks[] = $block;
                }
            }
        }
        
        return implode("\n\n", $blocks);
    }
    
    /**
     * Convertir un nœud DOM en bloc Gutenberg
     * 
     * @param DOMNode $node
     * @return string Bloc Gutenberg
     */
    private function node_to_block($node) {
        // Gérer les nœuds texte directement (sans balise)
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = trim($node->textContent);
            if (!empty($text)) {
                // Créer un paragraphe pour le texte orphelin
                return sprintf(
                    '<!-- wp:paragraph -->
<p>%s</p>
<!-- /wp:paragraph -->',
                    htmlspecialchars($text)
                );
            }
            return '';
        }
        
        // Ignorer les autres types de nœuds non-éléments
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }
        
        $tag_name = strtolower($node->nodeName);
        
        switch ($tag_name) {
            case 'h1':
                return $this->create_heading_block($node, 1);
            case 'h2':
                return $this->create_heading_block($node, 2);
            case 'h3':
                return $this->create_heading_block($node, 3);
            case 'h4':
                return $this->create_heading_block($node, 4);
            case 'h5':
                return $this->create_heading_block($node, 5);
            case 'h6':
                return $this->create_heading_block($node, 6);
            case 'p':
                return $this->create_paragraph_block($node);
            case 'ul':
                return $this->create_list_block($node, false);
            case 'ol':
                return $this->create_list_block($node, true);
            case 'blockquote':
                return $this->create_quote_block($node);
            case 'pre':
                return $this->create_code_block($node);
            case 'img':
                return $this->create_image_block($node);
            case 'hr':
                return $this->create_separator_block();
            case 'table':
                return $this->create_table_block($node);
            default:
                return $this->create_html_block($node);
        }
    }
    
    /**
     * Créer un bloc titre
     */
    private function create_heading_block($node, $level) {
        $content = $this->get_inner_html($node);
        return sprintf(
            '<!-- wp:heading {"level":%d} -->
<h%d class="wp-block-heading">%s</h%d>
<!-- /wp:heading -->',
            $level,
            $level,
            $content,
            $level
        );
    }
    
    /**
     * Créer un bloc paragraphe
     */
    private function create_paragraph_block($node) {
        $content = $this->get_inner_html($node);
        
        if (empty(trim(strip_tags($content)))) {
            return '';
        }
        
        return sprintf(
            '<!-- wp:paragraph -->
<p>%s</p>
<!-- /wp:paragraph -->',
            $content
        );
    }
    
    /**
     * Créer un bloc liste
     */
    private function create_list_block($node, $is_ordered) {
        $items = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeName === 'li') {
                $items .= '<li>' . $this->get_inner_html($child) . '</li>';
            }
        }
        
        $tag = $is_ordered ? 'ol' : 'ul';
        
        return sprintf(
            '<!-- wp:list %s-->
<%s>%s</%s>
<!-- /wp:list -->',
            $is_ordered ? '{"ordered":true} ' : '',
            $tag,
            $items,
            $tag
        );
    }
    
    /**
     * Créer un bloc citation
     */
    private function create_quote_block($node) {
        $content = $this->get_inner_html($node);
        return sprintf(
            '<!-- wp:quote -->
<blockquote class="wp-block-quote"><p>%s</p></blockquote>
<!-- /wp:quote -->',
            $content
        );
    }
    
    /**
     * Créer un bloc code
     */
    private function create_code_block($node) {
        $code = '';
        if ($node->firstChild && $node->firstChild->nodeName === 'code') {
            $code = htmlspecialchars($node->firstChild->textContent);
        } else {
            $code = htmlspecialchars($node->textContent);
        }
        
        return sprintf(
            '<!-- wp:code -->
<pre class="wp-block-code"><code>%s</code></pre>
<!-- /wp:code -->',
            $code
        );
    }
    
    /**
     * Créer un bloc image
     */
    private function create_image_block($node) {
        $src = $node->getAttribute('src');
        $alt = $node->getAttribute('alt');
        
        return sprintf(
            '<!-- wp:image -->
<figure class="wp-block-image"><img src="%s" alt="%s"/></figure>
<!-- /wp:image -->',
            esc_url($src),
            esc_attr($alt)
        );
    }
    
    /**
     * Créer un bloc HTML personnalisé (fallback)
     */
    private function create_html_block($node) {
        $html = $this->get_outer_html($node);
        return sprintf(
            '<!-- wp:html -->
%s
<!-- /wp:html -->',
            $html
        );
    }
    
    /**
     * Créer un bloc séparateur
     */
    private function create_separator_block() {
        return '<!-- wp:separator -->
<hr class="wp-block-separator has-alpha-channel-opacity"/>
<!-- /wp:separator -->';
    }
    
    /**
     * Créer un bloc tableau
     */
    private function create_table_block($node) {
        $html = $this->get_outer_html($node);
        return sprintf(
            '<!-- wp:table -->
<figure class="wp-block-table">%s</figure>
<!-- /wp:table -->',
            $html
        );
    }
    
    /**
     * Obtenir le HTML interne d'un nœud
     */
    private function get_inner_html($node) {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }
        return $html;
    }
    
    /**
     * Obtenir le HTML externe d'un nœud
     */
    private function get_outer_html($node) {
        return $node->ownerDocument->saveHTML($node);
    }
}
