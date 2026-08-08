# PAS-4.1 — Instructions d'exécution pour Claude Code

**Dépôt :** `optimgov/Naja7i_backend_front`
**Objet :** corriger le catalogue d'après les descriptifs officiels CRMEF
novembre 2025, et charger les trois matrices officielles.

**Pourquoi ce correctif :** le PAS-4 traitait « sciences de l'éducation »,
« didactique » et « spécialité » comme trois piliers de taxonomie. Ce sont
trois **épreuves** distinctes, de coefficients 8, 12 et 20, de durées 120, 120
et 240 minutes, chacune avec sa propre matrice de domaines. Bâtir un
simulateur sur la version précédente aurait produit des scores sans rapport
avec le concours réel.

---

## DÉCISIONS PRÉ-ARBITRÉES — ne pose aucune question sur ces points

| Situation | Décision |
|---|---|
| Colima / Docker arrêté | `colima start`, `docker compose up -d` |
| Le PAS-4 n'est pas encore poussé | Sans importance : les migrations de ce lot s'ajoutent aux siennes |
| Migration en échec sur un index ou un type existant | `php artisan migrate:fresh` puis reseeder. Aucune donnée de production n'existe |
| Tentation de remplir un coefficient manquant | **Interdit.** Ce qui n'est pas dans la source reste nul (ADR-0014 §5) |
| Tentation de créer les épreuves des spécialités fermées | **Interdit.** Fiche catalogue oui, épreuve non |
| Tentation d'ajouter « Sciences économiques » ou « Technologie » | **Interdit.** L'inventaire des sources fait foi : onze disciplines au secondaire |
| Tentation de fusionner les trois sources « Sciences de l'éducation » | **Interdit.** L'inventaire ne dit pas s'il s'agit du même document. Trois entrées, incertitude notée |
| Tentation d'arrondir un poids pour que la somme fasse 100 | **Interdit.** Si la somme ne tombe pas juste, c'est une erreur de saisie : la corriger, pas la masquer |
| Conflit sur un fichier de l'overlay | L'overlay fait foi |
| Test rouge | Corriger le code applicatif, jamais le test |
| PostgreSQL indisponible | S'arrêter et signaler. Jamais de repli SQLite |

**Arrête-toi uniquement si :** perte de données possible, dépôt incorrect, ou
PostgreSQL indisponible.

---

## Étapes

1. **Vérifier l'état** : `git log --oneline`, `colima start`, `docker compose up -d`.

2. **Appliquer l'overlay.** Aucune fusion manuelle cette fois : les fichiers
   s'ajoutent ou remplacent.

3. **Mettre à jour les modèles du PAS-4** — trois retouches à faire toi-même :
   - `App\Models\CompetencyNode` : la relation `family()` devient `exam()`
     (`belongsTo(Exam::class)`), et le scope `descendantsOf` filtre sur
     `exam_id` au lieu de `exam_family_id`. Ajouter `weight_percent`,
     `source_id`, `provenance`, `exam_id` au `$fillable` et
     `exam_id`, `source_id` au `$hidden`. `levelName()` passe par
     `$this->exam?->taxonomyProfile`.
   - `App\Models\TaxonomyProfile` : ajouter `exam_id` au `$fillable`, la
     relation `exam()`, et `exam_id` au `$hidden`.
   - `App\Models\Specialty` : ajouter `track_id` au `$fillable` et au
     `$hidden`, plus la relation `track()`.

4. **Adapter `CatalogueController::competencies`** : il interroge aujourd'hui
   `exam_family_id`. Le référentiel étant par épreuve, ajouter une route
   `catalogue/epreuves/{code}/competences` qui filtre sur `exam_id`, et faire
   répondre l'ancienne route 404 si la famille n'a plus de taxonomie propre.
   Exposer aussi `weight_percent`, `provenance` et le code de source dans
   `CompetencyNodeResource`.

5. **Enregistrer le seeder** dans `DatabaseSeeder` :
   ```php
   $this->call([CatalogueSeeder::class, Crmef2025Seeder::class]);
   ```

6. **Migrer, semer, tester** :
   ```bash
   php artisan migrate:fresh
   php artisan db:seed
   php artisan test
   ```
   Attendu : les tests précédents + 22 tests de référentiel. **0 rouge.**

7. **Vérification manuelle du plus important** :
   ```bash
   php artisan tinker --execute="
     foreach (App\Models\Exam::all() as \$e) {
       \$s = App\Models\CompetencyNode::where('exam_id', \$e->id)->whereNull('parent_id')->sum('weight_percent');
       echo \$e->code . ' coef ' . \$e->coefficient . ' — somme des domaines : ' . \$s . PHP_EOL;
     }"
   ```
   Les trois lignes doivent afficher 100.

8. **Vérifier la carte du corpus** :
   ```bash
   php artisan tinker --execute="
     echo 'Corpus officiel : ' . App\Models\Source::count() . ' descriptifs' . PHP_EOL;
     echo 'Transposés : ' . App\Models\Source::where('transposition_status','transpose')->count() . PHP_EOL;
     echo 'Restants : ' . App\Models\Source::where('transposition_status','identifie_non_transpose')->count() . PHP_EOL;"
   ```
   Attendu : 32, 3, 29.

9. **Style, commit, push, CI verte** :
   ```bash
   ./vendor/bin/pint
   git add -A
   git commit -m "PAS-4.1: référentiel CRMEF 2025 — parcours, onze spécialités secondaires, trois épreuves séparées avec coefficients officiels, matrices de domaines et poids, carte de couverture des 32 descriptifs, provenance des données"
   git push origin main
   ```

---

## Points de vigilance

- **Ne jamais inventer.** Nombre de questions, barème, seuil d'admission,
  coefficients non documentés : tout reste nul. C'est la faute la plus
  coûteuse possible sur ce produit — un candidat qui organise ses révisions
  sur un coefficient inventé est trompé sur l'essentiel.
- **Les trois épreuves restent étanches.** Un test vérifie qu'aucun code de
  domaine n'apparaît dans deux matrices.
- **Douze spécialités ont une fiche, pas d'épreuve.** C'est voulu : leurs
  descriptifs existent mais n'ont pas été transposés. Ne pas « compléter ».
- **Les poids sont vérifiés arithmétiquement.** Enfants = poids du parent,
  racines = 100. Si un test échoue, c'est la saisie qui est fausse.
- **Provenance obligatoire.** Un choix éditorial ne s'affiche jamais comme une
  caractéristique officielle du concours.
- **La carte du corpus doit rester exacte.** 32 descriptifs recensés, 3
  transposés. Ne jamais marquer `transpose` une source dont la matrice n'est
  pas réellement en base : ce champ sert à décider quel concours ouvrir.

## Ce que ce pas ne fait pas

La banque de questions, les remédiations, les questions miroir et les rappels
différés — c'est le PAS-6, qui reprendra le schéma de question et les statuts
éditoriaux du référentiel.
