# PAS-12 — Correctifs de la contre-revue PAS-11

**Dépôt :** `optimgov/Naja7i_backend_front` — état attendu : `74aeec3`
**Nature :** lot correctif.

---

## DÉCISIONS PRÉ-ARBITRÉES

| Situation | Décision |
|---|---|
| Colima / Docker arrêté | `colima start`, `docker compose up -d` |
| Migration en échec sur un trigger existant | `php artisan migrate:fresh --drop-types` sur la base de dev |
| Conflit sur un fichier de l'overlay | L'overlay fait foi |
| La connexion `pgsql_concurrent` n'existe pas | La créer à l'étape 3. Sans elle, les tests de course ne prouvent rien |
| Des tests antérieurs cassent sur `published → draft` | **Attendu.** Passer par `createRevision()`, jamais assouplir le trigger |
| Des tests antérieurs cassent sur les sources après publication | **Attendu.** Attacher la source AVANT publication |
| Le `lock_timeout` du test de verrou est instable en CI | Le porter à 1 s, jamais retirer le test |
| Test rouge | Corriger le code applicatif, jamais le test — sauf régression de sécurité, à signaler |
| PostgreSQL indisponible | S'arrêter et signaler. Jamais de repli SQLite |

---

## Étapes

1. `colima start` puis `docker compose up -d`.

2. **Appliquer l'overlay.** Il remplace `app/Services/AttemptService.php` et
   `app/Services/PermissionResolver.php`.

3. **Déclarer la seconde connexion** dans `config/database.php`, en copiant la
   configuration `pgsql` :
   ```php
   'pgsql_concurrent' => array_merge(
       config('database.connections.pgsql') ?? [],
       ['name' => 'pgsql_concurrent']
   ),
   ```
   Elle sert uniquement aux tests de concurrence : une transaction de test ne
   peut pas s'entrelacer avec elle-même.

   **Attention :** `RefreshDatabase` enveloppe les tests dans une transaction
   sur la connexion par défaut. La seconde connexion ne la voit pas. Les tests
   concernés créent donc leurs données puis les valident — vérifie que
   `CorrectifsContreRevueTest` passe, et si l'isolation pose problème, marque
   ces tests avec `DatabaseTransactions` désactivé plutôt que de les supprimer.

4. **Migrer et tester** :
   ```bash
   php artisan migrate
   php artisan test
   ```
   Attendu : les tests précédents adaptés + 18 tests de contre-revue. **0 rouge.**

5. **Rejouer les quatre scénarios de la contre-revue.** Chacun doit échouer :
   - `UPDATE questions SET status='draft'` sur une question publiée ;
   - `DELETE FROM question_sources` sur une question publiée ;
   - attacher `tenants.manage` au rôle `candidat` après une attribution dans un
     organisme ;
   - écrire une réponse après une soumission concurrente.

6. **Corriger l'avertissement PHPUnit** relevé par la revue : les data
   providers en doc-comment (`@dataProvider`) deviennent des attributs
   `#[DataProvider]`. Chercher toutes les occurrences dans `tests/`.

7. **Style, commit, push** puis **commit de suivi** inscrivant le SHA au
   journal et au backlog :
   ```bash
   ./vendor/bin/pint
   git add -A
   git commit -m "PAS-12: correctifs de contre-revue — sortie de l'état publié bornée au retrait, sources gelées après publication, permission réservée refusée sur un rôle déjà distribué, verrou de tentative dans answer() avec test d'entrelacement réel"
   git push origin main
   # puis
   git commit -m "docs: SHA du PAS-12 au journal et au backlog" && git push
   ```
   C'est la convention adoptée : un commit ne peut pas contenir son propre SHA.

## Points de vigilance

- **Le test de verrou est le cœur du lot.** Il détient un verrou sur une
  seconde connexion et vérifie que `answer()` attend. S'il ne lève pas de
  « lock timeout », c'est que le verrou n'est pas réclamé — le défaut est
  toujours là.
- **Un test séquentiel ne prouve rien sur une course.** Ne pas remplacer les
  tests à deux connexions par des versions simplifiées.
- **La garde des options du PAS-10 est toujours active.** Un test le vérifie
  explicitement : la contre-revue la croyait absente.

## Ce que ce lot ne fait pas

Le correctif frontend (typecheck, CI) : dépôt séparé, FRONT-1.1.
