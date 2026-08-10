# PAS-10 — Correctifs de la revue du 9 août

**Dépôt :** `optimgov/Naja7i_backend_front`
**Objet :** fermer les huit constats recevables de la revue `REVUE-PAS-1-A-7`.
**Nature :** lot correctif. Aucune fonctionnalité nouvelle.

---

## DÉCISIONS PRÉ-ARBITRÉES

| Situation | Décision |
|---|---|
| Colima / Docker arrêté | `colima start`, `docker compose up -d` |
| Migration en échec sur un index existant | `php artisan migrate:fresh --drop-types` sur la base de dev |
| Conflit sur un fichier de l'overlay | L'overlay fait foi |
| Des tests antérieurs cassent à cause du `$fillable` restreint de `Question` | **Attendu.** Les corriger pour passer par `QuestionTransitionService`, jamais rouvrir `$fillable` |
| Des tests antérieurs cassent à cause du gel des questions publiées | **Attendu.** Adapter le fixture, jamais assouplir le trigger |
| Des tests antérieurs cassent sur `markCauseRevealed` qui retourne désormais un booléen | Adapter les appels |
| La garde architecturale renforcée signale un fichier légitime | L'ajouter à `ALLOWED_LOW_LEVEL` **avec sa justification en commentaire**, jamais élargir les motifs |
| Test rouge | Corriger le code applicatif, jamais le test — sauf si le correctif serait une régression de sécurité : signale-le et corrige le test en expliquant pourquoi |
| PostgreSQL indisponible | S'arrêter et signaler. Jamais de repli SQLite |

---

## Étapes

1. `colima start` puis `docker compose up -d`.

2. **Appliquer l'overlay.** Il REMPLACE : `app/Models/Question.php`,
   `app/Models/LegalEvent.php`, `app/Services/AttemptService.php`,
   `app/Services/EmailVerificationService.php`,
   `app/Services/LegalConsentService.php`,
   `tests/Feature/TenancyArchitectureTest.php`.

3. **Adapter les appels existants.** `markCauseRevealed()` retourne désormais
   `bool`. Vérifier `ParcoursController::correction()` : la révélation n'est
   comptée que si l'appel retourne `true`.

4. **Adapter les tests antérieurs** rendus rouges par les correctifs. Deux
   familles attendues :
   - créations de questions passant `status` / `eligible_for_*` en masse →
     utiliser `QuestionTransitionService` ;
   - mutations de questions publiées dans des fixtures → créer la question
     complète avant publication.

   **Ne jamais** rouvrir `$fillable` ni assouplir un trigger pour verdir un test.

5. **Migrer et tester** :
   ```bash
   php artisan migrate
   php artisan test
   ```
   Attendu : les tests précédents adaptés + 25 tests de correctifs. **0 rouge.**

6. **Éprouver les gardes** — c'est la vérification qui compte :
   - poser une sonde `DB::table($nomVariable)` dans `app/` : la garde
     architecturale doit virer au rouge (elle ne le faisait pas avant) ;
   - poser `DB::connection()->table('memberships')` : idem ;
   - retirer les sondes, vérifier `app/` propre.

7. **Style, commit, push, CI verte** :
   ```bash
   ./vendor/bin/pint
   git add -A
   git commit -m "PAS-10: correctifs de revue — unicités tenant-aware, actes juridiques en ajout seul, contenu publié gelé, transitions éditoriales par service unique, consommation atomique des jetons et du quota, garde architecturale par chemins d'accès"
   git push origin main
   ```

## Points de vigilance

- **Le `$fillable` de `Question` est volontairement restreint.** `status`,
  `published_at`, `validator_id`, `version`, `supersedes_id` et les drapeaux
  d'éligibilité n'y figurent plus. C'est le correctif du défaut le plus grave
  de la revue.
- **Les triggers de gel autorisent le changement de STATUT**, pas de contenu :
  retirer une question publiée reste possible, c'est ainsi qu'on remplace une
  version.
- **Les tests de correctifs visent le chemin d'écriture le plus direct** —
  SQL brut, assignation de masse. Si l'un échoue, ce n'est pas le test qu'il
  faut adoucir.

## Ce que ce lot ne fait pas

Le correctif frontend (`npm run typecheck` rouge, absence de CI) : il concerne
l'autre dépôt et fera l'objet d'un FRONT-1.1.
