# PAS-4 — Instructions d'exécution pour Claude Code

**Dépôt :** `optimgov/Naja7i_backend_front`
**Objet :** catalogue public — filières, familles de concours, spécialités,
sessions, et taxonomie de compétences en arbre (ADR-0012, ADR-0013).

**Pourquoi ce pas compte :** ce sont ces endpoints qui alimenteront les pages
indexables par Google — le levier d'acquisition du plan à 90 jours. Et c'est le
premier pas qui matérialise la règle « ajouter un concours est une opération de
données, jamais de code ».

---

## DÉCISIONS PRÉ-ARBITRÉES — ne pose aucune question sur ces points

| Situation | Décision |
|---|---|
| Colima / Docker arrêté | `colima start`, `docker compose up -d` |
| Base `naja7i_test` absente | `docker compose exec -T postgres createdb -U naja7i naja7i_test` |
| Conflit sur un fichier de l'overlay | L'overlay fait foi, écraser |
| Tentation d'ajouter `tenant_id` à une table de catalogue | **Interdit.** Le catalogue est global (ADR-0002, ADR-0013). Un test le vérifie |
| Tentation d'utiliser `BelongsToTenant` sur un modèle de catalogue | Interdit, même raison |
| Le test d'architecture existant réclame une liste blanche | Ne pas y ajouter les modèles de catalogue : ils n'ont pas besoin de bypass, ils n'ont pas de scope |
| Migration en échec sur un type existant | `php artisan migrate:fresh` sur la base de dev uniquement |
| Test rouge | Corriger le code applicatif, jamais le test |
| PostgreSQL indisponible | S'arrêter et signaler. Jamais de repli SQLite |
| Choix de nommage | Trancher toi-même |

**Arrête-toi uniquement si :** perte de données possible, dépôt incorrect, ou
PostgreSQL indisponible.

---

## Étapes

1. **Vérifier le départ** : `git log --oneline` doit montrer le commit des ADR
   0012 et 0013. `colima start` puis `docker compose up -d`.

2. **Appliquer l'overlay.** Trois fusions manuelles, le reste se copie :
   - `routes/api-pas4-additions.php` → fusionner dans la **section publique**
     du groupe `Route::prefix('v1')` de `routes/api.php`, puis supprimer le
     fichier d'additions.
   - `lang/fr/errors.php.append` et `lang/ar/errors.php.append` → fusionner la
     clé `not_found` dans les fichiers `errors.php` existants, puis supprimer
     les `.append`. Si `errors.php` n'existe pas, le créer.

3. **Enregistrer le seeder** dans `database/seeders/DatabaseSeeder.php` :
   ```php
   $this->call(CatalogueSeeder::class);
   ```

4. **Migrer, semer, tester** :
   ```bash
   php artisan migrate
   php artisan db:seed --class=CatalogueSeeder
   php artisan test
   ```
   Attendu : les tests des pas précédents + 18 nouveaux tests de catalogue.
   **0 rouge.**

5. **Vérification manuelle** (deux minutes, elles valent la peine) :
   ```bash
   curl -s localhost:8000/api/v1/catalogue | head -40
   curl -s localhost:8000/api/v1/catalogue/familles/crmef | head -60
   curl -s -H "Accept-Language: ar" localhost:8000/api/v1/catalogue/familles/crmef/competences
   ```
   Le troisième doit répondre en arabe, avec les noms de niveaux traduits.

6. **Style, commit, push, CI verte** :
   ```bash
   ./vendor/bin/pint
   git add -A
   git commit -m "PAS-4: catalogue public — filières, familles, spécialités, sessions datées et sourcées, taxonomie en arbre à profondeur libre par famille"
   git push origin main
   ```

---

## Points de vigilance

- **Le catalogue est global.** Aucune de ces six tables ne porte `tenant_id`,
  aucun de ces modèles n'utilise `BelongsToTenant`. C'est la règle la plus
  facile à enfreindre par réflexe, et la plus coûteuse à réparer une fois des
  données en place.
- **Rien de non publié ne doit sortir.** Un brouillon éditorial indexé par
  Google avant relecture serait un incident réel. Trois tests le vérifient.
- **404, jamais 403**, pour une ressource non publiée : un 403 confirmerait son
  existence et laisserait deviner le catalogue à venir.
- **`dates_confirmed` sort toujours.** Les dates de concours circulent sur les
  réseaux avant leur publication officielle. Ne jamais rendre ce champ
  optionnel dans une ressource : le frontend doit pouvoir signaler visuellement
  une date non vérifiée.
- **La profondeur de taxonomie est libre.** Ne pas « simplifier » en écrivant
  du code qui suppose quatre niveaux : c'est précisément ce que l'ADR-0012
  interdit.
- **Les dates du seeder ne sont pas sourcées.** Elles sont marquées non
  confirmées à dessein. Ne pas inventer de vraies dates.

## Ce que ce pas ne fait pas

Annales, ressources éditoriales, questions, blueprints, back-office de saisie.
Le catalogue est la structure ; le contenu vient ensuite.
