# PAS-2 — Instructions d'exécution pour Claude Code

**Dépôt :** `optimgov/Naja7i_backend_front` — état attendu : commit `29b9170` (PAS-1.1, CI verte)
**Objet :** authentification candidat — Sanctum cookies BFF, actes juridiques versionnés, inscription FR/AR, vérification d'e-mail bloquante.

---

## DÉCISIONS PRÉ-ARBITRÉES — ne pose aucune question sur ces points

| Situation | Décision |
|---|---|
| Colima / Docker arrêté | `colima start`, `docker compose up -d`, attendre le healthcheck |
| Base `naja7i_test` absente | `docker compose exec -T postgres createdb -U naja7i naja7i_test` |
| `routes/api.php` absent | `php artisan install:api` (installe Sanctum), PUIS écraser par celui de l'overlay |
| Sanctum déjà installé | Ne pas réinstaller |
| Conflit sur un fichier de l'overlay | L'overlay fait foi, écraser |
| Le hasher par défaut est bcrypt | Basculer sur argon2id (`HASH_DRIVER=argon2id` dans `.env` et `.env.example`) — bcrypt tronque à 72 octets, ce qui casse les phrases de passe en arabe |
| `uncompromised()` échoue en CI (réseau) | Mettre `PASSWORD_CHECK_COMPROMISED=false` dans l'environnement CI uniquement, jamais en local ni en production, et le noter |
| Migration en échec sur un type existant | `php artisan migrate:fresh` sur la base de dev uniquement |
| Test rouge | Corriger le code applicatif, **jamais** le test ni la migration |
| PostgreSQL indisponible | S'arrêter et le signaler. **Jamais de repli SQLite** |
| Choix de nommage, ordre des méthodes | Trancher toi-même |

**Arrête-toi uniquement si :** perte de données possible, dépôt incorrect, ou PostgreSQL réellement indisponible.

---

## Étapes

1. **Vérifier le départ** : `git log --oneline` doit montrer `29b9170`. CI verte sur GitHub.

2. **Installer l'API et Sanctum** : `php artisan install:api` — ne pas lancer les migrations à l'invite, on les groupe.

3. **Appliquer l'overlay** (écraser sans hésiter) — voir l'arborescence de l'archive.

4. **Enregistrer les middlewares** dans `bootstrap/app.php`. L'ordre compte :
   `AssignRequestId` en premier (une erreur survenant n'importe où doit pouvoir
   s'y référer), `SetLocale` après l'authentification (il lit `$request->user()`).
   ```php
   ->withMiddleware(function (Middleware $middleware) {
       $middleware->statefulApi();
       $middleware->api(prepend: [AssignRequestId::class]);
       $middleware->api(append: [ResolveTenant::class, SetLocale::class]);
       $middleware->alias(['verified.api' => EnsureEmailIsVerified::class]);
   })
   ```

5. **Gestionnaire d'exceptions** — toutes les erreurs API doivent sortir au
   format `ErrorResponse` avec `request_id`, y compris 404, 422, 500 et les
   `AuthenticationException`. Dans `bootstrap/app.php`, `->withExceptions(...)` :
   rendre en JSON via `ApiError::make()` pour toute requête `expectsJson()`.
   Codes attendus : `AUTH_UNAUTHENTICATED` (401), `RESOURCE_NOT_FOUND` (404),
   `VALIDATION_FAILED` (422, avec `details`), `INTERNAL_ERROR` (500).
   **Les tests d'ApiContractTest vérifient ce point — ils échoueront tant que
   ce n'est pas fait.**

6. **Environnement** — `.env` et `.env.example` :
   ```
   HASH_DRIVER=argon2id
   SESSION_DRIVER=redis
   SESSION_DOMAIN=localhost
   SANCTUM_STATEFUL_DOMAINS=localhost,localhost:3000,127.0.0.1:3000
   FRONTEND_URL=http://localhost:3000
   EMAIL_VERIFICATION_GATE=registration
   ```
   `config/cors.php` : `'supports_credentials' => true`,
   `'paths' => ['api/*', 'sanctum/csrf-cookie']`.

7. **Modèle User** — ajouter `implements MustVerifyEmail` et les relations
   `identities()` et `legalEvents()`. Ne pas toucher au reste (PAS-1.1).

8. **Migrer et tester** :
   ```bash
   php artisan migrate
   php artisan test
   ```
   Attendu : 26 tests du PAS-1.1 + 22 tests d'authentification + 8 tests
   contractuels. **56 verts, 0 rouge.**

9. **Style** : `./vendor/bin/pint`

10. **Commit, push, et vérifier la CI** :
    ```bash
    git add -A
    git commit -m "PAS-2: authentification candidat — Sanctum cookies BFF, actes juridiques versionnés (CGU/privacy/marketing), mot de passe 12 car. + anti-fuite, rate limiting 3 agrégats, vérification e-mail bloquante, tests contractuels request_id et clés internes"
    git push origin main
    ```
    Un PAS-2 dont la CI est rouge n'est pas livré.

---

## Points de vigilance

- **L'état juridique se calcule contre le document publié**, jamais « dernière
  ligne par type ». Si tu es tenté d'écrire `orderByDesc('occurred_at')->first()`
  sans filtrer sur la version courante, relis `LegalConsentService`.
- **Un acte juridique ne se modifie ni ne se supprime.** Aucun `update()` sur
  `legal_events`.
- **Pas de jeton bearer.** Ne pas ajouter `createToken()` : c'est le schéma des
  intégrations serveur, pas celui du navigateur.
- **Les trois compteurs de rate limiting sont indépendants.** Ne pas les
  fusionner « pour simplifier » : c'est précisément ce qui rendait le contournement
  possible.
- **Le test contractuel récursif** parcourt chaque réponse JSON à la recherche
  de clés internes. S'il échoue sur une nouvelle route, c'est la ressource qu'il
  faut corriger, pas la liste des clés interdites.

## Ce que ce Pas ne fait pas

Google/Facebook (table `identities` prête), OTP téléphone, mot de passe oublié
(la table existe depuis le PAS-1.1, le parcours viendra), profil progressif,
export et suppression de compte. Chacun a son Pas.
