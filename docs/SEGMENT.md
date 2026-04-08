# Segment dans `ps_onepagecheckout`

Ce document décrit la **configuration** et l’**intégration technique** de Segment via le **SDK PHP** (`segmentio/analytics-php`), sur le même principe que le module [PrestaShop autoupgrade](https://github.com/PrestaShop/autoupgrade) (classe [`Analytics`](https://github.com/PrestaShop/autoupgrade/blob/dev/classes/Analytics.php)).

**Comportement actuel** : initialisation de `Segment::init()` lorsque les conditions sont réunies. **Aucun** appel `track` / `flush` n’est encore envoyé depuis le module — cela fera l’objet de tickets ultérieurs.

**Résumé** : write key **en dur** dans `Analytics::SEGMENT_CLIENT_KEY_PHP` → `Segment::init()` sur les requêtes BO qui passent par le hook concerné, **dès que le module est activé**.

La classe PHP s’appelle **`Analytics`** (nom volontairement **générique**) pour limiter les renommages si le fournisseur change.

**Front office** : aucun chargement du SDK navigateur (`analytics.js`) ; l’ancien bundle `opc-segment-init` a été retiré.

## Dépendance Composer

- `segmentio/analytics-php` (voir `composer.json`). Après clone : `composer install` à la racine du module pour disposer du vendeur et de l’autoload.

## Clés et constantes

| Source | Identifiant | Rôle | Défaut |
|--------|-------------|------|--------|
| Code PHP | `Analytics::SEGMENT_CLIENT_KEY_PHP` | Write key de la **source PHP** Segment — **seule source de vérité** (pas de `configuration`). | `''` |

## Architecture PHP

| Fichier | Rôle |
|---------|------|
| `src/Analytics/Analytics.php` | `bootstrap(bool $moduleSegmentEnabled)` : vérifie activation module, clé non vide, puis `Segment\Segment::init($writeKey)`. Garde statique pour n’initialiser qu’une fois par requête. |

`ps_onepagecheckout.php` appelle `Analytics::bootstrap(true)` depuis `bootstrapPhpSegmentClient()` (Segment activé tant que le module est activé), invoqué dans `hookActionAdminControllerSetMedia` lorsque `isBackOfficeConfigurationContext()` est vrai.

### Différences notables avec l’ancienne version (navigateur)

- Plus de `window.psopc_segment` ni de `opc-segment-init.bundle.js`.
- La clé **PHP** (`SEGMENT_CLIENT_KEY_PHP`) n’est pas la même source Segment que l’ancienne clé **JavaScript** ; à configurer dans l’espace Segment (source PHP).

## Où cela s’exécute

Hook **`actionAdminControllerSetMedia`**, uniquement sur la **configuration du module** en BO (`AdminPsOnePageCheckout`, `configure=ps_onepagecheckout`, ou `AdminPsOnePageCheckoutController`), comme auparavant pour le chargement JS — sauf qu’il ne s’agit plus que d’initialiser le client PHP.

## Configuration Back Office

Segment est considéré **activé** tant que le module est activé (et si la write key est non vide).

## Événements (`track`) — à venir

Les appels `Segment::track()` / `flush()` seront ajoutés dans de futurs tickets, une fois les événements métier définis.

## Cycle de vie du module

Pas de clé de configuration dédiée à Segment : l’activation suit l’activation du module.

## Fichiers utiles

- `src/Analytics/Analytics.php`
- `ps_onepagecheckout.php` — `hookActionAdminControllerSetMedia`, `bootstrapPhpSegmentClient()`
- `composer.json` — dépendance `segmentio/analytics-php`

## Limites

- La write key en dur dans le dépôt : politique de secret / environnements à définir côté équipe.
- `Segment::init` est appelé dans le contexte des requêtes qui déclenchent le hook (typiquement pages de config du module en BO).
