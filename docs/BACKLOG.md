# Backlog et grille d'audit — Naja7i backend front-office

**Identifiant :** `NAJA7I-BACKLOG-002` · **Version :** 1.0 — 9 août 2026
**Objet :** référentiel unique contre lequel le code doit être audité.
**Remplace :** toute grille d'audit antérieure non présente dans ce dépôt.

---

## 1. Autorité — à lire avant tout audit

Ce document est la **seule grille opposable** au code de
`optimgov/Naja7i_backend_front` et `optimgov/Naja7i_frontend`.

Un plan qui ne figure pas dans l'un de ces deux dépôts ne fait pas autorité,
quelle que soit sa qualité. C'est la règle §2 de `docs/METHODE.md` : *une
décision n'existe que lorsqu'elle est écrite dans une des sources déclarées.*

Hiérarchie, en cas de contradiction :

| Rang | Source | Emplacement |
|---|---|---|
| 1 | Fiches de règle métier | `docs/regles/` |
| 2 | Décisions d'architecture (ADR-0001 à 0018) | `docs/adr/` |
| 3 | Ce backlog | `docs/BACKLOG.md` |
| 4 | Journal des pas | `docs/PAS.md` |
| 5 | Registre de dette | `docs/DETTE.md` |

**Conséquence pour l'auditeur :** un livrable absent du code mais absent aussi
de ce backlog n'est **pas un échec**. C'est un lot non ouvert. Le signaler comme
manquant est une erreur de méthode, pas un constat.

## 2. Convention de numérotation

- `PAS-n` — incrément de fonctionnalité, numéroté dans l'ordre de livraison.
- `PAS-n.1` — correctif d'un pas déjà poussé. Ne remplace pas l'original.
- `FRONT-n` — lot d'interface.
- La numérotation est **historique**, pas thématique. `PAS-7` désigne ce qui a
  été livré au septième pas, rien d'autre.
- Un pas n'est inscrit au journal **qu'avec son SHA de commit**. Sans SHA, il
  n'est pas livré.

---

## 3. Pas livrés — périmètre et critères d'acceptation

### PAS-1 — Fondations multi-tenant · `c0ea420`
**Périmètre :** tenants, users globaux, 7 rôles, memberships, scope d'isolation.
**Acceptation :** un tenant plateforme unique créé par migration ; toute lecture
Eloquent sur table isolée exige un contexte ; tests négatifs verts.

### PAS-1.1 — Correctif de revue · `29b9170`
**Origine :** audit externe, trois bloquants.
**Acceptation :** écritures inter-tenant refusées ; `TenantContext` en binding
scoped ; `TenantBypass` seul point de sortie, journalisé ; test architectural
qui échoue la CI en cas de contournement ; CI PostgreSQL opérationnelle.

### PAS-2 — Authentification et actes juridiques · `be7ac28`
**Acceptation :** session par cookie httpOnly via BFF, aucun jeton en
JavaScript ; CGU / confidentialité / marketing traités comme trois actes de
nature distincte ; mot de passe 12 caractères + contrôle anti-fuite ;
limitation sur trois agrégats ; `request_id` sur toutes les erreurs.

### PAS-3 — Boucle d'authentification fermée · `bc89fef`
**Acceptation :** vérification d'e-mail par jeton opaque haché, usage unique ;
mot de passe oublié ; notifications FR/AR ; endpoints muets sur l'existence
d'un compte.

### PAS-3.1 — Messages de validation traduits · `89edadb`
**Acceptation :** 166 clés FR et AR, parité vérifiée ; garde structurelle qui
échoue si une clé n'est pas traduite.

### Méthode et décisions · `c48a11d`, `42b980c`, `f696db2`
**Contenu :** `METHODE.md`, ADR-0009 à 0014, registre de dette consolidé.

### PAS-4 + PAS-4.1 — Catalogue et référentiel CRMEF · `59bc127`
**Acceptation :** catalogue global sans `tenant_id` ; 3 filières, 11 familles ;
parcours ; 11 spécialités secondaires ; **3 épreuves distinctes** de
coefficients 8/12/20 ; matrices de domaines dont les poids somment à 100 ;
carte de couverture 32 descriptifs dont 3 transposés ; rien d'inventé.

