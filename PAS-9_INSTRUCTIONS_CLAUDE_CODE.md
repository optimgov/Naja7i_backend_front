# PAS-9 — Instructions d'exécution pour Claude Code

**Dépôt :** `optimgov/Naja7i_backend_front` — état attendu : `d643f96`
**Objet :** appliquer les permissions fines de l'ADR-0009, restées non
implémentées. Écart relevé par l'audit externe, garde-fou G10.

---

## DÉCISIONS PRÉ-ARBITRÉES

| Situation | Décision |
|---|---|
| Colima / Docker arrêté | `colima start`, `docker compose up -d` |
| Migration en échec sur un index existant | `php artisan migrate:fresh --drop-types` sur la base de dev |
| Conflit sur un fichier de l'overlay | L'overlay fait foi |
| Tentation de mettre en cache les permissions au-delà de la requête | **Interdit.** ADR-0019 §2 |
| Tentation d'assouplir le trigger des permissions réservées | **Interdit.** ADR-0019 §4 |
| Tentation d'ajouter des permissions « au cas où » | **Interdit.** ADR-0019 §5 |
| `Role` existe déjà (PAS-1) | L'overlay le remplace : il gagne `tenant_id`, `permissions()` et le scope |
| Un renvoi DET est nécessaire | Lire `docs/DETTE.md`, prendre le prochain identifiant libre |
| Test rouge | Corriger le code applicatif, jamais le test — **sauf** si le correctif applicatif serait une régression de sécurité : dans ce cas, signale-le et corrige le test en expliquant pourquoi |
| PostgreSQL indisponible | S'arrêter et signaler. Jamais de repli SQLite |

---

## Étapes

1. `colima start` puis `docker compose up -d`.

2. **Appliquer l'overlay.** `app/Models/Role.php` est remplacé.

3. **Vérifier que `Role` n'est plus utilisé pour autoriser** : cherche les
   appels à `hasRole()` et `isStaff()` dans `app/`. Les laisser où ils
   distinguent candidat et staff ; les signaler s'ils autorisent une action
   précise. Aucun n'est censé exister aujourd'hui — c'est un contrôle, pas une
   migration de code.

4. **Migrer et tester** :
   ```bash
   php artisan migrate
   php artisan test
   ```
   Attendu : les tests précédents + 17 tests de permissions. **0 rouge.**

5. **Vérifier l'attribution initiale** :
   ```bash
   php artisan tinker --execute="
     foreach (App\Models\Role::whereNull('tenant_id')->get() as \$r) {
       echo str_pad(\$r->code, 14) . ' : ' . \$r->permissions()->count() . ' permissions' . PHP_EOL;
     }"
   ```
   Attendu : candidat 0, auteur 3, reviseur 3, editeur 9, support 5,
   finance 2, super_admin toutes.

6. **Traiter la parallélisation de la suite** (écart §6 du backlog) :
   tenter `php artisan test --parallel`. S'il échoue sur des interblocages en
   `DROP TABLE`, ne pas s'acharner : documenter le comportement observé en
   dette avec le prochain identifiant libre, et ajouter une note dans
   `docs/BACKLOG.md` §9 si elle manque. C'est un constat, pas un correctif à
   forcer dans ce lot.

7. **Style, commit, push, CI verte** :
   ```bash
   ./vendor/bin/pint
   git add -A
   git commit -m "PAS-9: permissions fines appliquées — référentiel de 19 permissions, rôles par organisme, garde en base sur les permissions réservées, résolution sans cache persistant (exécute l'ADR-0009)"
   git push origin main
   ```

## Points de vigilance

- **Le trigger `permission_role_scope_guard` est le cœur du lot.** Il empêche
  qu'un administrateur d'organisme s'octroie un pouvoir de plateforme. Deux
  tests l'exercent dans les deux sens.
- **L'unicité des codes de rôle devient partielle.** Deux index remplacent
  l'unicité globale du PAS-1 : ne pas la recréer.
- **Aucune mise en cache au-delà de la requête.** Deux tests vérifient qu'un
  ajout comme un retrait prennent effet immédiatement.
- **Ne pas ajouter `permissions` ni `permission_role` à
  `TENANT_SCOPED_TABLES`** : ce sont des référentiels, pas de l'activité.
  `roles` non plus — il porte `tenant_id` mais reste lisible par tous les
  tenants pour les rôles de plateforme.

## Ce que ce pas ne fait pas

Les policies Laravel, l'écran d'attribution, le MFA. Ce lot pose le mécanisme
et le prouve ; le câblage suivra les écrans du back-office.
