# PAS-8 — Instructions d'exécution pour Claude Code

**Dépôt :** `optimgov/Naja7i_backend_front` — état attendu : `f696db2`
**Objet :** surface HTTP du parcours candidat et contrat de droit d'accès.

---

## DÉCISIONS PRÉ-ARBITRÉES

| Situation | Décision |
|---|---|
| Colima / Docker arrêté | `colima start`, `docker compose up -d` |
| Migration en échec sur un type existant | `php artisan migrate:fresh --drop-types` sur la base de dev |
| Conflit sur un fichier de l'overlay | L'overlay fait foi |
| Tentation de fusionner les deux ressources en une seule avec un drapeau | **Interdit.** ADR-0018 §1 |
| Tentation de renvoyer la justesse à la réponse | **Interdit.** ADR-0018 §2 |
| Tentation d'interroger un abonnement hors de `AccessGrant` | **Interdit.** ADR-0018 §3 |
| Tentation de masquer aussi les justifications au quota épuisé | **Interdit.** ADR-0018 §4 |
| Un renvoi DET est nécessaire | Lire `docs/DETTE.md` et prendre le prochain identifiant libre. **Ne jamais numéroter à l'aveugle** |
| Test rouge | Corriger le code applicatif, jamais le test |
| PostgreSQL indisponible | S'arrêter et signaler. Jamais de repli SQLite |

---

## Étapes

1. `colima start` puis `docker compose up -d`.

2. **Appliquer l'overlay.**

3. **Lier le contrat à son implémentation** — dans `AppServiceProvider::register()` :
   ```php
   $this->app->bind(
       \App\Contracts\AccessGrant::class,
       \App\Services\DatabaseAccessGrant::class
   );
   ```

4. **Fusionner les routes** : `routes/api-pas8-additions.php` dans
   `routes/api.php`. La route `demonstration/correction` va en section
   **publique** ; les routes `me/*` dans le groupe
   `['auth:sanctum', 'verified.api']`. Supprimer ensuite le fichier d'additions.

5. **Créer `lang/{fr,ar}/parcours.php`** à partir des deux `.append`, puis
   supprimer ceux-ci. Vérifier que `lang/{fr,ar}/errors.php` contient bien la
   clé `not_found` (posée au PAS-4).

6. **Migrer et tester** :
   ```bash
   php artisan migrate
   php artisan test
   ```
   Attendu : les tests précédents + 18 tests de parcours. **0 rouge.**

7. **Vérifier la surface** :
   ```bash
   php artisan route:list --path=api/v1 | grep -E "diagnostics|attempts|mastery|plan|demonstration"
   ```
   Huit routes attendues.

8. **Style, commit, push, CI verte** :
   ```bash
   ./vendor/bin/pint
   git add -A
   git commit -m "PAS-8: surface HTTP du parcours — diagnostic, réponses sans verdict, correction après soumission, maîtrise et ordonnance ; contrat de droit d'accès (ADR-0010) et démonstration publique marquée comme exemple"
   git push origin main
   ```

## Points de vigilance

- **Le test de fuite est le plus important du lot.** Il parcourt le corps JSON
  d'une tentative en cours à la recherche de `rationale`, `cause`,
  `is_correct`. S'il échoue, ne masque pas le symptôme : c'est une ressource
  qui expose trop.
- **`access_grants` n'a pas de `tenant_id`**, volontairement. Ne pas l'ajouter
  à `TENANT_SCOPED_TABLES`.
- **La démonstration exige une banque peuplée.** Sans questions éligibles, elle
  répond 404 — comportement voulu, pas un bug.

## Ce que ce pas ne fait pas

Séries d'entraînement, simulateur, questions miroir, rappels espacés, profil.
