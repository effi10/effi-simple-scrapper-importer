<?php
/**
 * Conversion du Markdown (via Parsedown) en blocs Gutenberg natifs.
 *
 * Le balisage produit suit celui qu'enregistre l'éditeur de blocs de
 * WordPress 6.6 et suivants (listes avec blocs « list-item », citations avec
 * blocs internes, classes wp-block-*) afin que l'éditeur n'affiche pas
 * d'avertissement « contenu inattendu ».
 *
 * @package EffiSimplyScrapperImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFFISSI_Gutenberg_Converter {

	/**
	 * Éléments de texte regroupés dans un même paragraphe lorsqu'ils
	 * apparaissent hors d'un bloc (HTML brut dans le Markdown).
	 */
	const INLINE_TAGS = array(
		'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'data', 'del', 'dfn', 'em', 'i', 'ins',
		'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strike', 'strong', 'sub', 'sup', 'time',
		'u', 'var', 'wbr',
	);

	/** Conteneurs HTML dont on convertit simplement le contenu. */
	const CONTAINER_TAGS = array( 'div', 'section', 'article', 'main', 'header', 'footer', 'aside', 'center' );

	/** Éléments supprimés avec leur contenu : ce ne sont jamais du contenu éditorial. */
	const REMOVED_TAGS = array( 'script', 'style', 'template' );

	/**
	 * Fonction appelée pour chaque image : function( string $src, string $alt ): ?array
	 * qui retourne array( 'id' => int, 'url' => string ) pour une image importée.
	 *
	 * @var callable|null
	 */
	private $image_resolver;

	/** @var string URL de la page source, pour rendre absolues les images relatives. */
	private $base_url = '';

	/** @var DOMDocument|null */
	private $dom;

	/** @var SplObjectStorage Images importées dans la médiathèque => ID de la pièce jointe. */
	private $attachment_ids;

	/**
	 * @param callable|null $image_resolver Voir la propriété $image_resolver.
	 */
	public function __construct( $image_resolver = null ) {
		$this->image_resolver = is_callable( $image_resolver ) ? $image_resolver : null;
		$this->attachment_ids = new SplObjectStorage();
	}

	/**
	 * Convertit du Markdown en contenu de blocs.
	 *
	 * @param string $markdown Contenu Markdown.
	 * @param string $base_url URL de la page source (facultatif).
	 * @param bool   $sanitize Filtrer le HTML avec wp_kses_post() avant la conversion.
	 * @return string Contenu sérialisé en blocs.
	 */
	public function convert_to_blocks( $markdown, $base_url = '', $sanitize = true ) {
		$html = EFFISSI_Parsedown_Loader::get_parsedown()->text( (string) $markdown );

		return $this->html_to_blocks( $html, $base_url, $sanitize );
	}

	/**
	 * Convertit du HTML en contenu de blocs.
	 *
	 * @param string $html     HTML.
	 * @param string $base_url URL de la page source (facultatif).
	 * @param bool   $sanitize Filtrer le HTML avec wp_kses_post() avant la conversion.
	 * @return string Contenu sérialisé en blocs.
	 */
	public function html_to_blocks( $html, $base_url = '', $sanitize = true ) {
		$this->base_url       = (string) $base_url;
		$this->attachment_ids = new SplObjectStorage();

		$body   = $this->load_body( (string) $html );
		$blocks = array();

		if ( null !== $body ) {
			$this->clean_tree( $body );

			// Filtrage après suppression des scripts (wp_kses_post() garderait leur texte),
			// et avant la conversion pour ne pas laisser de blocs vides.
			if ( $sanitize ) {
				$body = $this->load_body( wp_kses_post( $this->inner_html( $body ) ) );
			}
		}

		if ( null !== $body ) {
			$this->prepare_images( $body );
			$blocks = $this->convert_children( $body );
		}

		$this->dom            = null;
		$this->attachment_ids = new SplObjectStorage();

		return implode( "\n\n", $blocks );
	}

	/**
	 * Charge le HTML dans un document DOM et retourne l'élément body.
	 *
	 * @param string $html HTML.
	 * @return DOMElement|null
	 */
	private function load_body( $html ) {
		if ( '' === trim( $html ) ) {
			return null;
		}

		$dom      = new DOMDocument( '1.0', 'UTF-8' );
		$previous = libxml_use_internal_errors( true );
		$loaded   = $dom->loadHTML(
			'<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html . '</body></html>',
			LIBXML_NONET
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return null;
		}

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );

		if ( ! $body instanceof DOMElement ) {
			return null;
		}

		$this->dom = $dom;

		return $body;
	}

	/**
	 * Supprime les commentaires HTML (qui pourraient imiter des délimiteurs de
	 * blocs) et les éléments non éditoriaux (script, style...).
	 *
	 * @param DOMElement $root Racine.
	 */
	private function clean_tree( DOMElement $root ) {
		$xpath = new DOMXPath( $this->dom );
		$nodes = array();

		foreach ( $xpath->query( './/comment()', $root ) as $node ) {
			$nodes[] = $node;
		}

		foreach ( self::REMOVED_TAGS as $tag ) {
			foreach ( $root->getElementsByTagName( $tag ) as $node ) {
				$nodes[] = $node;
			}
		}

		// Images dont l'adresse n'est ni http(s) ni relative (data:, javascript:, file:...).
		foreach ( $root->getElementsByTagName( 'img' ) as $img ) {
			$src = trim( $img->getAttribute( 'src' ) );

			if ( '' === $src || ( preg_match( '#^([a-z][a-z0-9+.\-]*):#i', $src, $matches ) && ! in_array( strtolower( $matches[1] ), array( 'http', 'https' ), true ) ) ) {
				$nodes[] = $img;
			}
		}

		foreach ( $nodes as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}
	}

	/**
	 * Prépare les images : URL absolues, suppression des indices propres au
	 * site source (srcset, classes wp-image-N), import éventuel dans la
	 * médiathèque.
	 *
	 * @param DOMElement $root Racine.
	 */
	private function prepare_images( DOMElement $root ) {
		$images = array();

		foreach ( $root->getElementsByTagName( 'img' ) as $img ) {
			$images[] = $img;
		}

		foreach ( $images as $img ) {
			$img->removeAttribute( 'srcset' );
			$img->removeAttribute( 'sizes' );
			$this->set_class( $img, (string) preg_replace( '/(^|\s)wp-image-\d+(?=\s|$)/', ' ', $img->getAttribute( 'class' ) ) );

			$src = $this->absolute_url( trim( $img->getAttribute( 'src' ) ) );

			if ( '' === $src ) {
				continue;
			}

			$img->setAttribute( 'src', $src );

			if ( null === $this->image_resolver ) {
				continue;
			}

			$result = call_user_func( $this->image_resolver, $src, (string) $img->getAttribute( 'alt' ) );

			if ( ! is_array( $result ) || empty( $result['id'] ) || empty( $result['url'] ) ) {
				continue;
			}

			$attachment_id = (int) $result['id'];

			$img->setAttribute( 'src', (string) $result['url'] );
			$this->set_class( $img, $img->getAttribute( 'class' ) . ' wp-image-' . $attachment_id );
			$this->attachment_ids[ $img ] = $attachment_id;
		}
	}

	/**
	 * Convertit les enfants d'un nœud en liste de blocs.
	 *
	 * @param DOMNode $parent Nœud parent.
	 * @return string[] Blocs sérialisés.
	 */
	private function convert_children( DOMNode $parent ) {
		$blocks = array();
		$inline = '';

		foreach ( iterator_to_array( $parent->childNodes ) as $node ) {
			if ( $this->is_inline_node( $node ) ) {
				$inline .= $this->dom->saveHTML( $node );
				continue;
			}

			$this->flush_inline( $inline, $blocks );

			foreach ( $this->node_to_blocks( $node ) as $block ) {
				$blocks[] = $block;
			}
		}

		$this->flush_inline( $inline, $blocks );

		return $blocks;
	}

	/**
	 * Convertit un nœud en zéro, un ou plusieurs blocs.
	 *
	 * @param DOMNode $node Nœud.
	 * @return string[]
	 */
	private function node_to_blocks( DOMNode $node ) {
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return array();
		}

		$tag = strtolower( $node->nodeName );

		switch ( $tag ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				return $this->wrap( $this->heading_block( $node, (int) substr( $tag, 1 ) ) );
			case 'p':
				return $this->wrap( $this->paragraph_or_image_block( $node ) );
			case 'ul':
			case 'ol':
				return $this->wrap( $this->list_block( $node ) );
			case 'blockquote':
				return $this->wrap( $this->quote_block( $node ) );
			case 'pre':
				return $this->wrap( $this->code_block( $node ) );
			case 'img':
				return $this->wrap( $this->image_block( $node ) );
			case 'figure':
				return $this->wrap( $this->figure_block( $node ) );
			case 'hr':
				return array( $this->block( 'core/separator', array(), '<hr class="wp-block-separator has-alpha-channel-opacity"/>' ) );
			case 'table':
				return $this->wrap( $this->table_block( $node ) );
		}

		if ( in_array( $tag, self::CONTAINER_TAGS, true ) ) {
			return $this->convert_children( $node );
		}

		// Repli : HTML brut (vidéo, iframe, formulaire...) dans un bloc HTML personnalisé.
		return $this->wrap( $this->html_block( $node ) );
	}

	/**
	 * Bloc titre.
	 *
	 * @param DOMElement $node  Élément h1-h6.
	 * @param int        $level Niveau.
	 * @return string
	 */
	private function heading_block( DOMElement $node, $level ) {
		$content = $this->normalize_inline( $this->inner_html( $node ) );

		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			return '';
		}

		$attrs = 2 === $level ? array() : array( 'level' => $level );

		return $this->block( 'core/heading', $attrs, sprintf( '<h%1$d class="wp-block-heading">%2$s</h%1$d>', $level, $content ) );
	}

	/**
	 * Paragraphe, ou bloc image si le paragraphe ne contient qu'une image
	 * (éventuellement entourée d'un lien).
	 *
	 * @param DOMElement $node Élément p.
	 * @return string
	 */
	private function paragraph_or_image_block( DOMElement $node ) {
		$children = $this->meaningful_children( $node );

		if ( 1 === count( $children ) && $children[0] instanceof DOMElement ) {
			$only = $children[0];
			$name = strtolower( $only->nodeName );

			if ( 'img' === $name ) {
				return $this->image_block( $only );
			}

			if ( 'a' === $name ) {
				$inner = $this->meaningful_children( $only );

				if ( 1 === count( $inner ) && $inner[0] instanceof DOMElement && 'img' === strtolower( $inner[0]->nodeName ) ) {
					return $this->image_block( $inner[0], $only->getAttribute( 'href' ) );
				}
			}
		}

		return $this->paragraph_from_html( $this->inner_html( $node ) );
	}

	/**
	 * Bloc paragraphe à partir de HTML en ligne.
	 *
	 * @param string $html HTML en ligne.
	 * @return string
	 */
	private function paragraph_from_html( $html ) {
		$content = $this->normalize_inline( $html );

		if ( '' === trim( wp_strip_all_tags( $content ) ) && false === stripos( $content, '<img' ) ) {
			return '';
		}

		return $this->block( 'core/paragraph', array(), '<p>' . $content . '</p>' );
	}

	/**
	 * Bloc liste (avec blocs internes « list-item », listes imbriquées comprises).
	 *
	 * @param DOMElement $list Élément ul ou ol.
	 * @return string
	 */
	private function list_block( DOMElement $list ) {
		$ordered  = 'ol' === strtolower( $list->nodeName );
		$attrs    = array();
		$tag_attr = '';

		if ( $ordered ) {
			$attrs['ordered'] = true;
			$start            = trim( $list->getAttribute( 'start' ) );

			if ( preg_match( '/^-?\d{1,9}$/', $start ) && 1 !== (int) $start ) {
				$attrs['start'] = (int) $start;
				$tag_attr       = ' start="' . (int) $start . '"';
			}
		}

		$items = array();

		foreach ( $list->childNodes as $child ) {
			if ( $child instanceof DOMElement && 'li' === strtolower( $child->nodeName ) ) {
				$item = $this->list_item_block( $child );

				if ( '' !== $item ) {
					$items[] = $item;
				}
			}
		}

		if ( ! $items ) {
			return '';
		}

		$tag = $ordered ? 'ol' : 'ul';

		return $this->block(
			'core/list',
			$attrs,
			sprintf( '<%1$s%2$s class="wp-block-list">%3$s</%1$s>', $tag, $tag_attr, implode( "\n\n", $items ) )
		);
	}

	/**
	 * Bloc élément de liste.
	 *
	 * @param DOMElement $li Élément li.
	 * @return string
	 */
	private function list_item_block( DOMElement $li ) {
		$content = '';
		$nested  = array();

		foreach ( $li->childNodes as $child ) {
			$name = $child instanceof DOMElement ? strtolower( $child->nodeName ) : '';

			if ( 'ul' === $name || 'ol' === $name ) {
				$list = $this->list_block( $child );

				if ( '' !== $list ) {
					$nested[] = $list;
				}
				continue;
			}

			if ( 'p' === $name ) {
				// Liste « aérée » : les paragraphes sont fusionnés, séparés par un saut de ligne.
				$part = $this->normalize_inline( $this->inner_html( $child ) );

				if ( '' !== $part ) {
					$content .= ( '' !== trim( $content ) ? '<br>' : '' ) . $part;
				}
				continue;
			}

			if ( '' !== $name && 'img' !== $name && ! in_array( $name, self::INLINE_TAGS, true ) ) {
				// Autre bloc (code, citation, tableau...) : impossible dans un élément de liste,
				// on garde son texte.
				$content .= ' ' . htmlspecialchars( trim( $child->textContent ), ENT_NOQUOTES, 'UTF-8' );
				continue;
			}

			$content .= $this->dom->saveHTML( $child );
		}

		$content = $this->normalize_inline( $content );

		if ( '' === $content && ! $nested ) {
			return '';
		}

		return $this->block( 'core/list-item', array(), '<li>' . $content . implode( "\n\n", $nested ) . '</li>' );
	}

	/**
	 * Bloc citation (le contenu devient des blocs internes).
	 *
	 * @param DOMElement $node Élément blockquote.
	 * @return string
	 */
	private function quote_block( DOMElement $node ) {
		$inner = $this->convert_children( $node );

		if ( ! $inner ) {
			return '';
		}

		return $this->block( 'core/quote', array(), '<blockquote class="wp-block-quote">' . implode( "\n\n", $inner ) . '</blockquote>' );
	}

	/**
	 * Bloc code.
	 *
	 * @param DOMElement $node Élément pre.
	 * @return string
	 */
	private function code_block( DOMElement $node ) {
		$code = rtrim( $node->textContent, "\n" );

		if ( '' === trim( $code ) ) {
			return '';
		}

		// Comme l'éditeur : « [ » est encodé pour qu'un code court ne soit jamais exécuté.
		$code = str_replace( '[', '&#91;', htmlspecialchars( $code, ENT_NOQUOTES, 'UTF-8' ) );

		return $this->block( 'core/code', array(), '<pre class="wp-block-code"><code>' . $code . '</code></pre>' );
	}

	/**
	 * Bloc image.
	 *
	 * @param DOMElement $img     Élément img.
	 * @param string     $href    Lien éventuel autour de l'image.
	 * @param string     $caption Légende éventuelle (HTML en ligne).
	 * @return string
	 */
	private function image_block( DOMElement $img, $href = '', $caption = '' ) {
		$src = esc_url( $img->getAttribute( 'src' ) );

		if ( '' === $src ) {
			return '';
		}

		$attachment_id = isset( $this->attachment_ids[ $img ] ) ? (int) $this->attachment_ids[ $img ] : 0;
		$attrs         = array();
		$figure_class  = 'wp-block-image';
		$image         = sprintf( '<img src="%s" alt="%s"', $src, esc_attr( $img->getAttribute( 'alt' ) ) );

		if ( $attachment_id ) {
			$attrs['id']       = $attachment_id;
			$attrs['sizeSlug'] = 'full';
			$figure_class     .= ' size-full';
			$image            .= sprintf( ' class="wp-image-%d"', $attachment_id );
		}

		$title = trim( $img->getAttribute( 'title' ) );

		if ( '' !== $title ) {
			$image .= sprintf( ' title="%s"', esc_attr( $title ) );
		}

		$image .= '/>';
		$href   = esc_url( (string) $href );

		if ( '' !== $href ) {
			$attrs['linkDestination'] = 'custom';
			$image                    = '<a href="' . $href . '">' . $image . '</a>';
		} elseif ( $attachment_id ) {
			$attrs['linkDestination'] = 'none';
		}

		$caption = trim( (string) $caption );

		if ( '' !== $caption ) {
			$image .= '<figcaption class="wp-element-caption">' . $caption . '</figcaption>';
		}

		return $this->block( 'core/image', $attrs, '<figure class="' . $figure_class . '">' . $image . '</figure>' );
	}

	/**
	 * Élément figure contenant une image : bloc image avec légende.
	 *
	 * @param DOMElement $figure Élément figure.
	 * @return string
	 */
	private function figure_block( DOMElement $figure ) {
		$images = $figure->getElementsByTagName( 'img' );

		if ( 1 !== $images->length ) {
			return $this->html_block( $figure );
		}

		$img    = $images->item( 0 );
		$parent = $img->parentNode;
		$href   = ( $parent instanceof DOMElement && 'a' === strtolower( $parent->nodeName ) ) ? $parent->getAttribute( 'href' ) : '';

		$caption  = '';
		$captions = $figure->getElementsByTagName( 'figcaption' );

		if ( $captions->length ) {
			$caption = $this->normalize_inline( $this->inner_html( $captions->item( 0 ) ) );
		}

		return $this->image_block( $img, $href, $caption );
	}

	/**
	 * Bloc tableau.
	 *
	 * @param DOMElement $table Élément table.
	 * @return string
	 */
	private function table_block( DOMElement $table ) {
		$sections = array(
			'thead' => array(),
			'tbody' => array(),
			'tfoot' => array(),
		);
		$caption  = '';

		foreach ( $table->childNodes as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$name = strtolower( $child->nodeName );

			if ( isset( $sections[ $name ] ) ) {
				foreach ( $child->childNodes as $row ) {
					if ( $row instanceof DOMElement && 'tr' === strtolower( $row->nodeName ) ) {
						$row_html = $this->table_row( $row );

						if ( '' !== $row_html ) {
							$sections[ $name ][] = $row_html;
						}
					}
				}
			} elseif ( 'tr' === $name ) {
				$row_html = $this->table_row( $child );

				if ( '' !== $row_html ) {
					$sections['tbody'][] = $row_html;
				}
			} elseif ( 'caption' === $name ) {
				$caption = $this->normalize_inline( $this->inner_html( $child ) );
			}
		}

		$html = '';

		foreach ( $sections as $name => $rows ) {
			if ( $rows ) {
				$html .= '<' . $name . '>' . implode( '', $rows ) . '</' . $name . '>';
			}
		}

		if ( '' === $html ) {
			return '';
		}

		$caption_html = '' !== $caption ? '<figcaption class="wp-element-caption">' . $caption . '</figcaption>' : '';

		return $this->block(
			'core/table',
			array(),
			'<figure class="wp-block-table"><table class="has-fixed-layout">' . $html . '</table>' . $caption_html . '</figure>'
		);
	}

	/**
	 * Ligne de tableau.
	 *
	 * @param DOMElement $tr Élément tr.
	 * @return string
	 */
	private function table_row( DOMElement $tr ) {
		$cells = '';

		foreach ( $tr->childNodes as $cell ) {
			if ( ! $cell instanceof DOMElement ) {
				continue;
			}

			$tag = strtolower( $cell->nodeName );

			if ( 'th' !== $tag && 'td' !== $tag ) {
				continue;
			}

			$attr  = '';
			$align = $this->cell_alignment( $cell );

			if ( '' !== $align ) {
				$attr .= sprintf( ' class="has-text-align-%1$s" data-align="%1$s"', $align );
			}

			if ( 'th' === $tag ) {
				$scope = strtolower( trim( $cell->getAttribute( 'scope' ) ) );

				if ( in_array( $scope, array( 'row', 'col', 'rowgroup', 'colgroup' ), true ) ) {
					$attr .= ' scope="' . $scope . '"';
				}
			}

			foreach ( array( 'colspan', 'rowspan' ) as $span ) {
				$value = (int) $cell->getAttribute( $span );

				if ( $value > 1 ) {
					$attr .= sprintf( ' %s="%d"', $span, $value );
				}
			}

			$cells .= '<' . $tag . $attr . '>' . $this->normalize_inline( $this->inner_html( $cell ) ) . '</' . $tag . '>';
		}

		return '' === $cells ? '' : '<tr>' . $cells . '</tr>';
	}

	/**
	 * Alignement d'une cellule (style text-align de Parsedown, data-align ou align).
	 *
	 * @param DOMElement $cell Cellule.
	 * @return string left, center, right ou chaîne vide.
	 */
	private function cell_alignment( DOMElement $cell ) {
		$align = strtolower( trim( $cell->getAttribute( 'data-align' ) ) );

		if ( '' === $align && preg_match( '/text-align\s*:\s*(left|center|right)/i', $cell->getAttribute( 'style' ), $matches ) ) {
			$align = strtolower( $matches[1] );
		}

		if ( '' === $align ) {
			$align = strtolower( trim( $cell->getAttribute( 'align' ) ) );
		}

		return in_array( $align, array( 'left', 'center', 'right' ), true ) ? $align : '';
	}

	/**
	 * Bloc HTML personnalisé (repli).
	 *
	 * @param DOMNode $node Nœud.
	 * @return string
	 */
	private function html_block( DOMNode $node ) {
		$html = trim( (string) $this->dom->saveHTML( $node ) );

		if ( '' === $html ) {
			return '';
		}

		return $this->block( 'core/html', array(), $html );
	}

	/**
	 * Sérialise un bloc avec ses délimiteurs (fonction du cœur de WordPress,
	 * qui encode correctement les attributs JSON).
	 *
	 * @param string $name  Nom du bloc.
	 * @param array  $attrs Attributs.
	 * @param string $html  HTML du bloc.
	 * @return string
	 */
	private function block( $name, array $attrs, $html ) {
		return get_comment_delimited_block_content( $name, $attrs, "\n" . $html . "\n" );
	}

	/**
	 * @param string $block Bloc sérialisé ou chaîne vide.
	 * @return string[]
	 */
	private function wrap( $block ) {
		return '' === $block ? array() : array( $block );
	}

	/**
	 * Ajoute le paragraphe en attente (texte et éléments en ligne isolés).
	 *
	 * @param string   $inline HTML en attente (vidé par la méthode).
	 * @param string[] $blocks Blocs.
	 */
	private function flush_inline( &$inline, array &$blocks ) {
		$block  = $this->paragraph_from_html( $inline );
		$inline = '';

		if ( '' !== $block ) {
			$blocks[] = $block;
		}
	}

	/**
	 * @param DOMNode $node Nœud.
	 * @return bool Vrai pour un texte ou un élément en ligne.
	 */
	private function is_inline_node( DOMNode $node ) {
		if ( in_array( $node->nodeType, array( XML_TEXT_NODE, XML_CDATA_SECTION_NODE, XML_ENTITY_REF_NODE ), true ) ) {
			return true;
		}

		return $node instanceof DOMElement && in_array( strtolower( $node->nodeName ), self::INLINE_TAGS, true );
	}

	/**
	 * Enfants significatifs (ni espaces seuls, ni br).
	 *
	 * @param DOMNode $node Nœud.
	 * @return DOMNode[]
	 */
	private function meaningful_children( DOMNode $node ) {
		$children = array();

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( $child->textContent ) ) {
				continue;
			}

			if ( $child instanceof DOMElement && 'br' === strtolower( $child->nodeName ) ) {
				continue;
			}

			$children[] = $child;
		}

		return $children;
	}

	/**
	 * HTML interne d'un nœud.
	 *
	 * @param DOMNode $node Nœud.
	 * @return string
	 */
	private function inner_html( DOMNode $node ) {
		$html = '';

		foreach ( $node->childNodes as $child ) {
			$html .= $this->dom->saveHTML( $child );
		}

		return $html;
	}

	/**
	 * Remplace les retours à la ligne du HTML en ligne par des espaces.
	 *
	 * @param string $html HTML en ligne.
	 * @return string
	 */
	private function normalize_inline( $html ) {
		return trim( (string) preg_replace( '/[ \t\r]*\n[ \t\r]*/', ' ', (string) $html ) );
	}

	/**
	 * Rend une URL d'image absolue à partir de l'URL de la page source.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function absolute_url( $url ) {
		if ( '' === $url || preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $url ) || '' === $this->base_url ) {
			return $url;
		}

		return WP_Http::make_absolute_url( $url, $this->base_url );
	}

	/**
	 * Définit (ou supprime si vide) l'attribut class.
	 *
	 * @param DOMElement $element Élément.
	 * @param string     $classes Classes.
	 */
	private function set_class( DOMElement $element, $classes ) {
		$classes = trim( (string) preg_replace( '/\s+/', ' ', (string) $classes ) );

		if ( '' === $classes ) {
			$element->removeAttribute( 'class' );
		} else {
			$element->setAttribute( 'class', $classes );
		}
	}
}
