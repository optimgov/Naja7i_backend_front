# PAS-14.2 — Le sens du changement de portée

**Dépôt :** `optimgov/Naja7i_backend_front` — état attendu : `66bf48e`
**Objet :** fermer le blocant de la contre-revue PAS-14.1.

**Nature du défaut :** inversion de sens. La garde du PAS-14 contrôlait les
permissions réservées dans la direction `organisme → global`, alors que l'état
interdit se crée par `global → organisme`.

---

## DÉCISIONS PRÉ-ARBITRÉES

| Situation | Décision |
|---|---|
| Colima / Docker arrêté | `colima start`, `docker compose up -d` |
| Conflit sur un fichier de l'overlay | L'overlay fait foi |
| Un test antérieur casse sur le nouveau sens | **Attendu.** Corriger le montage, jamais la garde |
| Test rouge | Corriger le code applicatif, jamais le test — sauf régression de sécurité, à signaler |
| PostgreSQL indisponible | S'arrêter et signaler. Jamais de repli SQLite |

---

## Étapes

1. `colima start` puis `docker compose up -d`.

2. **Appliquer l'overlay.** La migration remplace la fonction
   `assert_role_attributes_stable` par `CREATE OR REPLACE` ; elle ne touche
   aucun trigger.

3. **Corriger le docblock relevé par la revue** dans
   `GardesSurLesNoeudsTest::test_aucun_entrelacement_ne_produit_un_role_back_office_distribue` :
   remplacer « sur deux sessions, dans les deux ordres » par une formulation
   exacte — le premier ordre en concurrence, le second après mutation acquise.

4. **Migrer et tester** :
   ```bash
   php artisan migrate
   php artisan test
   ```
   Attendu : les tests précédents + 7 tests de portée. **0 rouge.**

5. **Épreuve par mutation.** Retirer la condition du §3 de la fonction doit
   faire virer au rouge `test_un_role_global_portant_une_permission_reservee_ne_devient_pas_local`
   **et** `test_l_invariant_tient_apres_la_tentative`. Le second est le plus
   important : il vérifie l'état interdit, pas le chemin.

6. **Rejouer le scénario de la revue**, en trois temps :
   attacher `tenants.manage` à un rôle global non distribué (autorisé), tenter
   de lui donner un `tenant_id` d'organisme (doit échouer), puis vérifier
   qu'aucune ligne ne joint `roles.tenant_id IS NOT NULL` à une permission
   `platform_only`.

7. **Style, deux commits, un seul push.**

## Point de vigilance

**Le test qui compte est celui de l'invariant, pas celui du chemin.** Cinq
revues ont montré que fermer un chemin laisse les variantes ouvertes. La
requête de vérification — aucun rôle d'organisme portant une permission
réservée — doit rester en place et être appelée après chaque tentative.
