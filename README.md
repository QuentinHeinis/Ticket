# Gestion de tickets de support (test technique Symfony)

Application interne de gestion et d'attribution automatique de tickets : API JSON, front minimal Twig + JavaScript natif.

**Stack** : Symfony 7.4, PHP 8.2+ (développé avec PHP 8.5), Doctrine ORM + Migrations, SQLite, Symfony Validator, Security. Aucune librairie complémentaire en dehors des composants Symfony officiels.

## Lancement en local

```bash
composer install
bin/console doctrine:migrations:migrate -n
bin/console app:seed-users
php -S 127.0.0.1:8000 -t public      # ou: symfony server:start
```

`DATABASE_URL` est déjà configurée dans `.env` (`var/data.db`). Aucune configuration n'est nécessaire.

Ouvrir http://127.0.0.1:8000 et se connecter avec l'un des comptes ci-dessous (mot de passe : `pom_zed123!`).

| Utilisateur | Email | Capacité |
|---|---|---|
| Alice | alice@pomzed.ch | 5 |
| Bob | bob@pomzed.ch | 3 |
| Charlie | charlie@pomzed.ch | 8 |

### Tâches périodiques (cron)

Deux commandes sont à planifier **chaque minute, dans cet ordre** (un ticket attribuable ne doit pas être escaladé inutilement) :

```bash
bin/console app:tickets:assign-pending        # attribue les tickets en attente
bin/console app:tickets:escalate-priorities   # escalade les priorités
```

Exemple crontab : `* * * * * cd /chemin/projet && bin/console app:tickets:assign-pending && bin/console app:tickets:escalate-priorities`

### Tests

```bash
APP_ENV=test bin/console doctrine:migrations:migrate -n
vendor/bin/phpunit
```

101 tests, 396 assertions. Les tests utilisent `var/test.db` ; ceux qui vident des tables vérifient d'abord que la base ciblée est bien `test.db`. Les lignes `[error] Uncaught PHP Exception` affichées pendant la suite sont les réponses 4xx volontairement provoquées par les tests.

## API

Toutes les routes `/api/*` (sauf `/api/login`) exigent une session authentifiée et répondent **401 JSON** sinon.

| Méthode | Route | Rôle |
|---|---|---|
| POST | `/api/login` | `{email, password}` → `{id, name}` (cookie de session) |
| POST | `/api/logout` | Déconnexion |
| GET | `/api/me` | Utilisateur connecté |
| POST | `/api/tickets` | Crée un ticket `{title, description, priority}` et tente l'attribution immédiate (201) |
| GET | `/api/tickets` | Recherche paginée par curseur |
| PATCH | `/api/tickets/{id}/status` | `{status}` : changement de statut |
| PATCH | `/api/tickets/{id}/assignee` | `{assigneeId}` : réattribution |
| GET | `/api/tickets/overdue` | Tickets en retard (`page`, `limit`) |
| GET | `/api/dashboard` | Tableau de bord |

Codes d'erreur : **422** pour un corps ou des paramètres invalides, **404** pour un ticket ou un utilisateur inexistant, **409** pour une règle métier violée (transition interdite, capacité atteinte, réattribution refusée).

### Recherche et pagination

`GET /api/tickets` accepte, combinables en `AND` : `status`, `priority`, `assigneeId`, `createdFrom` (inclus), `createdTo` (exclu) en ISO 8601, `cursor`, `limit` (défaut 50, max 100). Toute valeur invalide renvoie 422 ; rien n'est ajusté silencieusement.

Réponse : `{items, nextCursor, limit}`.

- **Pagination par curseur (keyset)**, pas par offset. Le tri est fixe : `createdAt DESC, id DESC`. Le curseur est opaque (base64 de `createdAt|id`).
- On demande `limit + 1` lignes pour savoir s'il y a une page suivante, sans `COUNT`.
- Pourquoi : un `OFFSET` profond et un `COUNT(*)` filtré sur 500 000 lignes coûtent cher. Le coût d'une page reste constant (voir les mesures).
- Compromis : pas d'accès direct à la page N, pas de total, pas de tri configurable.

## Règles métier et décisions

