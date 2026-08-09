# PAS-7 — Instructions d'exécution pour Claude Code

**Dépôt :** `optimgov/Naja7i_backend_front` — état attendu : `02a3299`
**Objet :** maîtrise par compétence et ordonnance de remédiation.
**Inclut :** le correctif de `TenancyArchitectureTest` relevé au PAS-6.

---

## DÉCISIONS PRÉ-ARBITRÉES

| Situation | Décision |
|---|---|
| Colima / Docker arrêté | `colima start`, `docker compose up -d` |
| Migration en échec sur un type existant | `php artisan migrate:fresh --drop-types` sur la base de dev |
| Conflit sur un fichier de l'overlay | L'overlay fait foi |
| Tentation d'afficher un score sous le seuil d'évidence | **Interdit.** Contrainte en base, ADR-0017 §1 |
| Tentation d'ajouter un « indice de réussite » ou une probabilité | **Interdit.** METHODE §7.3, testé |
| Tentation d'agréger par nombre de réponses au lieu du poids officiel | **Interdit.** ADR-0017 §3 |
| Tentation de retirer le motif d'une ligne d'ordonnance | **Interdit.** ADR-0017 §6 |
| Test rouge | Corriger le code applicatif, jamais le test |
| PostgreSQL indisponible | S'arrêter et signaler. Jamais de repli SQLite |

---

## Étapes

1. `colima start` puis `docker compose up -d`.

2. **Appliquer l'overlay.** Aucune fusion manuelle sauf le fichier de dette
   (étape 5).

3. **Corriger `TenancyArchitectureTest`** — le défaut que tu as relevé au
   PAS-6 :
   - `class TenancyArchitectureTest extends \Tests\TestCase`
   - retirer l'import `PHPUnit\Framework\TestCase`
   - vérifier qu'il passe **seul** :
     `php artisan test --filter=TenancyArchitectureTest`
   - repose ta sonde `DB::table('attempts')` dans `app/`, vérifie qu'il vire
     au rouge en exécution isolée, puis retire-la.

4. **Ajouter `mastery_scores`** à `TENANT_SCOPED_TABLES` du même test.

5. **Fusionner `docs/DETTE-ajouts-pas7.md`** dans `docs/DETTE.md`, puis
   supprimer le fichier temporaire. DET-18 est marqué corrigé par ce pas.

6. **Migrer et tester** :
   ```bash
   php artisan migrate
   php artisan test
   ```
   Attendu : les tests précédents + 18 tests de maîtrise. **0 rouge.**

7. **Style, commit, push, CI verte** :
   ```bash
   ./vendor/bin/pint
   git add -A
   git commit -m "PAS-7: maîtrise et remédiation — pas de score sans évidence (contrainte en base), pondération par la certitude, agrégation par poids officiels, ordonnance motivée, aucun score prédictif ; corrige la garde d'architecture (DET-18)"
   git push origin main
   ```

## Points de vigilance

- **La contrainte `mastery_scores_no_score_without_evidence` est le cœur du
  pas.** Un test vérifie que la base refuse un score sans évidence. Ne pas
  l'assouplir pour faire passer un cas.
- **Le poids 0,35 de la réussite au hasard est nommé et isolé** dans
  `MasteryCalculator::POIDS`. C'est un paramètre à réétalonner (DET-19), pas
  une constante à disperser dans le code.
- **`examSummary` et `prioritize` exposent toujours l'évidence avec le score.**
  Ne jamais ajouter un accesseur qui rende le score seul.
- **Aucune probabilité de réussite**, sous aucun nom. Un test parcourt les
  sorties JSON à la recherche de ces termes.

## Ce que ce pas ne fait pas

Endpoints HTTP, rappels espacés, mission du jour, questions miroir en série,
simulateur. Le recalcul reste synchrone (DET-20).
