# Fiches de règle métier

Une fiche par fonction. Elle fait autorité sur le comportement attendu — le
code et les tests la citent, ils ne la réinterprètent pas.

**Aucune fonction n'est codée sans sa fiche validée.** Voir `docs/METHODE.md` §3.

| Fiche | Fonction | Statut |
|---|---|---|
| `F03-autopsie-de-l-erreur.md` | Autopsie de l'erreur | **validée** — v1.1, 8 août 2026 |
| `F01-carte-de-maitrise.md` | Carte de maîtrise | brouillon reconstruit — v0.1, 21 août 2026 |
| `F02-certitude-plus.md` | Certitude+ | brouillon reconstruit — v0.1, 21 août 2026 |
| `F04-pourquoi-pas-b.md` | Correction par élimination | brouillon reconstruit — v0.1, 21 août 2026 |
| `F05-question-miroir.md` | Question miroir | brouillon reconstruit, non-consommation tranchée — v0.1, 21 août 2026 |
| `F06-ordonnance-najah.md` | Ordonnance Najah | brouillon reconstruit — v0.1, 21 août 2026 |
| `F07-rendez-vous-memoire.md` | Rendez-vous Mémoire | brouillon autonome révisé, non-consommation tranchée — v0.3, 21 août 2026 |
| `F09-mission-du-jour.md` | Priorités de préparation, vue F06 | brouillon autonome requalifié — v0.3, 21 août 2026 |

## Fonctions en attente de fiche

Rédigées au moment où le pas qui les implémente est ouvert, pas avant.

| ID | Fonction | Pas concerné |
|---|---|---|
| F08 | Indice de préparation | Maîtrise |
| F10 | Atlas des pièges | Après données réelles |
| F11a | Passeport de source | Back-office |
| F11b | Radar Réformes | Après données réelles |
| F12 | SimuClasse | Après validation |
| F13 | Lexique bilingue | Après données réelles |
| F14 | Copilote qualité éditorial | Back-office |

**Note importante :** le document `NAJAH-FONC-ORIG-001`, cité comme source par
`NAJAH-INV-001`, n'existe pas. Les règles de ces fonctions ne sont écrites nulle
part. Chaque fiche est donc reconstruite à partir de la ligne d'inventaire, du
prototype et du cadrage — puis validée. C'est la raison d'être de ce dossier.

Les brouillons intégrés le 21 août 2026 rendent désormais ces règles visibles,
mais ne les valident pas : toute rubrique « À trancher » doit être vidée avant
gel conformément à `docs/METHODE.md` §3.
