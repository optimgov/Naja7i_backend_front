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
| — | SHA du PAS-13, puis critères de sortie de sous-cycle au backlog | `d78be7b`, `de8ce18` |
| 14 | Gardes sur les nœuds du graphe d'autorisation : portée d'un rôle distribué immuable, is_staff et platform_only figés après distribution | `c628b2b` |
| 14.1 | Critère 3 porté par un test d'entrelacement ; tests de verrou ramenés à ce qu'ils prouvent ; DET-29 | `168f870` |
| 14.2 | Sens du changement de portée : la garde contrôle global → organisme, seul chemin créant l'état interdit | `e367c61` |
| — | SHA du PAS-14.2 au journal et au backlog | `ff42e9d` |
| — | Catalogue public : les épreuves d'une famille et leur coefficient, PAS-4.1 rendu visible | `91e5920` |
| — | SHA du catalogue public au journal, DET-30 | `3550e22` |
| 15 | Composition d'une session d'entraînement : périmètre jamais élargi, anti-répétition en base, sans chronomètre | `5043886` |
| — | SHA du PAS-15 au journal et au backlog, DET-31 | `98ed1d6` |
| 16 | Rendez-vous Mémoire (F07), première moitié : calendrier à casiers, paliers fixes, on planifie une erreur et non une question | `4b7ad75` |
| 17 | L'évitement cesse de payer : une question servie et sautée est comptée, entre dans l'urgence à facteur partiel et sous son propre motif | `5a97c19` |
| — | SHA du PAS-16 et du PAS-17 au journal et au backlog, DET-32 à DET-36 | `fc6e598` |
| 18 | Rendez-vous Mémoire, seconde moitié : les deux routes, la question sœur, le plafond annoncé, et DET-35 tranché — le couple avance, plus la question tracée | `23d4aa6` |
| — | SHA du PAS-18 au journal et au backlog, DET-35 clos, DET-37 et DET-38 | `7b29607` |
| 19 | Une cause déjà payée ne se reverrouille pas : la liste de révision tient la garantie du quota, DET-38 requalifié en défaut | `31b87e2` |
| — | SHA du PAS-19 au journal et au backlog, DET-38 clos et requalifié | `69e184b` |
| 20 | La suite passe de 249 s à ~117 s sans dépendance : semis une fois par processus, argon2id au coût de test — paratest écarté par la mesure | `d3320b0` |
| — | SHA du PAS-20 au journal et au backlog, DET-28 requalifié, DET-39 | `6f2cbf0` |
| 21 | Audit externe 490fc53, cinq bloquants : effets de bord derrière la garde de transition, planificateur verrouillé, énoncé resservi qui ne fait plus sortir, collisions d'ouverture rattrapées, empreinte d'idempotence | `aac1d7a` |
| — | SHA du PAS-21 au journal et au backlog, DET-36 clos, DET-40 | `752a10f` |
| 22 | L'énoncé resservi monte jusqu'au milieu de l'échelle et pas au-delà ; les couples sans sœur deviennent un plan de rédaction ordonné par la demande | `04b78e6` |
| — | SHA du PAS-22 au journal et au backlog, DET-41, DET-32 complété | `0d7cefc` |
| 23 | Index des tentatives du candidat : filtres, borne annoncée, aucune charge d'items — la reprise multi-appareil cesse d'être une béquille | `0cec306` |
| — | SHA du PAS-23 au journal et au backlog, DET-42 | `f1599cc` |
| 24 | Correctifs de l'index : correct_count nul avant soumission, dernière activité, exam_code sans oracle, no-store sur le chronomètre | `e62106c` |

## Lots frontend

| Lot | Contenu | Commit |
|---|---|---|
| FRONT-1 | Socle Nuxt 3 + BFF, six écrans bilingues, recette manuelle | `43a140f` |
| — | Mise à jour de la recette après PAS-3.1 | `d72584c` |

## À venir

Simulateur, question miroir (F05), profil candidat, module opportunités,
commercial et CMI, back-office Filament. Voir `docs/BACKLOG.md`.
