# Traductions (`ps_onepagecheckout`)

Ce document décrit comment **ajouter**, **packager** et **utiliser** les traductions du module sous PrestaShop 9 (système Symfony / XLIFF).

## Domaines de traduction

| Contexte | Domaine recommandé | Exemple d’usage |
|----------|-------------------|-----------------|
| Chaînes propres au module (BO) | `Modules.Psonepagecheckout.Admin` | `displayName`, libellés d’onglet, textes du module |
| Chaînes du cœur PrestaShop (BO) | `Admin.Actions`, `Admin.Design.Feature`, `Admin.Notifications.*`, etc. | Boutons, titres, alertes déjà traduits par le core |

Le fichier principal du module utilise `Modules.Psonepagecheckout.Admin` (voir `ps_onepagecheckout.php`). Les écrans de configuration réutilisent souvent des domaines `Admin.*` pour rester alignés avec le back-office.

> **Cohérence** : gardez la même chaîne source (anglais) partout pour une même phrase, et le même domaine pour que les XLF correspondent.

## Où placer les fichiers

Les traductions packagées avec le module se trouvent sous :

```text
translations/
  fr-FR/
    ModulesPsonepagecheckoutAdmin.fr-FR.xlf
  en-US/
    ModulesPsonepagecheckoutAdmin.en-US.xlf
```

Un exemple minimal est fourni : [`translations/fr-FR/ModulesPsonepagecheckoutAdmin.fr-FR.xlf`](../translations/fr-FR/ModulesPsonepagecheckoutAdmin.fr-FR.xlf).

Les noms de fichiers générés par la console PrestaShop peuvent légèrement varier ; l’important est le **domaine** à l’intérieur du XLF et la **locale** (`fr-FR`, `en-US`, …).

## Créer ou mettre à jour les traductions

### Option A — Depuis le back-office (recommandé pour le contenu)

1. Installer le module sur une boutique PrestaShop 9.
2. Aller dans **International > Traductions**.
3. Traduire les chaînes du module (domaines `Modules.Psonepagecheckout.Admin` et éventuellement `Admin.*` si vous avez surchargé des libellés).

### Option B — Exporter depuis la console (pour livrer des XLF dans le dépôt)

Depuis la racine de **PrestaShop** (pas seulement le module) :

```bash
php bin/console prestashop:translation:export-module ps_onepagecheckout fr-FR
```

Sans locale, la commande peut exporter toutes les langues disponibles (voir la [documentation officielle](https://devdocs.prestashop-project.org/9/development/components/console/prestashop-translation-export-module/)).

Ensuite, décompressez / copiez le dossier `translations/` généré à la racine du module dans le dépôt.

## Utiliser les traductions dans le code

### PHP — classe `Module`

```php
$this->trans('One-page checkout', [], 'Modules.Psonepagecheckout.Admin');
```

Avec une locale explicite (ex. noms d’onglets multilingues) :

```php
$this->trans('Checkout', [], 'Modules.Psonepagecheckout.Admin', $lang['locale']);
```

### PHP — hors `Module` (ex. `BackOfficeConfigurationForm`)

Utiliser le traducteur du contexte :

```php
$translator = \Context::getContext()->getTranslator();
$translator->trans('Ma chaîne', [], 'Modules.Psonepagecheckout.Admin');
```

Le helper privé `trans()` dans `BackOfficeConfigurationForm` fait la même chose ; le domaine par défaut du helper doit rester aligné avec `Modules.Psonepagecheckout.Admin` pour les chaînes du module.

### Twig

Les templates BO du module (`views/templates/admin/*.html.twig`) reçoivent en général des **chaînes déjà traduites** depuis PHP (variables passées au rendu). Vous pouvez aussi traduire dans Twig :

```twig
{{ 'Ma chaîne'|trans({}, 'Modules.Psonepagecheckout.Admin') }}
```

### Smarty (legacy)

- **Avec domaine Symfony** (recommandé si le thème / le contexte le supporte) :

```smarty
{l s='Ma chaîne' d='Modules.Psonepagecheckout.Admin'}
```

- **Ancien style module** (`mod=`) : utilise les fichiers PHP legacy du module, **pas** les XLF Symfony du même nom. Préférez le domaine `d=` pour rester cohérent avec ce dépôt.

## Chaînes côté JavaScript

Le module n’embarque pas encore de catalogue JS dédié. Pour des messages affichés en JS :

- soit passer les libellés **déjà traduits** depuis PHP (`Media::addJsDef`, configuration exposée au front),
- soit exposer un petit objet de traductions construit côté PHP avec `$translator->trans(...)`.

## Bonnes pratiques

- **Source** : rédiger les chaînes en anglais dans le code.
- **Ne pas dupliquer** la même phrase avec des variantes (espaces, ponctuation) : une seule entrée par sens.
- Après ajout de nouvelles chaînes dans le module, **ré-exporter** ou compléter les XLF pour chaque locale ciblée.
- Les fichiers `translations/*.php` générés automatiquement par PrestaShop peuvent exister en local mais ne sont pas la cible principale pour PS 9 ; le format **XLF** est celui à versionner pour la distribution.

## Références

- [Console `prestashop:translation:export-module`](https://devdocs.prestashop-project.org/9/development/components/console/prestashop-translation-export-module/)
- Dépôt central des packs de traduction (exemples de XLF) : [PrestaShop/TranslationFiles](https://github.com/PrestaShop/TranslationFiles)