### Attribution automatique
- Un utilisateur indisponible ne reçoit rien ; un utilisateur ne dépasse jamais sa capacité (nombre de tickets `assigned` + `in_progress`).
- Charge = somme des points des tickets `assigned` et `in_progress` (low 1, normal 2, high 4, urgent 8). Les points vivent à un seul endroit (`Priority::points()`).
- Le ticket va à l'utilisateur de **charge la plus faible**.
- **Départage** : à charge égale, celui dont la dernière attribution (`lastAssignedAt`) est la plus ancienne (jamais attribué passe en premier), puis l'`id` le plus bas. C'est un round-robin dont l'état est en base, déterministe et vérifiable.
- Un test unitaire vérifie que le résultat ne dépend pas de l'ordre de la liste fournie.

### Personne de disponible
Le ticket est enregistré en `new`, sans assigné. La commande `app:tickets:assign-pending` le rattrape (priorité décroissante, puis ancienneté). Quand de la capacité se libère (ticket résolu, utilisateur redevenu disponible), c'est ce cron qui en profite : délai maximal d'une minute, compromis assumé plutôt que de déclencher le rattrapage dans chaque changement de statut.

La création se fait en deux transactions volontairement séparées : le ticket existe toujours, même si l'attribution échoue ou si le processus s'interrompt entre les deux.

### Transitions de statut
`new → assigned`, `assigned → in_progress`, `in_progress → resolved`, `resolved → closed`, `resolved → in_progress`. Tout le reste renvoie 409. Table de transitions portée par l'enum `Status`.

Précisions :
- `new → assigned` via l'API est refusé si le ticket n'a pas d'assigné.
- `resolved → in_progress` remet le ticket dans la charge : il est **refusé (409) si l'assigné est déjà à capacité maximale**.

### Historique
Table `ticket_history` en journal d'événements immuables (`created`, `status_changed`, `assigned`, `reassigned`, `priority_escalated`) avec ancienne et nouvelle valeur, acteur (nul pour les actions automatiques) et date. Elle permet de reconstituer toute l'évolution d'un ticket. Les ids d'utilisateurs sont stockés, pas les noms. Aucun ticket n'est jamais supprimé et l'historique n'est jamais modifié.

### Réattribution
- Refusée (409) si le ticket est `closed`, si le nouvel utilisateur est indisponible, s'il est déjà l'assigné, ou si sa capacité est atteinte.
- La capacité n'est contrôlée que si le ticket compte dans la charge (`new`, `assigned`, `in_progress`). Un ticket `resolved` ne consomme rien ; la capacité sera contrôlée s'il repasse en `in_progress`.
- **L'historique est conservé** : on ajoute une entrée `reassigned` (de A vers B, par qui, quand).
- Un ticket `new` sans assigné devient `assigned` (attribution manuelle).
- `lastAssignedAt` n'est pas modifié : le round-robin ne concerne que l'attribution automatique.

### Retard
Un ticket est en retard s'il est en `new` ou `assigned` et que `now > createdAt + limite(priorité actuelle)` (urgent 30 min, high 2 h, normal 8 h, low 24 h). Le calcul se fait à partir de la priorité courante. Conséquence : un ticket escaladé peut apparaître en retard rapidement ; c'est cohérent avec l'énoncé.

### Priorité automatique
Seuls les tickets `new` **sans assigné** sont escaladés : low → normal après 4 h, normal → high après 2 h, high → urgent après 1 h, urgent reste urgent. Le délai est mesuré depuis `priorityChangedAt` (création, puis dernier changement de priorité).