### PAS-5 — Banque de questions · `78cc77f`
**Acceptation :** question monolingue liée par `sibling_group` ; justification
obligatoire sur chaque option ; cause interdite sur la bonne réponse ; **trigger
refusant l'éligibilité au diagnostic si un distracteur n'est pas étiqueté** ;
sources de contenu distinctes de la source de blueprint ; valideur ≠ auteur.

### PAS-6 — Tentatives et réponses · `02a3299`
**Acceptation :** idempotence à deux niveaux ; horloge serveur autoritative ;
`is_correct` nul jusqu'à soumission ; rattachement de compétence copié ; série
pondérée par les poids officiels ; Certitude+ ; quota de causes cumulatif.

### PAS-7 — Maîtrise et remédiation · `9fb8e24`
**Acceptation :** contrainte en base interdisant un score sans évidence
suffisante ; pondération par la certitude ; agrégation par poids officiels ;
ordonnance motivée ; **aucune probabilité de réussite**, testé.

### PAS-8 — Surface HTTP du parcours · `b58c2d6`
**Acceptation :** deux ressources séparées (présentation / correction) ; aucune
fuite de `rationale`, `cause` ou `is_correct` pendant la tentative ; contrat
`AccessGrant` seul point d'autorisation produit ; démonstration publique
marquée `is_example` ; 404 jamais 403 entre candidats.

### PAS-9 — Permissions fines appliquées · `229ac7a`
**Acceptation :** référentiel de 19 permissions en données, pas en code ; rôles
définissables par organisme, codes uniques par portée ; une permission réservée
à la plateforme n'est jamais attachable à un rôle d'organisme, garde en base ;
résolution sans cache au-delà de la requête. Exécute l'ADR-0009, décidé au
PAS-2 et resté inappliqué — l'écart G10 le plus visible du dépôt.

### PAS-10 — Correctifs de revue · `cf88cc6`
**Acceptation :** unicités portant le tenant ; actes juridiques en ajout seul ;
contenu publié gelé ; transitions éditoriales par service unique, les champs de
transition hors de `$fillable` ; consommation atomique des jetons ; garde
architecturale cherchant les CHEMINS d'accès bas niveau et non les noms de
tables — `DB::table($variable)` et `DB::connection()->table(...)` sont désormais
détectés.

### PAS-11 — Correctifs de revue 2 · `82a4a77`
**Acceptation :** un rôle de back-office de plateforme n'est jamais attribuable
dans un organisme ; la publication est contrôlée en base quel que soit le
chemin d'écriture ; le gel d'une question publiée procède par liste blanche, donc
couvre les colonnes futures ; le quota de causes est réservé avant marquage, la
ressource rare étant le quota et non la réponse ; première action protégée par
une permission déclarée sur la route.

### PAS-12 — Correctifs de contre-revue · `83f79ff`
**Acceptation :** une question publiée ne sort de son état que par le retrait,
la garde raisonnant sur l'ÉTAT visé et non sur la transition empruntée
(ADR-0022) ; les sources d'une question publiée sont gelées, elles fondent la
correction déjà servie ; une permission réservée est refusée sur un rôle déjà
distribué hors plateforme, et le résolveur la refuse encore si la table a été
corrompue par un autre chemin ; `answer()` réclame le verrou de la tentative
avant de relire son état, prouvé par un entrelacement réel sur une seconde
connexion PostgreSQL — un test séquentiel ne prouve rien sur une course.

### PAS-13 — Sérialisation des invariants · `985a318`
**Acceptation :** le statut du parent est lu sous verrou, jamais en lecture
optimiste ; un enfant déplacé est contrôlé sur ses DEUX parents, l'ancien comme
le nouveau ; l'état `retired` est gelé au même titre que `published` — une
correction déjà servie ne se réécrit pas davantage après retrait ; les triggers
d'appartenance et d'attachement de permission se donnent rendez-vous sur la
ligne `roles`, ce qui sérialise deux chemins qui pouvaient auparavant se croiser.

