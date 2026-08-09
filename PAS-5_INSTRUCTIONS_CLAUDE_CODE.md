# PAS-5 — Instructions d'exécution pour Claude Code

**Dépôt :** `optimgov/Naja7i_backend_front` — état attendu : `59bc127` ou plus récent
**Objet :** banque de questions — schéma, garde-fous d'intégrité, contrôles éditoriaux.

**Ce que ce pas établit :** ce qu'une question doit contenir pour être digne de
confiance. Pas encore comment elle est présentée au candidat — c'est le pas
suivant.

---

## DÉCISIONS PRÉ-ARBITRÉES — ne pose aucune question sur ces points

| Situation | Décision |
|---|---|
| Colima / Docker arrêté | `colima start`, `docker compose up -d` |
| Base `naja7i_test` absente | `docker compose exec -T postgres createdb -U naja7i naja7i_test` |
| Migration en échec sur un type existant | `php artisan migrate:fresh --drop-types` sur la base de dev |
| Conflit sur un fichier de l'overlay | L'overlay fait foi |
| Tentation de générer des questions de contenu | **Interdit.** Voir « Ce que ce pas ne fait pas » ci-dessous |
| Tentation d'assouplir le trigger d'éligibilité | **Interdit.** C'est la garde de la fiche F03 |
| Tentation de rendre `rationale` nullable | **Interdit.** Une option sans justification n'est pas publiable, par conception |
| Le trigger différé complique un test | Utiliser une transaction dans le test, pas modifier le trigger |
| Test rouge | Corriger le code applicatif, jamais le test |
| PostgreSQL indisponible | S'arrêter et signaler. Jamais de repli SQLite |
| Choix de nommage | Trancher toi-même |

**Arrête-toi uniquement si :** perte de données possible, dépôt incorrect, ou
PostgreSQL indisponible.

---

## Étapes

1. **Vérifier l'état** : `git log --oneline`, `colima start`, `docker compose up -d`.

2. **Appliquer l'overlay.** Aucune fusion manuelle : tout s'ajoute.

3. **Vérifier que `CompetencyNode` expose bien `exam_id`** — le PAS-4.1 l'a
   ajouté. Les questions s'y rattachent.

4. **Migrer et tester** :
   ```bash
   php artisan migrate
   php artisan test
   ```
   Attendu : les tests précédents + 18 tests de banque. **0 rouge.**

5. **Vérification manuelle de la garde F03** — c'est le contrôle qui compte :
   ```bash
   php artisan tinker
   ```
   Créer une question avec un distracteur non étiqueté, puis tenter
   `$q->update(['eligible_for_diagnostic' => true])`. Doit lever une exception
   mentionnant la fiche F03. Si ça passe, le trigger n'est pas actif.

6. **Style, commit, push, CI verte** :
   ```bash
   ./vendor/bin/pint
   git add -A
   git commit -m "PAS-5: banque de questions — questions monolingues versionnées, justification obligatoire par option, éligibilité au diagnostic gardée en base (fiche F03), sources de contenu distinctes du blueprint"
   git push origin main
   ```

---

## Points de vigilance

- **Le trigger est DIFFÉRÉ**, et c'est nécessaire : une question et ses options
  se créent dans la même transaction. Un contrôle immédiat échouerait sur une
  question encore sans options. Ne pas le rendre immédiat « pour simplifier ».
- **`rationale` est obligatoire sur toutes les options**, bonne réponse
  comprise. C'est la fonction « Pourquoi pas B ? » qui en dépend.
- **La cause n'existe que sur les distracteurs.** Une contrainte l'impose.
- **Une question publiée ne se modifie pas.** Nouvelle version, ancienne
  retirée. Ne pas ajouter de chemin de mise à jour direct.
- **Le valideur n'est jamais l'auteur.** Contrôle appliqué, pas seulement
  documenté.

## Ce que ce pas ne fait pas — et pourquoi

**Aucune question de contenu n'est créée.** Le référentiel CRMEF demandait une
banque de démonstration de soixante questions. Ce lot n'en produit aucune, pour
une raison qui tient au produit et non à la technique : rédiger soixante
questions sur des contenus pédagogiques qu'aucun expert n'a validés créerait
exactement le risque contre lequel le référentiel met en garde — du contenu
d'apparence crédible, invérifiable, et qui finirait par circuler.

Les questions du jeu de tests existent uniquement dans les tests, et n'entrent
jamais en base de développement.

La banque doit être écrite par les responsables pédagogiques, avec le gabarit
que ce schéma impose. Ce que le lot fournit, c'est la garantie qu'une question
incomplète ne pourra pas être publiée.

**Pas non plus :** endpoints de consultation, composition de séries, tentatives,
calcul de maîtrise. Ce sont les pas suivants.
