# Journal des pas de construction

| Pas | Contenu | Statut |
|---|---|---|
| 1 | Fondations : Laravel API-only, tenants, users, roles ×7, memberships, scope tenant, ADR 0001-0003 | livré · `c0ea420` |
| 1.1 | Correctif de revue : isolation des écritures, TenantContext scoped, TenantBypass, CI PostgreSQL, ADR-0006 | livré · `29b9170` |
| 2 | Authentification : Sanctum cookies BFF, actes juridiques versionnés, mot de passe 12 car., rate limiting 3 agrégats, ADR 0004/0005/0007 | livré · `be7ac28` |
| 3 | Boucle fermée : vérification d'e-mail par jeton opaque, mot de passe oublié, notifications FR/AR, Mailpit, ADR-0008 | livré 7 août 2026 |
| 3.1 | Correctif issu de la recette FRONT-1 (point 10) : `validation.php` FR/AR absents, les messages s'affichaient en clé brute (`validation.min.string`). Catalogue traduit en entier, libellés de champs lisibles, garde de non-régression sur tout le catalogue | livré 8 août 2026 |
| 4 | Profil progressif par situation (bachelier, étudiant supérieur, lauréat, en poste) | à venir |
| 5 | Catalogue public : portails, familles, concours, sessions, spécialités | à venir — dépend des décisions D02/D03 |
