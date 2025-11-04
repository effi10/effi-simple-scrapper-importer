# effiSimplyScrapperImporter

Plugin WordPress professionnel pour importer des contenus Markdown issus de SimplyScrapper avec conversion automatique en blocs Gutenberg natifs.

## 📋 Fonctionnalités

- ✅ Import par lot de fichiers Markdown (.md)
- ✅ Conversion automatique en blocs Gutenberg natifs (pas de bloc HTML personnalisé)
- ✅ Extraction intelligente du titre et du slug depuis les métadonnées SimplyScrapper
- ✅ **Rattachement automatique des images à la une par numérotation**
- ✅ Traitement asynchrone via AJAX avec barre de progression en temps réel
- ✅ Gestion optimale de la mémoire (traitement séquentiel)
- ✅ Choix du statut des articles (Brouillon ou Publié)
- ✅ Interface administrateur intuitive et responsive
- ✅ Gestion complète des erreurs avec rapports détaillés

## 📦 Structure du Plugin

```
effiSimplyScrapperImporter/
├── effiSimplyScrapperImporter.php    # Fichier principal
├── README.md                          # Documentation
├── assets/
│   ├── css/
│   │   └── admin.css                 # Styles de l'interface
│   └── js/
│       └── admin.js                  # Script AJAX et progression
├── includes/
│   ├── class-parsedown-loader.php    # Chargeur Parsedown
│   ├── class-markdown-parser.php     # Parser de fichiers MD
│   ├── class-gutenberg-converter.php # Convertisseur en blocs
│   └── class-importer.php            # Gestionnaire d'import
└── vendor/
    └── parsedown/
        └── Parsedown.php             # Bibliothèque Parsedown
```


## 📖 Utilisation

### Format des Fichiers Markdown

Les fichiers `.md` doivent respecter le format SimplyScrapper (et doivent être encodés en UTF 8):

```markdown
# [Titre de l'article](https://example.com/blog/slug-article-n105)
_https://example.com/blog/slug-article-n105_

Le contenu commence ici...

## Section 1
Contenu de la section...
```

**Important :**
- **Ligne 1** : Titre entre crochets avec lien
- **Ligne 2** : URL complète (utilisée pour extraire le slug)
- **Ligne 3+** : Contenu Markdown standard

### Processus d'Import

1. **Accédez à l'importeur :**
   - Outils > SimplyScrapper Importer

2. **Sélectionnez vos fichiers :**
   - Cliquez sur le sélecteur de fichiers
   - Choisissez un ou plusieurs fichiers `.md`
   - Le nombre de fichiers sélectionnés s'affiche

3. **Choisissez le statut :**
   - **Brouillon** : Pour révision avant publication
   - **Publié** : Publication immédiate

4. **Rattachement des images (optionnel) :**
   - ✅ Cochez "Rattacher les photos par numérotation" pour activer le rattachement automatique
   - Le système cherchera une image nommée avec le numéro du slug (ex: `123.jpg` pour `article-c123`)
   - Voir la documentation complète dans `FONCTIONNALITE-RATTACHEMENT-IMAGES.md`

5. **Lancez l'import :**
   - Cliquez sur "Importer"
   - Une barre de progression affiche l'avancement en temps réel
   - Les résultats s'affichent à la fin (succès/échecs)

### Conversion en Blocs Gutenberg

Le plugin convertit automatiquement :

| Markdown | Bloc Gutenberg |
|----------|----------------|
| `# Titre` à `###### Titre` | Bloc Titre (niveaux 1-6) |
| Paragraphes | Bloc Paragraphe |
| `- Liste` / `* Liste` | Bloc Liste à puces |
| `1. Liste` | Bloc Liste numérotée |
| `> Citation` | Bloc Citation |
| `` `code` `` / ```code``` | Bloc Code |
| `![alt](url)` | Bloc Image |
| Liens `[texte](url)` | Liens natifs dans les blocs |

**Tous les blocs sont natifs Gutenberg**, pas de blocs HTML personnalisés !

## ⚙️ Architecture Technique

### Gestion de la Mémoire

Le plugin utilise un **traitement séquentiel** pour éviter les dépassements de mémoire :

1. Les fichiers sont envoyés **un par un** au serveur via AJAX
2. Chaque fichier est traité complètement avant de passer au suivant
3. Aucun stockage massif en mémoire

### Performance

- **Fichiers de 5 Ko** : ~0.5 seconde par fichier
- **100 fichiers** : ~50 secondes d'import total
- **Aucun timeout** grâce au traitement asynchrone
- **Timeout par fichier** : 60 secondes (configurable dans `admin.js`)

