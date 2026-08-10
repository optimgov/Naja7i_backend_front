# PAS-14 — Gardes sur les nœuds du graphe d'autorisation

**Dépôt :** `optimgov/Naja7i_backend_front` — état attendu : `d78be7b`
**Objet :** fermer les deux blocants de la revue PAS-13.

---

## DÉCISIONS PRÉ-ARBITRÉES

| Situation | Décision |
|---|---|
| Colima / Docker arrêté | `colima start`, `docker compose up -d` |
| Migration en échec sur un trigger existant | `php artisan migrate:fresh --drop-types` sur la base de dev |
| Conflit sur un fichier de l'overlay | L'overlay fait foi |
| Un test de course est instable en CI | Porter le `lock_timeout` à 1 s, jamais retirer le test |
| Un interblocage apparaît au lieu d'un délai d'attente | **Signale-le.** Ordre de verrouillage incohérent de ma part |
| Un seeder ou un test antérieur mute `roles.tenant_id` après attribution | **Attendu.** Corriger le montage, jamais assouplir la garde |
| Test rouge | Corriger le code applicatif, jamais le test — sauf régression de sécurité, à signaler |
| PostgreSQL indisponible | S'arrêter et signaler. Jamais de repli SQLite |

---

## Étapes

1. `colima start` puis `docker compose up -d`.

2. **Appliquer l'overlay.** Une migration, un test, un ADR. Aucun fichier
   existant n'est remplacé.

3. **Migrer et tester** :
   ```bash
   php artisan migrate
   php artisan test
   ```
   Attendu : les tests précédents + 13 tests de gardes. **0 rouge.**

4. **ÉPREUVE PAR MUTATION** — obligatoire, comme au PAS-13.

   Deux gardes, quatre verrous à éprouver :
   - `assert_role_attributes_stable` : retirer le `PERFORM ... FOR UPDATE` sur
     `memberships` ;
   - `assert_permission_reservation_stable` : retirer le `PERFORM ... FOR
     UPDATE OF r` sur `roles`.

   Puis, pour chaque garde, retirer la **condition** elle-même (le `RAISE
   EXCEPTION`) et vérifier qu'un test vire au rouge.

   **Comme au PAS-13, méfie-toi d'un test qui attend pour la mauvaise
   raison** : une clé étrangère prend un `FOR KEY SHARE`, un trigger voisin
   peut déjà verrouiller la ligne. Si un test reste vert sans son verrou, ou
   rouge pour une autre cause que celle annoncée, dis-le.

5. **Rejouer les deux scénarios de la revue.** Chacun doit échouer :
   - `UPDATE roles SET is_staff = true` sur un rôle global attribué dans un
     organisme ;
   - `UPDATE permissions SET platform_only = true` sur une permission portée
     par un rôle distribué hors plateforme.

6. **Style, deux commits, un seul push** :
   ```bash
   ./vendor/bin/pint
   git add -A
   git commit -m "PAS-14: gardes sur les nœuds du graphe d'autorisation — portée d'un rôle distribué immuable, is_staff et platform_only non mutables après distribution, sérialisation par verrou sur appartenances et rôles"
   git commit --allow-empty -m "docs: SHA du PAS-14 au journal et au backlog"  # après édition
   git push origin main
   ```

## Points de vigilance

- **Le sens « lever une réservation » doit rester ouvert.** Un test le
  vérifie : restreindre l'accès ne produit jamais d'escalade, l'interdire
  serait une gêne sans contrepartie.
- **Un rôle attribué uniquement sur la plateforme peut devenir `is_staff`.**
  C'est le cas légitime ; la garde ne mord que hors plateforme.
- **Le graphe d'autorisation est désormais gardé sur ses quatre tables.**
  Toute table qui viendra y participer devra l'être aussi (ADR-0024).

## Ce que ce lot ne fait pas

Le correctif frontend (typecheck, CI) : dépôt séparé, FRONT-1.1.
