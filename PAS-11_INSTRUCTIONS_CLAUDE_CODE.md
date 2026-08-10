# PAS-11 — Correctifs de la revue PAS-9 / PAS-10

**Dépôt :** `optimgov/Naja7i_backend_front` — état attendu : `cf88cc6`
**Nature :** lot correctif. Une seule fonctionnalité nouvelle : le premier
endpoint protégé par une permission.

---

## DÉCISIONS PRÉ-ARBITRÉES

| Situation | Décision |
|---|---|
| Colima / Docker arrêté | `colima start`, `docker compose up -d` |
| Migration en échec sur un trigger existant | `php artisan migrate:fresh --drop-types` sur la base de dev |
| Conflit sur un fichier de l'overlay | L'overlay fait foi |
| Des tests antérieurs cassent parce qu'ils publiaient par `update()` | **Attendu.** Passer par `QuestionTransitionService`, jamais assouplir le trigger |
| Des tests antérieurs cassent parce qu'ils attribuaient un rôle staff dans un organisme | **Attendu.** Corriger le fixture |
| `markCauseRevealed` n'existe plus | Remplacé par `CauseRevealService::reveal()`. Adapter `ParcoursController` |
| La garde d'architecture signale `CauseRevealService` | L'ajouter à `ALLOWED_LOW_LEVEL` avec sa justification : incréments conditionnels |
| Test rouge | Corriger le code applicatif, jamais le test — sauf régression de sécurité, à signaler |
| PostgreSQL indisponible | S'arrêter et signaler. Jamais de repli SQLite |

---

## Étapes

1. `colima start` puis `docker compose up -d`.

2. **Appliquer l'overlay.**

3. **Enregistrer le middleware** dans `bootstrap/app.php` :
   ```php
   $middleware->alias(['permission' => \App\Http\Middleware\RequirePermission::class]);
   ```

4. **Fusionner les routes** : `routes/api-pas11-additions.php` dans
   `routes/api.php`, puis supprimer le fichier d'additions.

5. **Fusionner les traductions** : `lang-fr-auth.append` et
   `lang-ar-auth.append` dans `lang/{fr,ar}/auth.php`, puis les supprimer.

6. **Remplacer les appels à `markCauseRevealed`** par
   `CauseRevealService::reveal($user, $response, $premium)` dans
   `ParcoursController::correction()`. La méthode retourne `true` si la cause
   est visible. Retirer `markCauseRevealed()` et `canRevealCause()` de
   `AttemptService` — `CauseRevealService::status()` les remplace.

7. **Migrer et tester** :
   ```bash
   php artisan migrate
   php artisan test
   ```
   Attendu : les tests précédents adaptés + 26 tests de correctifs. **0 rouge.**

8. **Rejouer les scénarios de la revue** — c'est la vérification qui compte.
   Chacun doit désormais échouer :
   ```bash
   php artisan tinker
   ```
   - `Question::whereKey($id)->update(['status' => 'published'])`
   - `$membership = ...->create(['role_id' => $superAdminId])` sous un organisme
   - deux `CauseRevealService::reveal()` avec une seule unité restante

9. **Mettre à jour `docs/BACKLOG.md`** : ajouter PAS-9, PAS-10 et PAS-11 à la
   liste des pas livrés avec leurs SHA, et retirer du §6 les écarts fermés
   (permissions non implémentées, garde SQL contournable).

10. **Style, commit, push, CI verte** :
    ```bash
    ./vendor/bin/pint
    git add -A
    git commit -m "PAS-11: correctifs de revue 2 — garde d'appartenance contre l'escalade inter-tenant, publication contrôlée en base quel que soit le chemin, gel complet par liste blanche, quota réservé avant marquage, première action protégée par permission ; journal et backlog à jour"
    git push origin main
    ```

## Points de vigilance

- **Le trigger de publication duplique le service, volontairement.** Ne pas
  « factoriser » : le service produit des messages lisibles, la base garantit
  qu'aucun chemin ne l'esquive.
- **Le gel procède par liste blanche.** Ajouter une colonne à `questions` la
  gèle automatiquement. C'est voulu : l'oubli doit protéger, pas ouvrir.
- **Le quota se réserve AVANT de marquer la réponse.** L'unité rare est le
  quota, pas la réponse.
- **`docs/PAS.md` est remplacé** par une version à jour avec tous les SHA. La
  convention du backlog l'exige : un pas sans SHA n'est pas livré.

## Ce que ce lot ne fait pas

Le correctif frontend (typecheck rouge, absence de CI) : dépôt séparé,
FRONT-1.1.
