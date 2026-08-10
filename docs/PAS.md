# Journal des pas de construction

**Convention opposable :** un pas n'est inscrit ici qu'avec son SHA de commit.
Un pas sans SHA n'est pas livré, quel que soit l'état du code.

| Pas | Contenu | Commit |
|---|---|---|
| 1 | Fondations multi-tenant : tenants, users, rôles, memberships, scope d'isolation | `c0ea420` |
| 1.1 | Correctif : isolation des écritures, contexte scoped, bypass journalisé, CI PostgreSQL | `29b9170` |
| 2 | Authentification : Sanctum cookies BFF, actes juridiques versionnés | `be7ac28` |
| 3 | Boucle fermée : vérification e-mail, mot de passe oublié, notifications FR/AR | `bc89fef` |
| 3.1 | Correctif : messages de validation traduits, garde structurelle | `89edadb` |
| — | Méthode de travail, ADR-0009 à 0011 | `c48a11d` |
| — | ADR-0012 et 0013 : taxonomie en arbre, vocabulaire du domaine | `42b980c` |
| 4 + 4.1 | Catalogue public et référentiel CRMEF 2025 : parcours, épreuves, matrices, carte du corpus | `59bc127` |
| 5 | Banque de questions : schéma, garde F03 en base, contrôles éditoriaux | `78cc77f` |
| 6 | Tentatives et réponses : idempotence, horloge serveur, Certitude+ | `02a3299` |
| 7 | Maîtrise et remédiation : évidence obligatoire, ordonnance motivée | `9fb8e24` |
| — | Fusion de la dette organisme restée hors registre | `f696db2` |
| 8 | Surface HTTP du parcours : diagnostic, correction, maîtrise, droits d'accès | `b58c2d6` |
| — | Backlog et grille d'audit (NAJA7I-BACKLOG-002) | `d643f96` |
| 9 | Permissions fines : référentiel, rôles par organisme, garde en base | `229ac7a` |
| 10 | Correctifs de revue : unicités tenant-aware, actes en ajout seul, contenu gelé | `cf88cc6` |
| 11 | Correctifs de revue 2 : garde d'appartenance, publication contrôlée en base, gel complet, quota atomique, première action protégée par permission | `82a4a77` |
| — | SHA du PAS-11 au journal et au backlog | `74aeec3` |
| 12 | Correctifs de contre-revue : sortie de l'état publié bornée au retrait, sources gelées, permission réservée refusée sur un rôle distribué, verrou de tentative | `83f79ff` |
| — | SHA du PAS-12 au journal et au backlog | `9f98ec9` |
| 13 | Sérialisation des invariants : verrou parent avant lecture du statut, déplacement d'enfant contrôlé sur les deux parents, état retiré gelé comme publié | `985a318` |

## Lots frontend

| Lot | Contenu | Commit |
|---|---|---|
| FRONT-1 | Socle Nuxt 3 + BFF, six écrans bilingues, recette manuelle | `43a140f` |
| — | Mise à jour de la recette après PAS-3.1 | `d72584c` |

## À venir

Séries d'entraînement, simulateur, rappels espacés, profil candidat, module
opportunités, commercial et CMI, back-office Filament. Voir `docs/BACKLOG.md`.