- **Un seul cran par exécution** : `priorityChangedAt` repasse à « maintenant ».
- Un `UPDATE` conditionnel (`status = new AND assignee IS NULL AND priority = ancienne`) vérifie qu'une ligne a bien été touchée : si un autre processus a attribué le ticket entre-temps, rien n'est fait.
- La logique métier est dans `PriorityEscalationService` (l'horloge est passée en paramètre, donc testable sans attendre) ; la commande console n'est qu'un déclencheur.

## Concurrence

SQLite n'admet qu'un seul écrivain à la fois, mais une transaction Doctrine démarre en mode différé : plusieurs requêtes peuvent lire la charge avant que l'une écrive. Les services d'attribution, de changement de statut, de réattribution et d'escalade commencent donc leur transaction par une **écriture sans effet** (`UPDATE ticket SET id = id WHERE id = :id`) qui prend le verrou d'écriture **avant** toute lecture. Les autres processus attendent leur tour, puis relisent l'état à jour (`refresh`) avant de décider.

### Test avec de vrais processus parallèles
Base `test.db`, un utilisateur de capacité 5 avec 4 tickets actifs, 10 tickets `new`, 10 processus lancés simultanément (`app:concurrency:assign`).

| | Résultat |
|---|---|
| **Avec le verrou** | 1 `assigned`, 9 `skipped`, 0 erreur, `active=5` : capacité respectée (reproduit deux fois) |
| **Sans le verrou** (test témoin) | 1 `assigned`, 6 `skipped`, **3 erreurs `database is locked`** ; capacité non dépassée sur cet essai |

Lecture honnête : sans le verrou initial, des requêtes légitimes échouent immédiatement (SQLite refuse d'élever un verrou de lecture en écriture). Avec lui, elles se sérialisent proprement. **Je n'ai pas réussi à reproduire un dépassement de capacité sans verrou** (un seul essai de ce type) ; je ne prétends donc pas l'avoir démontré, seulement que le verrou supprime les erreurs et garantit la cohérence.

Pour rejouer le test (zsh) :
```bash
IDS=$(APP_ENV=test bin/console app:concurrency:assign prepare | tail -1)
for id in ${=IDS}; do APP_ENV=test bin/console app:concurrency:assign run $id & done; wait
APP_ENV=test bin/console app:concurrency:assign verify
```

## Structure des données

- **`app_user`** : id, email (unique), name, password (hash), roles, available, capacity, last_assigned_at.
- **`ticket`** : id, title, description, priority, status, created_at, priority_changed_at, assignee_id (nullable).
- **`ticket_history`** : id, ticket_id, type, from_value, to_value, actor_id (nullable), created_at.

Priorités et statuts sont des enums PHP. Les dates sont des `DateTimeImmutable` stockés **en UTC, à la seconde**. La table s'appelle `app_user` car `user` est un mot réservé dans plusieurs SQL. Le ticket n'a pas de collection `history` : on ne charge jamais l'historique entier.

## Index et performances

### Jeu de données de mesure
Base séparée `var/perf.db` : **500 000 tickets, 100 utilisateurs, 1 924 261 lignes d'historique** (344 Mo), généré en 19,7 s par `app:perf:generate` (SQL brut par lots). Répartition : 5 % `new`, 500 tickets actifs (respectant les capacités), le reste `resolved`/`closed`. L'énoncé dit qu'il n'est pas nécessaire de générer 5 M de lignes d'historique : il n'est lu que par `ticket_id`, via un index, donc son coût par ticket ne dépend pas de la taille de la table (extrapolation, non mesurée à 5 M).

```bash
DATABASE_URL="sqlite:///%kernel.project_dir%/var/perf.db" bin/console doctrine:migrations:migrate -n
APP_ENV=prod APP_DEBUG=0 DATABASE_URL="sqlite:///%kernel.project_dir%/var/perf.db" php -d memory_limit=-1 bin/console app:perf:generate
APP_ENV=prod APP_DEBUG=0 DATABASE_URL="sqlite:///%kernel.project_dir%/var/perf.db" php -d memory_limit=-1 bin/console app:perf:measure
```

(Le mode `prod` / sans debug est nécessaire : en `dev`, le profiler Doctrine conserve les paramètres de chaque requête et épuise la mémoire.)

### Index

| Index | Sert à |
|---|---|
| `idx_ticket_created_at` | recherche sans statut, curseur, filtre par période |
| `idx_ticket_status_created_id` | recherche par statut avec tri et curseur |
| `idx_ticket_status_assignee` | charge par utilisateur (attribution, dashboard) |
| `idx_ticket_status_priority_created` | retards, escalade, rattrapage |
| `idx_history_ticket_created` | historique d'un ticket |
| index sur `assignee_id` (clé étrangère) | filtre `assigneeId` |

L'index `idx_ticket_status_created_id` a été ajouté **après mesure** : sans lui, `status=closed` lisait environ 237 000 lignes puis les triait (`USE TEMP B-TREE FOR ORDER BY`).

### Mesures (moyenne de 5 exécutions, `EXPLAIN QUERY PLAN` vérifié)

| Scénario | Temps | Plan |
|---|---|---|
| Recherche sans filtre, limit 50 | 0,64 ms | parcours ordonné de `idx_ticket_created_at` |
| `status=new` | 6,6 → **0,62 ms** | `idx_ticket_status_created_id` |
| `status=closed` | 264 → **0,63 ms** | `idx_ticket_status_created_id` |
| `assigneeId` (≈ 5 000 tickets) | 5,1 ms | index FK + tri temporaire |
| `priority=urgent` + période de 7 jours | 0,84 ms | `idx_ticket_created_at` borné |
| Curseur profond (200 pages) | 1,4 ms | idem page 1 : coût constant |
| Retards (liste + comptage) | 20,9 ms | `MULTI-INDEX OR` sur `idx_ticket_status_priority_created` + tri temporaire |
| Dashboard : compteurs | 42,9 ms | `SCAN` de la table |
| Dashboard : lignes par utilisateur | 0,53 ms | `idx_ticket_status_assignee` |
| Charges utilisateurs (verrou d'attribution) | 0,48 ms | `idx_ticket_status_assignee` |
| Historique d'un ticket | 0,17 ms | `idx_history_ticket_created` |

Limites des mesures : les SQL passés à `EXPLAIN QUERY PLAN` sont des équivalents reconstruits à la main, pas extraits de Doctrine ; le ticket d'historique mesuré est le n° 1 (une seule ligne), ce qui est le meilleur cas ; une seule machine, un seul jeu de données.

### Choix d'optimisation assumés
- **Pas de chargement d'entités pour les agrégats** : dashboard et charges utilisateurs sont des requêtes d'agrégation (`COUNT`, `SUM(CASE ...)`), les points étant construits depuis `Priority::points()`.
- **Pas de N+1** : les listes font `leftJoin` + `addSelect` sur l'assigné.
- **Dashboard laissé en scan linéaire** (43 ms à 500 000 tickets) : cela reste raisonnable et évite de la complexité. Piste : compteurs dénormalisés ou requêtes séparées sur index (à mesurer, trois requêtes pourraient coûter plus qu'un scan).
- **Index `(assignee_id, created_at, id)` non ajouté** : gain de quelques millisecondes, au prix d'écritures plus lentes et d'une base plus grosse.
- Le prédicat de retard existe sous deux formes (liste et dashboard) ; un test compare les résultats pour éviter toute divergence.

## Front

Page unique `/` (onglets Tickets, En retard, Tableau de bord) et page `/login`. JavaScript natif (`public/js/app.js`), sans framework ni bundler. Fonctions : voir et filtrer les tickets (statut, priorité, assigné, période), créer, changer le statut (menu limité aux transitions autorisées), réattribuer, voir les retards, consulter le dashboard. Les données de l'API sont insérées avec `textContent` (jamais `innerHTML`) car les titres viennent des utilisateurs.

## Sécurité

Connexion `json_login` avec session par cookie (`HttpOnly`, `SameSite=Lax`). Les routes `/api` renvoient 401 sans session. L'utilisateur connecté est enregistré comme acteur dans l'historique.

## Limites connues

- **Un seul rôle** : tout utilisateur connecté peut tout faire (l'énoncé ne définit aucune règle d'autorisation).
- **Pas de jeton CSRF** : protection par cookie `SameSite=Lax` et corps JSON. Compromis à revoir en production.
- **La page `/` est publique** (coquille Twig) : seules les données passent par l'API protégée. Un visiteur non connecté voit brièvement une page vide avant d'être redirigé.
- **SQLite** : un seul écrivain à la fois. Les écritures concurrentes sont sérialisées par le verrou ; à forte charge en écriture, il faudrait un SGBD client-serveur (PostgreSQL) avec `SELECT ... FOR UPDATE`.
- **Recherche** : tri fixe, pas de total, pas d'accès direct à une page.
- **Message de validation d'une priorité inconnue** : Symfony répond « should be of type int|string » avec un `hint` indiquant l'enum attendu ; non reformulé.
- **Rattrapage différé** : la capacité libérée est exploitée au prochain passage du cron (≤ 1 minute).
- **Pas de déploiement en ligne** : le projet est prévu pour un lancement local. Avec SQLite, la plupart des hébergeurs gratuits ont un disque éphémère, ce qui perdrait les données à chaque redémarrage.
- **Temps réel** : non demandé par l'énoncé, non implémenté.
- **5 M d'historique** non générés (voir plus haut) ; mesures faites à 1,9 M.

## Pistes d'amélioration

- PostgreSQL et verrous de lignes pour une vraie montée en charge en écriture.
- Compteurs dénormalisés pour le dashboard.
- Rôles et règles d'autorisation (qui peut réattribuer, clôturer).
- Rafraîchissement du front par polling ou Server-Sent Events si un besoin de temps réel apparaît.
- Messages d'erreur de validation plus explicites pour les enums.
