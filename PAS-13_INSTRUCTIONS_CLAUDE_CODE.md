# PAS-13 — Sérialisation des invariants

**Dépôt :** `optimgov/Naja7i_backend_front` — état attendu : `9f98ec9`
**Objet :** fermer les trois blocants de la contre-revue PAS-12.

---

## DÉCISIONS PRÉ-ARBITRÉES

| Situation | Décision |
|---|---|
| Colima / Docker arrêté | `colima start`, `docker compose up -d` |
| Migration en échec sur un trigger existant | `php artisan migrate:fresh --drop-types` sur la base de dev |
| Conflit sur un fichier de l'overlay | L'overlay fait foi |
| Un test de course est instable en CI | Porter le `lock_timeout` à 1 s, jamais retirer le test |
| Un interblocage apparaît au lieu d'un délai d'attente | **Signale-le.** C'est un ordre de verrouillage incohérent, pas un réglage |
| Des fixtures cassent sur le déplacement d'options | **Attendu.** Créer les options sur le bon parent dès le départ |
| Test rouge | Corriger le code applicatif, jamais le test — sauf régression de sécurité, à signaler |
| PostgreSQL indisponible | S'arrêter et signaler. Jamais de repli SQLite |

---

## Étapes

1. `colima start` puis `docker compose up -d`.

2. **Appliquer l'overlay.** La migration remplace quatre fonctions plpgsql et
   deux triggers enfants ; elle n'ajoute aucune table.

3. **Migrer et tester** :
   ```bash
   php artisan migrate
   php artisan test
   ```
   Attendu : les tests précédents + 13 tests de sérialisation. **0 rouge.**

4. **ÉPROUVER LES TESTS PAR MUTATION** — l'étape la plus importante du lot,
   et celle que les deux lots précédents ont ratée.

   Pour chacune des quatre gardes, retirer le verrou et vérifier qu'au moins
   un test vire au rouge :
   - `statut_question_verrouille` : retirer `FOR UPDATE` ;
   - contrôle de publication : retirer les deux `PERFORM ... FOR UPDATE` ;
   - `assert_membership_role_scope` : retirer `FOR UPDATE` sur `roles` ;
   - `assert_permission_scope` : retirer `FOR UPDATE` sur `roles`.

   **Si un test reste vert sans son verrou, il ne mesure pas ce qu'il
   annonce.** Signale-le plutôt que de le laisser passer — c'est exactement ce
   qui s'est produit au PAS-12.

   Restaurer la migration après chaque mutation.

5. **Rejouer les scénarios de la contre-revue.** Chacun doit échouer :
   - modifier une option d'une question **retirée** ;
   - déplacer une option d'une question publiée vers un brouillon ;
   - attacher `tenants.manage` à `candidat` pendant une attribution ouverte
     dans un organisme ;
   - publier pendant qu'une option est modifiée dans une transaction ouverte.

6. **Style, deux commits, un seul push** — nouvelle convention, pour qu'un
   seul run de CI s'exécute sur le second SHA :
   ```bash
   ./vendor/bin/pint
   git add -A
   git commit -m "PAS-13: sérialisation des invariants — verrou parent avant lecture du statut, contrôle des deux parents sur déplacement, état retiré gelé comme publié, rendez-vous sur la ligne roles entre appartenance et permission"
   # puis le journal, avec le SHA obtenu par git rev-parse HEAD
   git commit -m "docs: SHA du PAS-13 au journal et au backlog"
   git push origin main    # un seul push, un seul run
   ```

## Points de vigilance

- **L'ordre de verrouillage est parent puis enfants, partout.** Un ordre
  inversé quelque part produirait des interblocages au lieu d'une garantie.
- **Un test de concurrence non éprouvé par mutation ne compte pas.** Deux
  tests verts des lots précédents mesuraient autre chose que ce qu'ils
  annonçaient.
- **`DatabaseMigrations` et non `RefreshDatabase`** sur la classe de
  sérialisation : la seconde connexion doit voir des données validées.

## Ce que ce lot ne fait pas

Le correctif frontend (typecheck, CI) : dépôt séparé, FRONT-1.1.