**Épreuve :** les quatre gardes sont éprouvées par mutation, chacune couverte
par exactement un test qui vire au rouge sans son verrou. Deux des tests livrés
ne discriminaient pas — l'un mesurait une autre garde, l'autre une clé
étrangère — et ont été doublés de tests isolants (`FOR NO KEY UPDATE`,
verrouillage d'enfant sans déclenchement de trigger).

### FRONT-1 — Socle d'interface · `43a140f`, `d72584c`
**Acceptation :** relais BFF, aucun appel direct du navigateur vers l'API ;
six écrans bilingues avec RTL ; recette manuelle en 11 points documentée.

---

## 4. Pas non ouverts — ne pas auditer comme manquants

| Lot | Contenu | Statut |
|---|---|---|
| Séries d'entraînement ciblées | Composition adaptative | Non ouvert |
| Simulateur d'examen | Chronomètre, barème, rapport | Non ouvert |
| Rappels espacés (F07) | Rendez-vous Mémoire | Non ouvert |
| Profil candidat | Situation, objectif, échéance | Non ouvert |
| Module Opportunités | Veille, annonces, alertes | Non ouvert |
| Commercial et CMI | Offres, commandes, paiement | Non ouvert |
| Back-office Filament | Rédaction, révision, publication | Non ouvert — lot A4 |
| Journal d'audit administratif | Traçabilité des actions back-office | **Non planifié — voir §7** |
| Row-Level Security PostgreSQL | Isolation au niveau base | Différée au gate B2B (ADR-0002) |
| MFA | Second facteur staff | Non ouvert (ADR-0009 §4) |

---

## 5. Garde-fous transversaux — G1 à G10

Ce sont les invariants à contrôler **sur tout pas, à tout moment**. Chacun est
tiré d'un ADR et devrait être exécuté par un test ou une contrainte.

| ID | Invariant | Source | Vérification attendue |
|---|---|---|---|
| **G1** | Isolation tenant : lecture et écriture ; 404 jamais 403 ; bypass journalisé | ADR-0002, ADR-0006 | `TenantIsolationTest`, `TenantWriteIsolationTest`, `TenancyArchitectureTest` |
| **G2** | Aucun identifiant interne exposé ; UUIDv7 public | ADR-0002 | `ApiContractTest` — parcours récursif du JSON |
| **G3** | Provenance : rien d'inventé, valeur nulle si non sourcée | ADR-0014 | `ReferentielCrmefTest` — aucun `official_question_count` |
| **G4** | Le serveur est seul juge ; aucun contrôle d'accès côté client | METHODE §7.1 | Tests HTTP d'autorisation |
| **G5** | Aucun score sans volume d'évidence ; aucune prédiction de réussite | ADR-0017, METHODE §7.3 | Contrainte SQL + balayage des sorties JSON |
| **G6** | Intégrité éditoriale : justification par option, cause étiquetée, valideur ≠ auteur | ADR-0015, fiche F03 | Trigger `assert_diagnostic_eligibility` + `QuestionIntegrityChecker` |
| **G7** | Bilinguisme FR/AR de premier rang, RTL par propriétés logiques | ADR-0005, FRONT-1 | Aucune clé brute, aucune propriété CSS physique |
| **G8** | Traçabilité juridique : actes versionnés, jamais écrasés, IP tronquée | ADR-0005 | `AuthenticationTest` — historique préservé |
| **G9** | Robustesse : idempotence, horloge serveur, aucune réponse perdue | ADR-0016 | `TentativesTest` |
| **G10** | Toute règle annoncée est exécutée par un test ou une contrainte, jamais seulement documentée | METHODE §1 | Absence d'écart X01–X12 |

**G10 est le méta-invariant.** Un audit utile cherche d'abord les règles
énoncées dans un ADR et non tenues par le code. C'est ce qui a produit les
correctifs PAS-1.1 et PAS-3.1.

---

## 6. Écarts connus et assumés — ne pas les redécouvrir comme bloquants

Ces points sont **déjà identifiés**, tracés au registre, et ordonnancés. Les
signaler à nouveau est du bruit ; les hiérarchiser autrement est un apport.

**Fermés depuis la dernière révision :** les permissions fines de l'ADR-0009
sont appliquées (PAS-9) et la garde architecturale ne se contourne plus par un
nom de table dynamique ni par `DB::connection()` (PAS-10, vérifié en rejouant
l'ancienne garde contre les deux contournements).

| Constat | Registre | Statut |
|---|---|---|
| Frontend sans tests automatisés ni CI applicative | À ouvrir | **Écart réel** |
| Suite de tests non parallélisable : `--parallel` ne démarre pas, paratest absent | DET-28 | Quand la durée le justifiera |
| Textes juridiques provisoires en base | DET-07 | Bloque l'ouverture publique |
| Qualification juridique non validée par un juriste | DET-08 | Bloque l'ouverture publique |
| Fournisseur d'e-mail non choisi | DET-09 | Bloque le pilote |
| Écart NIST assumé : 12 caractères sans MFA | DET-10 | Documenté, ADR-0007 |
| Coefficient 0,35 de la réussite au hasard, valeur d'architecte | DET-19 | À réétalonner |
| Facteur 0,5 des domaines jamais évalués | DET-22 | À réétalonner |
| Recalcul de maîtrise synchrone | DET-20 | Avant montée en charge |
| Niveau « domaine » du CRMEF non défini | DET-27 | Avant production de contenu |
| Quatre questions commerciales du modèle organisme | DET-23 à DET-26 | Avant premier contrat |

---

## 7. Ce que ce plan ne prévoit pas, et pourquoi

**Journal d'audit administratif chaîné et ancrage WORM.** Aucun lot ne le
prévoit à ce jour. Ce n'est pas un oubli d'exécution mais une absence de
décision : le back-office n'existe pas encore, donc aucune action
administrative n'est à tracer.

La question reste ouverte et mérite d'être posée au moment du lot back-office —
une plateforme dont les contenus engagent la préparation de candidats a un
intérêt réel à prouver qui a publié quoi et quand. Mais tant qu'elle n'est pas
arbitrée et inscrite ici, **son absence n'est pas un échec d'audit**.

---

## 8. Protocole d'audit

**Format attendu pour chaque constat bloquant :**

1. Section ou fichier visé, avec ligne.
2. Scénario qui échoue, reproductible.
3. Conséquence concrète pour un candidat ou pour les données.
4. Correction minimale.
5. Test d'acceptation qui prouverait la correction.

**Sévérités :**

| Niveau | Traitement |
|---|---|
| Bloquant | Correctif immédiat, pas décimal |
| Majeur | Correctif au pas suivant |
| Mineur | Registre de dette, avec échéance |

**Limites :** cinq bloquants maximum par pas. Deux rounds de correction
maximum, puis arbitrage humain.

**Ce qui ne constitue pas un constat recevable :**

- Un livrable absent de ce backlog présenté comme manquant.
- Un désaccord de conception sans scénario d'échec.
- Une préférence de style ou de nommage.
- Un constat déjà inscrit au §6, sauf si sa sévérité est contestée avec un
  scénario.

**Arbitrage.** Un désaccord entre l'architecte et l'auditeur remonte à
OptimGov. Il n'est jamais tranché entre eux (`METHODE.md` §4).

---

## 9. Comment vérifier localement

```bash
git clone https://github.com/optimgov/Naja7i_backend_front.git
cd Naja7i_backend_front
cp .env.example .env && php artisan key:generate     # APP_KEY requis
docker compose up -d                                  # PostgreSQL 16 + Redis
php artisan migrate && php artisan test               # séquentiel
```

La suite **n'est pas parallélisable en l'état** : voir §6. `--parallel` ne
démarre pas — Collision exige `brianium/paratest`, absent des dépendances. Le
constat d'interblocage sur les suppressions de tables est antérieur et n'a pas
pu être reproduit au PAS-9 : il reste à vérifier une fois paratest ajouté
(DET-28). La CI exécute la suite en séquentiel.
