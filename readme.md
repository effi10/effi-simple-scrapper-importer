# effiSimplyScrapperImporter

Extension WordPress qui transforme des fichiers Markdown exportés par l'outil de scraping SimplyScrapper en articles WordPress composés de blocs Gutenberg natifs. Le slug d'origine est conservé, l'image à la une peut être retrouvée par son numéro et les images peuvent, si vous le souhaitez, être copiées dans la médiathèque.

- **Auteur :** [Cédric Girard](https://www.effi10.com/) (effi10)
- **Version :** 1.1.0
- **Prérequis :** WordPress 6.6 ou plus récent, PHP 8.1 ou plus récent (testé avec PHP 8.2, 8.3 et 8.5)
- **Licence :** GPL-2.0-or-later

> **Droits d'auteur :** n'importez que des contenus et des images que vous avez le droit de réutiliser (vos propres sites, contenus sous licence compatible ou avec l'autorisation écrite de leur auteur). L'extension ne vérifie pas ces droits à votre place.

## À quoi sert cette extension

Elle sert à reprendre rapidement des pages déjà rédigées (migration d'un ancien site vers WordPress, consolidation de plusieurs sites, récupération de vos propres contenus) sans copier-coller ni retouche manuelle des blocs :

- chaque fichier `.md` devient un article ;
- le titre et le slug d'origine sont repris tels quels, ce qui facilite la conservation des URL et les redirections ;
- le contenu est découpé en blocs natifs (titres, paragraphes, listes, citations, code, tableaux, images, séparateurs) et reste donc entièrement modifiable dans l'éditeur.

## Fonctionnalités

- Import de plusieurs fichiers en une fois, traités **l'un après l'autre** (une requête par fichier, avec barre de progression) pour éviter les dépassements de mémoire et de délai.
- Conversion en blocs natifs conformes au balisage de l'éditeur de WordPress 6.6 et suivants.
- Statut au choix : **brouillon (par défaut)**, en attente de relecture ou publié.
- **Détection des doublons de slug** : par défaut le fichier est ignoré et signalé ; vous pouvez aussi choisir de l'importer quand même (WordPress ajoutera alors un suffixe au slug lors de la publication).
- **Image à la une par numérotation** (facultatif) : `mon-article-c123` utilise l'image `123.jpg` déjà présente dans la médiathèque.
- **Import des images dans la médiathèque** (facultatif, désactivé par défaut).
- Compte rendu détaillé : articles créés (avec lien de modification), fichiers ignorés, échecs et avertissements.

## Format attendu des fichiers

Les fichiers doivent avoir l'extension `.md`, être encodés en **UTF-8** et peser au plus 2 Mo (limite modifiable, voir plus bas). Les fichiers UTF-16 avec BOM (« Unicode » du Bloc-notes de Windows) sont convertis automatiquement ; les autres encodages sont refusés avec un message explicite.

```markdown
# [Titre de l'article](https://example.com/blog/slug-article-n105)
_https://example.com/blog/slug-article-n105_

Le contenu commence ici...

## Section 1
Contenu de la section...
```

- **Ligne 1 :** le titre, sous la forme `# [Titre](URL)` (la forme `# Titre` est aussi acceptée).
- **Ligne 2 :** l'URL complète de la page source, entre tirets bas. Son dernier segment devient le slug (`slug-article-n105`). La chaîne de requête, l'ancre, une barre oblique finale et une extension `.html`, `.htm`, `.php`, `.asp` ou `.aspx` sont ignorées. Si cette ligne est absente, le lien du titre est utilisé, sinon le slug est calculé à partir du titre.
- **Lignes suivantes :** le contenu, en Markdown standard.

Un exemple complet et fictif est fourni dans [`examples/exemple-article.md`](examples/exemple-article.md).

## Installation

1. Téléchargez l'archive depuis GitHub (bouton **Code > Download ZIP** ou page des versions).
2. Dans WordPress : **Extensions > Ajouter > Téléverser une extension**, choisissez l'archive, puis activez l'extension.
   Vous pouvez aussi copier le dossier dans `wp-content/plugins/`.
3. Si le dossier s'appelle `effi-simple-scrapper-importer-main` (archive GitHub), renommez-le de préférence en `effi-simple-scrapper-importer` avant l'activation.

Les mises à jour se font manuellement depuis GitHub : l'en-tête `Update URI` empêche WordPress.org de proposer par erreur la mise à jour d'une autre extension portant le même nom de dossier.

## Utilisation

1. Ouvrez **Outils > SimplyScrapper Importer** (réservé par défaut aux administrateurs).
2. Sélectionnez un ou plusieurs fichiers `.md`.
3. Choisissez les options :
   - **Statut des articles :** brouillon (recommandé, pour relire avant publication), en attente de relecture ou publié ;
   - **Si le slug existe déjà :** ignorer le fichier (recommandé) ou l'importer quand même ;
   - **Rattacher l'image à la une par numérotation :** si le slug se termine par un numéro (`-123`, `-c123`, `-n123`...), l'image dont le nom de fichier est exactement `123.jpg`, `123.jpeg`, `123.png`, `123.gif`, `123.webp` ou `123.avif` (téléversée au préalable dans la médiathèque) devient l'image à la une ;
   - **Importer les images dans la médiathèque :** voir « Images » ci-dessous.
4. Cliquez sur **Importer** et suivez la progression. Le compte rendu final liste chaque fichier.

## Conversion en blocs

| Markdown | Bloc WordPress |
|----------|----------------|
| `# Titre` à `###### Titre` | Titre (niveaux 1 à 6) |
| Paragraphes, **gras**, *italique*, `code en ligne`, liens | Paragraphe |
| `- élément` / `1. élément`, y compris imbriqués | Liste (avec éléments de liste) |
| `> citation` | Citation |
| Bloc de code entre ```` ``` ```` | Code |
| Tableau avec `\|` (alignements compris) | Tableau |
| `![texte alternatif](url)` seul sur sa ligne | Image (avec lien éventuel) |
| `---` | Séparateur |
| `<div>` et conteneurs HTML simples | Contenu converti en blocs |
| Autre HTML brut (vidéo, formulaire...) | HTML personnalisé (s'il survit au filtrage) |

Le Markdown est interprété par [Parsedown](https://github.com/erusev/parsedown) 1.6.0, embarquée sous un nom de classe propre à l'extension (`EFFISSI_Parsedown`) pour ne jamais entrer en conflit avec une autre extension qui charge sa propre version de Parsedown.

## Images

- **Option décochée (par défaut) :** les images restent chargées depuis le site source. Elles disparaîtront si ce site les supprime, change d'adresse ou bloque le chargement depuis un autre domaine. Les images relatives (`/images/photo.jpg`) sont transformées en adresses absolues vers le site source.
- **Option cochée :** les images sont téléchargées et ajoutées à la médiathèque, rattachées à l'article, puis le contenu pointe vers la copie locale. Garde-fous :
  - adresses `http`/`https` uniquement, téléchargées via l'API HTTP de WordPress (adresses locales et privées refusées) ;
  - 10 images au plus par article et 5 Mo au plus par image (plafonnés par la limite de téléversement du serveur) ;
  - type réel vérifié sur le contenu du fichier : JPEG, PNG, GIF, WebP ou AVIF (le SVG est refusé) ;
  - une image déjà importée depuis la même adresse est réutilisée au lieu d'être dupliquée ;
  - les images refusées ou en échec restent liées au site source et sont signalées dans le compte rendu.
- Les images servant d'image à la une par numérotation doivent être téléversées vous-même dans la médiathèque avant l'import.

## Sécurité

- Page et requêtes d'import réservées aux utilisateurs ayant la capacité `manage_options` (modifiable par filtre) ; nonce vérifié à chaque requête.
- Fichiers téléversés contrôlés : téléversement réel, extension `.md`, taille, type MIME réel de type texte, encodage UTF-8. Le fichier est lu depuis le dossier temporaire de PHP, jamais copié dans un dossier public, puis supprimé.
- Le contenu provenant d'un site tiers est **toujours filtré par `wp_kses_post()`**, y compris pour les administrateurs : scripts, styles, iframes, attributs d'événement et liens `javascript:` sont supprimés. Un développeur peut lever ce filtrage pour les utilisateurs disposant de `unfiltered_html` (filtre `effissi_allow_unfiltered_html`). Les balises `<script>`, `<style>` et les commentaires HTML sont supprimés dans tous les cas.
- Les articles sont créés avec `wp_insert_post( wp_slash( $data ), true )` et les erreurs sont remontées dans le compte rendu.

## Limites

- Seul le type de contenu « article » (`post`) est créé ; catégories, étiquettes, auteur et date d'origine ne sont pas repris (l'article est attribué à l'utilisateur qui importe, catégorie par défaut, date du jour).
- Les liens présents dans le contenu pointent toujours vers le site source : pensez à les mettre à jour et à prévoir vos redirections.
- Syntaxe Markdown prise en charge : celle de Parsedown 1.6.0 (Markdown classique et tableaux). Les notes de bas de page, listes de tâches et autres extensions « Markdown Extra » ne sont pas converties.
- Un élément de liste ne peut pas contenir de bloc (code, citation) : seul son texte est conservé.
- Le format d'entrée est celui de SimplyScrapper : un fichier structuré autrement devra être adapté (voir `includes/class-markdown-parser.php`).
- Le balisage produit suit l'éditeur de WordPress 6.6 et suivants ; sur une version plus ancienne, l'éditeur peut proposer de « récupérer » certains blocs.
- Avec l'import des images, un fichier peut demander plusieurs dizaines de secondes : sur un hébergement limité, importez de petits lots.

## Pour les développeurs

| Crochet | Type | Rôle |
|---------|------|------|
| `effissi_required_capability` | filtre | Capacité requise (défaut : `manage_options`). |
| `effissi_max_file_size` | filtre | Taille maximale d'un fichier `.md`, en octets (défaut : 2 Mo). |
| `effissi_max_image_size` | filtre | Taille maximale d'une image importée, en octets (défaut : 5 Mo). |
| `effissi_max_images_per_post` | filtre | Nombre maximal d'images téléchargées par article (défaut : 10). |
| `effissi_allow_unfiltered_html` | filtre | `true` pour ne pas filtrer le HTML si l'utilisateur a `unfiltered_html` (défaut : `false`). |
| `effissi_post_data` | filtre | Données passées à `wp_insert_post()` (type de contenu, catégorie, etc.). |
| `effissi_post_imported` | action | Après la création d'un article : `( $post_id, $data, $options )`. |

Exemple : ajouter une méta et une catégorie à chaque article importé.

```php
add_action( 'effissi_post_imported', function ( $post_id, $data ) {
	update_post_meta( $post_id, 'source_url', esc_url_raw( $data['source_url'] ) );
	wp_set_post_categories( $post_id, array( 12 ), true );
}, 10, 2 );
```

## Désinstallation

L'extension n'enregistre aucune option. À la suppression, elle efface seulement la méta technique `_effissi_source_url` des images importées (les images et les articles sont conservés) et le dossier vide `uploads/effisimplyscrapper` créé par les versions 1.0.x.

## Note sur le nom

Le nom n'est pas homogène : dépôt `effi-simple-scrapper-importer`, fichier principal `effi-simply-scrapper-importer.php`, nom affiché `effiSimplyScrapperImporter`, outil source « SimplyScrapper » (en anglais, « scraper » s'écrit avec un seul p). Les fichiers et dossiers ne sont volontairement pas renommés : WordPress identifie une extension par son chemin `dossier/fichier-principal.php`, et un renommage la désactiverait sur les sites où elle est déjà installée.

## Crédits et licence

- Extension distribuée sous licence GPL version 2 ou ultérieure : voir [`LICENSE`](LICENSE).
- Parsedown 1.6.0, © Emanuil Rusev, licence MIT : voir [`vendor/parsedown/LICENSE.txt`](vendor/parsedown/LICENSE.txt). Les modifications apportées à cette copie (renommage de la classe, compatibilité PHP 8.4+) sont décrites en tête de `vendor/parsedown/Parsedown.php`.

## Journal des versions

### 1.1.0 (2026-10-24)

- **Correction critique :** la copie de Parsedown déclarait la classe globale `Parsedown` en se présentant comme la version 1.7.4 ; une erreur fatale survenait si une autre extension chargeait Parsedown. Il s'agissait en fait d'une réécriture simplifiée et défaillante (images transformées en liens, blocs de code et tableaux non reconnus, listes imbriquées aplaties, avertissements PHP 8). Elle est remplacée par Parsedown 1.6.0 sous le nom préfixé `EFFISSI_Parsedown`, avec sa licence MIT.
- Conversion en blocs réécrite : listes avec blocs « élément de liste » et listes imbriquées, citations avec blocs internes, tableaux natifs avec alignements, images avec lien et légende, codes courts neutralisés dans les blocs de code, images relatives rendues absolues.
- Sécurité : contenu filtré par `wp_kses_post()`, scripts et commentaires HTML supprimés, contrôles complets du fichier téléversé (type MIME réel, taille, encodage), fichier temporaire supprimé, `wp_slash()` avant `wp_insert_post()` (les antislashs du contenu sont préservés), statut par défaut « brouillon » imposé côté serveur.
- Nouveau : détection et signalement des doublons de slug, statut « en attente de relecture », import facultatif des images dans la médiathèque, compte rendu détaillé avec liens de modification.
- Image à la une par numérotation : recherche exacte du nom de fichier (`123.jpg` ne correspond plus à `4123.jpg`), prise en charge des images « -scaled » et du format AVIF.
- Encodage : BOM UTF-8 retiré, UTF-16 avec BOM converti, fichiers non UTF-8 refusés avec un message clair.
- Le dossier vide `uploads/effisimplyscrapper` n'est plus créé à l'activation ; ajout de `uninstall.php`.
- Textes de l'interface traduisibles (domaine `effi-simple-scrapper-importer`), en-tête d'extension complet, fichier `LICENSE`, README réécrit.
- Exemple de test remplacé par un exemple fictif (`examples/exemple-article.md`).

### 1.0.2 (2025-10-28)

- Rattachement automatique de l'image à la une par numérotation du slug.

### 1.0.1 (2025-10-28)

- Correction de l'import incomplet du contenu (analyse DOM, nœuds texte orphelins).
- Conservation exacte du slug d'origine.
- Prise en charge des séparateurs et des tableaux.

### 1.0.0 (2025-10-22)

- Version initiale : import par lot avec AJAX, conversion en blocs, barre de progression.
