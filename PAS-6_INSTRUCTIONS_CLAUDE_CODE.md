# PAS-6 — Instructions d'exécution pour Claude Code

**Dépôt :** `optimgov/Naja7i_backend_front`
**Objet :** tentatives et réponses — le cœur transactionnel.

---

## DÉCISIONS PRÉ-ARBITRÉES

| Situation | Décision |
|---|---|
| Colima / Docker arrêté | `colima start`, `docker compose up -d` |
| Migration en échec sur un type existant | `php artisan migrate:fresh --drop-types` sur la base de dev |
| Conflit sur un fichier de l'overlay | L'overlay fait foi |
| Tentation de calculer `is_correct` pendant la tentative | **Interdit.** Le candidat déduirait la réponse (ADR-0016 §4) |
| Tentation d'utiliser l'heure du client | **Interdit.** Serveur seul, ADR-0016 §3 |
| Tentation de lire `question.competency_node_id` au lieu de la copie | **Interdit.** ADR-0016 §5 |
| Tentation de remettre le quota de causes à zéro | **Interdit.** Cumulatif, fiche F03 |
| Test rouge | Corriger le code applicatif, jamais le test |
| PostgreSQL indisponible | S'arrêter et signaler. Jamais de repli SQLite |
| Choix de nommage | Trancher toi-même |

---

## Étapes

1. `colima start` puis `docker compose up -d`.

2. **Appliquer l'overlay.** Aucune fusion manuelle.

3. **Ajouter la clé de quota** dans `config/naja7i.php` :
   ```php
   'free_cause_quota' => env('FREE_CAUSE_QUOTA', 2),
   ```

4. **Ajouter au test d'architecture** (`TenancyArchitectureTest`) les trois
   nouvelles tables isolées : `attempts`, `attempt_items`, `responses` doivent
   figurer dans `TENANT_SCOPED_TABLES`.

5. **Migrer et tester** :
   ```bash
   php artisan migrate
   php artisan test
   ```
   Attendu : les tests précédents + 20 tests de tentatives. **0 rouge.**

6. **Vérification manuelle de la pondération** :
   ```bash
   php artisan tinker --execute="
     app(App\Tenancy\TenantContext::class)->set(App\Models\Tenant::where('kind','platform')->first());
     \$e = App\Models\Exam::where('code','CRMEF-SE-2025')->first();
     echo App\Services\DiagnosticComposer::class . ' prêt : ' . var_export(app(App\Services\DiagnosticComposer::class)->isReady(\$e,'fr'), true) . PHP_EOL;"
   ```
   Répond `false` tant que la banque est vide — c'est le comportement voulu.

7. **Style, commit, push, CI verte** :
   ```bash
   ./vendor/bin/pint
   git add -A
   git commit -m "PAS-6: tentatives et réponses — idempotence à deux niveaux, horloge serveur autoritative, correction figée à la soumission, série pondérée par les poids officiels, Certitude+ et quota de causes cumulatif"
   git push origin main
   ```

## Points de vigilance

- **Trois nouvelles tables isolées par tenant.** Elles portent `tenant_id` et
  `BelongsToTenant` — contrairement au catalogue.
- **Aucune réponse fantôme.** Une question sans réponse ne crée pas de ligne :
  un test le vérifie.
- **`answered_count` s'incrémente à la première réponse seulement**, pas aux
  suivantes.
- **Le diagnostic ne s'ouvre pas** si la banque est insuffisante. Un bouton
  absent vaut mieux qu'un diagnostic vide.

## Ce que ce pas ne fait pas

Endpoints HTTP, calcul de maîtrise, plan de remédiation, rappels espacés,
questions miroir. Les endpoints viendront avec le profil candidat ; la maîtrise
est le PAS-7.