### Sécurité

- ✅ Vérification des nonces AJAX
- ✅ Vérification des capacités utilisateur (`manage_options`)
- ✅ Validation des extensions de fichiers (`.md` uniquement)
- ✅ Échappement de toutes les sorties HTML
- ✅ Protection contre l'accès direct aux fichiers PHP

## 🔧 Configuration Avancée

### Modifier le Timeout AJAX

Dans `assets/js/admin.js`, ligne 137 :

```javascript
timeout: 60000, // 60 secondes (60000 ms)
```

### Personnaliser les Types de Blocs

Modifiez `includes/class-gutenberg-converter.php`, méthode `node_to_block()` pour ajouter des conversions personnalisées.

### Ajouter des Métadonnées

Dans `includes/class-importer.php`, méthode `create_post()` :

```php
// Ajouter des termes de taxonomie
wp_set_post_terms($post_id, array('Non classé'), 'category');

// Ajouter des meta données personnalisées
update_post_meta($post_id, 'imported_from', 'SimplyScrapper');
update_post_meta($post_id, 'import_date', current_time('mysql'));
```

## 🐛 Dépannage

### Erreur : "Parsedown non trouvé"

**Solution :** Vérifiez que `vendor/parsedown/Parsedown.php` existe.

### Erreur : "Format de fichier invalide"

**Solution :** Assurez-vous que vos fichiers `.md` respectent le format avec les 2 premières lignes de métadonnées.

### Import qui se bloque

**Solution :** 
1. Vérifiez les logs PHP (erreurs de mémoire)
2. Augmentez `memory_limit` dans `php.ini` (recommandé : 256M)
3. Réduisez le nombre de fichiers par lot

### Blocs mal formatés

**Solution :** Le contenu Markdown peut contenir des balises HTML non standard. Vérifiez vos fichiers sources.

### Erreur d'importation

**Solution :** Vérifier que les fichiers à importer soient bien encodés en UTF 8 (typiquement l'UTF 16 provoque des anomalies/erreurs de type "titre trop long" et importe uniquement le premier bloc avec un espace entre chaque caractère)

## 📝 Changelog

### Version 1.0.2 (2025-10-28)
- ✨ **Nouvelle fonctionnalité** : Rattachement automatique des images à la une
  - Ajout d'une case à cocher "Rattacher les photos par numérotation"
  - Extraction automatique du numéro depuis le slug (ex: `article-c123` → `123`)
  - Recherche d'image dans la bibliothèque média par numéro (ex: `123.jpg`)
  - Support de 5 formats d'image (jpg, jpeg, png, gif, webp)
  - Définition automatique comme image à la une si trouvée
- 📄 Documentation complète dans `FONCTIONNALITE-RATTACHEMENT-IMAGES.md`

### Version 1.0.1 (2025-10-28)
- 🐛 **Correction majeure** : Import incomplet du contenu résolu
  - Amélioration du parsing DOM pour capturer tous les éléments HTML
  - Ajout de la gestion des nœuds texte orphelins
  - Enveloppe HTML pour une structure cohérente
- 🐛 **Correction** : Préservation exacte du slug d'origine
  - Le slug WordPress correspond maintenant exactement au slug de l'URL d'origine
  - Suppression de la logique de troncature des suffixes `-nXXX`
- ✨ **Améliorations** :
  - Support des séparateurs (`<hr>`)
  - Support des tableaux (`<table>`)
- 📄 Documentation complète des corrections dans `CORRECTIONS.md`
- 🧪 Fichiers de test fournis : `test-article.md` et `test-slug-exact.md`

### Version 1.0.0 (2025-10-22)
- ✨ Version initiale
- ✅ Import par lot avec AJAX
- ✅ Conversion en blocs Gutenberg natifs
- ✅ Interface administrateur complète
- ✅ Barre de progression temps réel

## 🤝 Support

Pour toute question ou problème :
1. Vérifiez cette documentation
2. Consultez les logs WordPress (`wp-content/debug.log`)
3. Contactez le support technique

## 📄 Licence

Ce plugin est sous licence GPL v2 ou ultérieure.

## 👨‍💻 Développeur

Développé avec ❤️ pour faciliter la migration de contenus SimplyScrapper vers WordPress.

---

**Note importante :** Ce plugin a été conçu spécifiquement pour le format de sortie de SimplyScrapper. Si votre format diffère, des adaptations peuvent être nécessaires dans `class-markdown-parser.php`.