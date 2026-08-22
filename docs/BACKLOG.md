# Backlog et grille d'audit — Naja7i backend front-office

> **Où l'on va** se lit dans [`docs/JALONS.md`](JALONS.md) — les quatre jalons
> restants et les décisions réservées au propriétaire. Le présent fichier est le
> journal de **ce qui a été livré**.

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

### PAS-14 — Gardes sur les nœuds du graphe d'autorisation · `c628b2b`
**Acceptation :** les gardes des PAS-9 à 13 portaient sur les ARÊTES du graphe
d'autorisation — `memberships`, `permission_role` ; celles-ci ferment ses NŒUDS
— `roles`, `permissions` — dont muter un attribut après distribution
contournait les précédentes. La portée d'un rôle distribué est figée ; `is_staff`
et `platform_only` ne se posent plus après distribution hors plateforme ; le
sens inverse — lever une réservation — reste ouvert, conformément au quatrième
critère de sortie du §8.

**Épreuve :** sept mutations, les deux verrous et les cinq conditions prises
séparément, toutes détectées.

### PAS-14.1 — Critère 3 du §8 porté par un test d'entrelacement · `168f870`
**Origine :** revue PAS-14, arbitrage d'OptimGov.
**Acceptation :** les deux tests de verrou sont ramenés à ce qu'ils établissent
— le verrou est réclamé, l'ordre parent-puis-enfants est tenu — et ne
revendiquent plus de fermer une escalade. L'invariant a son propre test, sur
deux sessions et dans les deux ordres, qui vérifie l'ISSUE et non le mécanisme :
aucun rôle back-office de plateforme ne porte d'appartenance hors plateforme.

**Contre-preuve, deux mutations disjointes :** retirer le verrou du PAS-14 fait
tomber le seul test de verrou, l'invariant restant vert — c'est une défense de
profondeur assumée ; retirer le rendez-vous du PAS-13 sur la ligne `roles` fait
tomber le seul test d'invariant. Le critère 3 est rempli : le test de
concurrence prouve l'entrelacement recherché et n'observe pas un verrou
incident.

### PAS-14.2 — Le sens du changement de portée · `e367c61`
**Origine :** contre-revue PAS-14.1, un bloquant.
**Nature :** inversion de sens, pas trou de couverture. La garde du PAS-14
contrôlait `organisme → global` alors que l'état interdit — un rôle d'organisme
portant une permission réservée à la plateforme — se crée par `global →
organisme`. Les deux sens sont désormais contrôlés, le second parce qu'il rend
le rôle attribuable partout.

**Épreuve :** neutraliser la condition fait tomber les trois tests du sens
dangereux. Le test d'invariant a été corrigé pour échouer EN NOMMANT l'état :
son assertion de chemin le précédait et court-circuitait la requête d'état,
PHPUnit s'arrêtant à la première assertion en défaut. Il tombait pour la bonne
cause en la rapportant par le mauvais symptôme — le motif relevé aux PAS-13 et
PAS-14, cette fois dans l'ordre des assertions.

### Catalogue public — épreuves et coefficients d'une famille · `91e5920`
**Origine :** application du FRONT-2, contrainte n°3 en échec.
**Nature :** exposition manquante, pas défaut de données. Les coefficients 8, 12
et 20 existaient en base depuis le PAS-4.1 et `AttemptResource` les servait déjà
sur une tentative, mais `GET /api/v1/catalogue/familles/{slug}` n'exposait
aucune épreuve. La correction structurelle du PAS-4.1 ne pouvait donc pas
atteindre l'écran, et la ligne 6 de la recette FRONT-2 — celle qui vérifie que
la spécialité pèse 20 contre 8 pour les sciences de l'éducation — ne pouvait pas
passer.

**Acceptation :** `exams` sur la famille, liste blanche stricte de trois champs
— code, nom localisé, coefficient. Durée, langues et format relèvent de la fiche
d'épreuve et non de la famille ; les exposer ici ouvrirait un second contrat sur
les mêmes données. Aucune autre resource ne les exposait avant ce lot, vérifié.

**Trouvé en route :** `exams` ne porte pas d'`exam_family_id` — une épreuve
appartient à un PARCOURS, et le parcours à la famille. La relation est donc un
`hasManyThrough` par `Track`, non une colonne ajoutée : le chemin manquait, pas
la donnée. Et `Exam` n'emploie pas `IsCatalogueEntry` : il porte son propre
`scopePublished` mais aucun `scopeOrdered`, d'où un ordre explicite dans la
fermeture de chargement (DET-30).

### PAS-15 — Composition d'une session d'entraînement · `5043886`
**Périmètre :** `TrainingComposer`, `AttemptService::startTraining()`,
`POST me/training/{examCode}`, index unique partiel d'unicité de session.

**Acceptation :** le périmètre demandé n'est JAMAIS élargi — une série courte
plutôt qu'un mini-diagnostic déguisé, `meta` disant combien et pourquoi ; sous
cinq questions, 409 avec un code propre et le nombre disponible ;
anti-répétition en base par rang — jamais vue, puis manquée, puis réussie en
dernier recours signalé ; aucun chronomètre ; une seule session ouverte par
candidat, tous concours confondus ; sans `node_uuid`, le périmètre vient de
l'ordonnance, ce qui referme la boucle du produit.

**Réemploi :** passation, soumission, correction, maîtrise et quota de causes
sont ceux du diagnostic, inchangés. Un entraînement soumis alimente donc la
maîtrise — sans quoi le profil se figerait au premier diagnostic et le plan à
90 jours ne montrerait jamais de progrès.

> **Lignée correctrice ouverte au PAS-10.** PAS-10, PAS-11, PAS-12 et PAS-13
> forment une même lignée thématique — les invariants imposés par la base — et
> elle doit se clore au PAS-14. Elle dépasse le plafond de deux rounds du §8 :
> **chaque round a été arbitré explicitement par OptimGov**, comme le prévoit ce
> plafond. Le fait est inscrit ici pour n'avoir pas à être déduit du journal.
>
> Le plafond porte sur un pas ; les cinq critères de sortie du §8 portent sur
> cette lignée. Conformément au §2, PAS-14 n'est pas listé ci-dessus tant qu'il
> n'a pas de SHA.

### PAS-16 — Rendez-vous Mémoire, première moitié (F07) · `4b7ad75`
**Périmètre :** `review_schedules`, `MemoryScheduler`, `ReviewSchedule`,
`naja7i.timezone_candidat`, borne de session. **Lot volontairement coupé en
deux** : les routes, le sélecteur de question sœur et le plafond de liste
relèvent de la seconde moitié — un découpage assumé plutôt qu'un lot
interrompu. Ne pas auditer la moitié absente comme manquante (§2).

**Acceptation :** calendrier à casiers et non facteur d'aisance — paliers fixes
`1-3-7-16-35` plutôt qu'un SM-2, qui produirait des nombres d'allure
scientifique sur une banque jeune sans historique de calibration ; on planifie
une ERREUR et non une question, la ligne portant le couple (compétence, cause),
car resservir douze fois le même item apprendrait l'item et non le
raisonnement ; sortie du calendrier après deux réussites certaines
consécutives ; aucun rendez-vous dans la marge d'avant-épreuve ; les items sans
réponse sont exclus — F07 révise une cause diagnostiquée, une question laissée
vide n'en a pas.

**Écart connu, clos au PAS-18 :** le calendrier n'avançait que sur la
question tracée par `last_question_id`. DET-35 a été tranché dans le sens du
COUPLE (compétence, cause) — voir ci-dessous.

### PAS-18 — Rendez-vous Mémoire, seconde moitié (F07) · `23d4aa6`
**Périmètre :** `GET me/memory/{examCode}/due`, `POST me/memory/{examCode}/session`,
`MemoireController`, `ReviewComposer`, `ReviewScheduleResource`,
`AttemptService::startReview()`, genre de tentative `review` et index unique
partiel d'unicité de séance, `MemoryScheduler::PLAFOND_LISTE`, traductions
FR et AR. **F07 est complet** — le §4 ne le liste plus.

**Numérotation :** un pas NEUF, et non `PAS-16.1`. Le §2 réserve le suffixe au
*correctif* d'un pas poussé, et pose que la numérotation est **historique, pas
thématique**. Cette seconde moitié n'amende rien et arrive après le PAS-17 :
la nommer 16.1 aurait rompu les deux règles à la fois. Le lien avec F07 se lit
dans le titre et dans cette fiche, pas dans le numéro.

**Acceptation :** rien d'échu répond 200 avec une liste vide et la prochaine
date — « rien aujourd'hui, prochain le 14 » est une information, un 404 n'en
est pas une ; aucun plafond silencieux — 20 servis, le reste compté et annoncé
dans `meta` ; la séance sert une question SŒUR quand la banque en a une, et
ressert l'énoncé tracé sinon — repli annoncé dans `meta.reserved_identical`,
et qui ne fait JAMAIS sortir du calendrier (PAS-21, audit BLOC-3) ; une question dont plusieurs distracteurs portent des causes échues
en couvre plusieurs à la fois ; 201 à l'ouverture, 200 à la reprise, une seule
séance ouverte par candidat ; deux codes d'erreur distincts l'un de l'autre
comme du diagnostic et de l'entraînement — `MEMORY_NOTHING_DUE` (le candidat
est à jour) et `MEMORY_NO_SIBLING_QUESTION` (la banque ne tend pas encore ce
piège).

**Mur payant :** la CAUSE est fermée dans la liste de révision hors abonnement,
avec `cause_locked`, sur le précédent de `CorrectionResource`. L'exposer aurait
offert par une autre porte le diagnostic que la correction fait payer. Le quota
F03 n'est pas décompté sur une lecture de liste — DET-38.

### PAS-17 — L'évitement cesse de payer · `5a97c19`
**Périmètre :** `mastery_scores.skipped_count`, `MasteryCalculator` (calcul
piloté par les items servis et non plus par les seules réponses, agrégation
comprise), `RemediationPlanner` (facteur partiel et motif `questions_sautees`),
`MasteryScore::toPublicArray`.

**Le défaut corrigé, mesuré et non soupçonné :** sur la même série de dix avec
cinq bonnes réponses chacun, le candidat qui répondait faux aux cinq autres
tombait à 50 et prenait la tête de l'ordonnance, celui qui les sautait affichait
100 et en sortait. Le domaine faible disparaissait parce qu'il avait été
esquivé — l'exact contraire de la promesse du produit.

**Acceptation :** une question servie sur une tentative CLOSE et laissée sans
réponse est comptée ; une tentative en cours n'en produit aucune ; le score
n'est pas touché — les sautées au dénominateur donneraient au sauteur
l'urgence exacte de qui a répondu faux, soit l'affirmation qu'éviter et rater
sont le même fait ; le sauteur remonte au-dessus d'un domaine maîtrisé mais
reste SOUS l'erreur démontrée ; le motif `questions_sautees` est distinct de
`erreurs_avec_certitude` et de `jamais_evalue`, et reste un texte lisible ; un
domaine entièrement répondu ne bouge d'aucun millième.

**Calibration :** facteur 0,5, identique à `FACTEUR_JAMAIS_EVALUE` et pour la
même raison — sur la part sautée, l'écart est inconnu, pas maximal. Borne haute
mesurée par balayage : à 1,0 le sauteur égale celui qui a répondu faux. Un test
relit le facteur dans le résultat et tient ce plafond sans exposer la constante.
Clôt DET-34, inscrit au même pas.

### PAS-19 — Une cause déjà payée ne se reverrouille pas · `31b87e2`
**Périmètre :** `CauseRevealService::revealedCouples()`, `MemoireController::due()`,
`ReviewScheduleResource`.

**Ce qui était faux :** DET-38 avait été inscrit au PAS-18 comme un prix
assumé. C'était une erreur de qualification. `ParcoursController::correction()`
promet que le quota est décompté une seule fois et que revenir sur sa
correction ne recoûte rien ; `CauseRevealCounter` n'est jamais remis à zéro
pour cette raison même. Une cause payée qui réapparaît fermée dans la liste de
révision n'est pas un mur payant, c'est une promesse rompue. L'objection du
coût — une lecture par ligne — ne tenait pas : une requête suffit.

**Acceptation :** une cause révélée en correction reste ouverte dans la liste,
sans qu'aucune unité ne se consomme à la lecture ; une cause jamais révélée
reste fermée hors abonnement ; le compteur ne bouge pas.

**Portée bornée, et c'est une décision :** rendre gratuite une cause déjà payée
sur une AUTRE question du même couple, dans la correction, ferait passer
l'unité de quota du « par réponse » au « par couple ». C'est l'économie de F03,
donc une décision de produit — non prise ici.

### PAS-20 — Le temps de la suite, mesuré puis réduit · `d3320b0`
**Périmètre :** `Tests\TestCase::$seed`, `phpunit.xml` (facteur de travail
argon2id), retrait des semis de onze `setUp()`, trois assertions de
`CataloguePublicTest` remises sur le référentiel réel.

**Ce que la mesure a dit, avant toute action :** répartition PLATE — six
classes sur vingt-six portent la moitié du temps, donc aucun point chaud — mais
**67 % de la suite était du montage** et non des assertions. Deux causes, aucune
dépendance : le semis du catalogue rejoué à chaque test (0,22 s × ~240 tests) et
`argon2id` retenu au coût de PRODUCTION en test (99,9 ms par hachage, deux à
trois comptes par test).

**Résultat :** 249 s → ~117 s. Trois exécutions vertes, dont une en ordre
aléatoire — le catalogue vivant désormais hors transaction, l'ordre habituel
seul n'aurait rien prouvé sur l'isolation.

**Paratest écarté, et c'est un résultat de mesure.** DET-28 le proposait ;
paralléliser un montage devenu court en échange d'une dépendance et d'une
classe de défauts serait un mauvais échange. **L'interblocage sur `DROP TABLE`
du §6 reste non reproduit ET non vérifié** — le vérifier exige paratest.

**Effet de bord tracé (DET-39) :** le catalogue de test vit hors transaction. Un
test qui l'écrirait fuirait sur les suivants. Aucun ne le fait ; la contrainte
est écrite parce qu'elle ne se déduit d'aucune ligne de code.

### PAS-21 — Correctifs de l'audit externe 490fc53 · `aac1d7a`
**Origine :** audit externe, cinq bloquants. Les cinq sont exacts ; quatre
relèvent de la concurrence, et la suite était verte pendant tout ce temps.

**Acceptation :** un rejeu de `POST submit` ne touche aucune colonne d'un
rendez-vous — les effets de bord sont dans `AttemptService::submit()`, dans la
transaction et derrière la garde de transition, donc valables pour TOUTE voie
de soumission ; le planificateur verrouille le rendez-vous DÈS LA LECTURE et
traite une violation d'index comme une relecture, sous point de reprise ;
l'énoncé resservi faute de sœur est annoncé et GÈLE le compteur de sorties ;
deux ouvertures simultanées rendent 201 puis 200 sur la même session, jamais
500, sur les trois chemins ; une clé d'idempotence rejouée sur une autre
opération reçoit 409 et jamais une autre tentative.

**Ordre de verrouillage, écrit une fois pour toutes :** tentative, puis items,
puis rendez-vous. Le même dans `answer()`, `submit()` et `MemoryScheduler`.

**Épreuve :** quatre tests à DEUX sessions PostgreSQL, l'entrelacement imposé
par `DB::listen`. Chaque test vérifié par mutation — **deux ne discriminaient
pas** et ont été refaits : l'un se fiait à des horodatages à la seconde
(DET-40), l'autre constatait une attente sans vérifier quelle instruction
l'avait provoquée. Un test de mise à jour perdue exigerait deux processus
réels ; ce montage n'en a pas, et le commentaire le dit.

**Ferme DET-36.** Ouvre DET-40.

### PAS-22 — Le plafond de l'énoncé resservi, et le plan de rédaction · `04b78e6`
**Périmètre :** `MemoryScheduler::PLAFOND_ENONCE_RESSERVI`, `CouvertureBanque`,
`GET admin/banque/couverture/{examCode}` sous `permission:questions.view`,
`meta.without_sibling` sur la liste échue du candidat.

**L'arbitrage :** un énoncé resservi à l'identique fait monter le palier
jusqu'au MILIEU de l'échelle — 7 jours — et pas au-delà. Geler complètement
ferait revenir chaque jour, indéfiniment, tout couple à question unique, et ces
revenants satureraient la liste plafonnée à vingt en évinçant les rendez-vous
résolubles. Laisser filer jusqu'à 35 jours ferait disparaître le rendez-vous
par la petite porte après lui avoir fermé la grande. La sortie du calendrier
reste fermée ; un échec remet toujours à zéro. Le plafond borne la MONTÉE et ne
rabaisse jamais un palier déjà mérité — clause testée pour elle-même.

**Acceptation :** le plan de rédaction liste les couples (compétence, cause)
attendus par au moins un candidat que la banque ne couvre pas, ordonnés par
nombre de candidats en attente ; la couverture est donnée PAR LANGUE, une
question étant monolingue ; le candidat n'en reçoit qu'un NOMBRE, jamais les
couples — les nommer nommerait des causes, et la cause est un champ payant.

**Ce qui n'est pas fait :** aucune interface. Le back-office n'est pas ouvert
(lot A4) et un rédacteur ne lit pas du JSON — DET-41.

### PAS-23 — Index des tentatives du candidat · `0cec306`
**Périmètre :** `GET me/attempts` dans le groupe `auth:sanctum` + `verified.api`,
`ParcoursController::index()` et `PLAFOND_INDEX`.

**Le trou comblé :** `show()` exige l'uuid, que personne ne connaît sur un
appareil neuf. La reprise multi-appareil ne tenait que par un effet de bord —
rouvrir un diagnostic rend celui en cours — qui suppose de connaître l'épreuve,
gardée par le frontend dans une trace locale faute de contrat (sa dette D-F15).

**Acceptation :** filtres optionnels `status`, `kind`, `exam_code`, un filtre
inconnu refusé en 422 plutôt qu'ignoré ; la plus récente d'abord, départagée par
`id` — les horodatages sont à la seconde (DET-40) ; borne à 20 avec le reste
compté dans `meta`, jamais tronqué en silence ; les tentatives d'un autre
candidat n'apparaissent jamais ; **aucun énoncé ni aucune option dans la charge
utile**, éprouvé sur les octets rendus et vérifié par mutation.

**Ce que ça rend inutile, et sa limite (DET-42) :** le frontend déduit l'épreuve
suivie de la tentative la plus récente, sans profil ni trace locale — aucun état
nouveau. Mais un candidat qui prépare A et a travaillé B en dernier verra B : le
produit sait quelle épreuve il a TOUCHÉE, pas laquelle il PRÉPARE. Le profil
candidat remplacera cette déduction.

### PAS-24 — Correctifs de l'index des tentatives · `e62106c`
**Périmètre :** `AttemptResource` (`correct_count`, `last_activity_at`),
migration `attempts.last_activity_at` et son index, `AttemptService`,
`Attempt::booted()`, `ParcoursController::index()`, middleware `NoStore`.

**Acceptation :** `correct_count` est nul tant que `submitted_at` l'est, sur
l'index comme sur la route unitaire ; la tentative travaillée en dernier sort
en tête, quelle que soit sa date d'ouverture ; un `exam_code` inconnu et un
`exam_code` réel hors portée rendent la MÊME réponse — 200, liste vide ;
`status` et `kind` restent refusés en 422 hors énumération ; toute réponse
portant `seconds_remaining` est `no-store`.

**Ce que la mesure a corrigé du brief :** l'oracle de `correct_count` n'existait
pas — la valeur n'est écrite qu'à la soumission, et retirer la garde de la
ressource ne changeait rien (vérifié par mutation). Le test porte donc sur la
COLONNE, qui est la vraie garantie ; la garde de la ressource est la seconde
ligne. Recensement fait des autres agrégats : correction fermée en 409,
maîtrise et rendez-vous alimentés depuis `submit()` seul, derrière la garde de
transition du PAS-21. Aucun autre ne fuit.

**Déjà livré, rien à faire :** le bloc `exams` du catalogue (code, nom
localisé, coefficient) existe depuis `91e5920`.

**Non ouvert, volontairement :** la pagination par curseur. Le plafond reste et
`meta` l'annonce.

### PAS-26 — F05, la question miroir · `c15e032`
**Périmètre :** `QuestionsSoeurs` (extrait de `ReviewComposer`),
`AttemptService::startMirror()`, `POST me/mirrors/{itemUuid}`,
`CorrectionResource.mirror_available`, index d'unicité `mirror`,
`CauseRevealService::reveal()`.

**Acceptation :** un item juste, sans réponse, ou d'une tentative non soumise
n'ouvre aucun miroir (409 `MIRROR_NOT_APPLICABLE`) ; l'item d'un autre candidat
est **introuvable** ; le miroir n'est **jamais** la question déjà répondue, et
son absence répond 409 `MIRROR_NOT_AVAILABLE` — code distinct, la banque étant
en cause et non le candidat ; la correction n'annonce que l'EXISTENCE, aucun
énoncé ni uuid de miroir n'y voyage, éprouvé sur les octets ; 201 à
l'ouverture, 200 à la reprise, une seule ouverte à la fois ; réussir le miroir
fait avancer le rendez-vous mémoire du couple.

**Un seul sélecteur pour deux surfaces.** `QuestionsSoeurs` porte le vivier
indexé par (compétence, cause) ; la POLITIQUE DE REPLI reste à chaque appelant
— la révision ressert l'énoncé connu faute de mieux, le miroir refuse. Trois
sélecteurs du même concept divergent : c'est la leçon de DET-30.

**Changement d'économie, à connaître (F03).** L'unité de quota portait sur la
RÉPONSE ; elle porte désormais sur le COUPLE (compétence, cause). F05 l'a
imposé : le miroir porte par construction la cause qu'on vient de payer, et la
faire repayer vendrait deux fois le même diagnostic. Plus proche de la lettre
de la fiche — « un compte gratuit voit deux causes » — que le décompte par
réponse ne l'était. Trois tests du quota ont dû changer de montage : ils
répondaient deux fois au même distracteur et empruntaient donc le chemin
devenu gratuit, sans plus rien prouver de l'atomicité qu'ils défendent.

**Non tranché, tracé (DET-45) :** `questions.mirror_question_id` existe depuis
le PAS-5 et n'est pas utilisée ici.

### PAS-27 — La chaîne éditoriale par l'API · `079b283`
**Périmètre :** `POST/PATCH/GET admin/questions`, `GET admin/questions/{uuid}`,
`GET admin/questions/a-relire`, `QuestionAuthoringService`,
`QuestionAdminResource`. `publish` et `retire` sont inchangés. **L'écran est le
lot A4**, pas ce pas.

**Acceptation :** une question naît BROUILLON quoi qu'on envoie ; la cause n'est
jamais posée sur la bonne réponse ; un distracteur sans cause bloque la
publication pour diagnostic ; le valideur n'est jamais l'auteur ; une question
publiée ne s'amende plus et ne change plus de source, garanti par trigger ; la
liste est bornée à 50 et l'annonce ; chaque geste porte sa permission, et la
file de relecture sert le plus ancien d'abord.

**Aucune règle métier n'est née ici** — c'était la contrainte du lot. Le
service d'assemblage n'a pas d'avis ; `QuestionIntegrityChecker` répond, et ses
motifs sont rendus dans `meta.publication_blockers` à chaque lecture.

**Portée de la règle 404/403, précisée au PAS-28 :** une permission de
personnel refusée répond 403 explicite. `METHODE.md` §7.2 dit désormais ce que
la règle vise — l'énumération de ce qui appartient à autrui — au lieu de
laisser sa portée se deviner.

**Bloquant levé au PAS-28 (DET-46) :** la vérification d'une source est
`POST admin/sources/{uuid}/verify`.

### PAS-28 — Correctifs de l'audit tournée 2, et deux décisions · `b884280`
**Origine :** audit externe, trois bloquants dont un en production.

**Acceptation :** `meta.cause` d'un miroir n'est servie que si le couple est
acquis ou l'accès illimité — sinon `cause: null` et `cause_locked: true`, sans
rien consommer ; deux révélations concurrentes du MÊME couple coûtent une
unité, deux couples distincts en coûtent deux ; une collision sur l'index de
clé revalide l'empreinte et lève `IdempotencyKeyReused` au lieu de rendre la
tentative d'une autre opération ; un miroir ouvert sur un autre item ne se
reprend pas.

**Le déplacement structurel :** `cause_acquisitions` matérialise l'achat d'un
couple (compétence, cause). Un acquis déduit par requête ne peut pas être
atomique ; un acquis qui existe en base l'est par construction — même
raisonnement qu'au PAS-10, verrouiller la ressource rare et non l'objet qui la
consomme.

**Décision 1 — DET-46 tranché :** vérifier est un acte sur LA SOURCE, pas sur
la question. `POST admin/sources/{uuid}/verify`, sous `questions.review` par
COMMODITÉ et non par principe. La vérification enregistre qui et quand, une
contrainte de base refusant l'un sans l'autre. Les citations d'une question
publiée ne sont pas dégelées.

**Décision 2 — portée de la règle 404/403 écrite** dans `METHODE.md` §7.2 et
`CLAUDE.md` : 404 pour ce qui appartient à autrui, où un 403 permettrait
l'énumération ; 403 explicite pour une permission de personnel refusée. Le
middleware n'a pas bougé — c'est l'énoncé qui manquait de portée.

**Constaté au PAS-28, endigué au PAS-29 (DET-47) :** une source vérifiée
pouvait être modifiée après coup sans que le contrôle soit invalidé.

### PAS-29 — Une source modifiée cesse d'être vérifiée · `0de348d`
**Périmètre :** deux déclencheurs sur `sources`. Aucune surface HTTP nouvelle.

**Acceptation :** modifier une colonne porteuse de sens — `code`, `kind`,
`title_*`, `authority_*`, `session_label`, `url` — annule `verified_at` et
`verified_by` ; les citations des questions NON GELÉES retombent à
`unverified` ; la publication pour diagnostic se rebloque jusqu'à
re-vérification, et re-vérifier débloque ; `location_note_*` et `languages` ne
désarment PAS le contrôle — ils aident à trouver le document sans le
constituer.

**Pourquoi deux déclencheurs :** annuler `verified_at` seul aurait déplacé le
défaut d'une table. Ce que lisent `hasVerifiedContentSource()` et le trigger de
publication est le PIVOT. Vérifié par mutation, chaque moitié séparément.

**Mesure d'attente, pas solution.** L'invalidation efface une trace au lieu de
la conserver et impose un recontrôle complet pour une correction
typographique. **DET-47 reste ouverte** pour le versionnement de la source —
une source vérifiée devient immuable, la corriger en crée une version, comme
pour une question publiée.

### PAS-30 — DET-45 tranché : le miroir désigné · `88fbcf5`
**Périmètre :** `QuestionsSoeurs::designee()`, priorité dans
`AttemptService::startMirror()`. Aucune surface HTTP nouvelle.

**Acceptation :** la question désignée par `mirror_question_id` l'emporte sur
le choix par couple ; une désignation NON SERVABLE — brouillon, retirée, autre
langue — se replie sur le couple au lieu de refuser ; le repli reste le
comportement par défaut, rien n'étant désigné dans la banque actuelle.

**Contrainte à connaître pour le lot A4 (DET-48) :** `mirror_question_id` est
gelé après publication. Le miroir se désigne à la rédaction, ou par une
nouvelle version.

### A4a — Le panneau, la rédaction, la relecture · `3ab2355`
**Périmètre :** Filament 4 et le panneau `admin` (`AdminPanelProvider`),
`QuestionResource` et ses trois pages, `QuestionPolicy`, `SourcePolicy`,
`SourceObserver`, `User::canAccessPanel()`.

**Acceptation :** un rédacteur crée, amende et fait circuler une question sans
écrire de PHP ; l'écriture passe par `QuestionAuthoringService` et les cinq
transitions par `QuestionTransitionService` — vérifié par un test qui
n'observe QUE des faits produits par le service (`sibling_group` neuf, source
citée `unverified`, cause retirée de la bonne réponse), et par la mutation qui
laisse Filament écrire le modèle et le fait rougir ; un candidat n'entre pas
dans le panneau ; l'auteur ne VOIT pas le bouton de validation de sa propre
question ; les motifs de `QuestionIntegrityChecker` s'affichent en permanence
sans rien empêcher ; le formulaire d'une question publiée est fermé et DIT
pourquoi ; aucune action de suppression nulle part.

**Deux ajouts au panneau, dont un indispensable :** ses routes sont des routes
WEB et ne traversent pas le groupe `api`. Sans `ResolveTenant`, la première
lecture de `memberships` lève « aucun tenant résolu » et le panneau ne s'ouvre
pas. `SetLocale` suit, le back-office étant bilingue.

**Ce que les policies font, et ne font pas :** elles décident de ce qu'on
MONTRE, jamais de ce qui se produit. Retirer une policy ne casse aucune
garantie ; retirer un service les casse toutes. Le 403 est correct ici — la
règle 404 vaut pour les ressources d'un autre CANDIDAT (§1).

**Reste du lot A4 :** la surface des sources et celle de la couverture.

### A4b — Le registre des sources, et la couverture en accueil · `2db4f6c`
**Périmètre :** `SourceResource` et ses trois pages, `Couverture` (page
d'accueil du panneau), `App\Filament\Libelles`, `Source::COLONNES_DE_SENS` et
`Source::questions()`, `User` implémente `HasName`, retrait du tableau de bord
de Filament.

**Acceptation :** `/admin` sert la couverture, ordonnée par candidats en attente
— vérifié à travers l'interface, et la mutation qui réordonne la collection
après le service la fait rougir ; un couple que personne n'attend n'y figure
pas ; les deux langues sont comptées séparément ; un candidat n'y accède pas.
Côté sources : la vérification passe par `SourceVerificationService`, enregistre
qui et quand, et propage aux citations modifiables ; le bouton disparaît pour
qui n'a pas `questions.review` et pour une source déjà vérifiée ; modifier un
champ d'identification annule la vérification ET SE VOIT sans rechargement ;
modifier un repère de lecture ne coûte rien.

**Les deux sens de chaque garantie**, comme la note de méthode de l'audit
tournée 2 le demande : « l'invalidation se voit » est testée avec « ce qui ne
doit rien coûter ne coûte rien », faute de quoi un écran qui crierait à chaque
enregistrement passerait pour juste.

**`Source::COLONNES_DE_SENS` décrit, elle ne décide pas.** La règle est dans le
déclencheur du PAS-29 ; cette liste permet au formulaire d'annoncer le coût
AVANT l'enregistrement. Un test la confronte à la base colonne par colonne et
dans les deux sens, pour qu'elle ne devienne pas une seconde source de vérité.

**Un défaut que seule une requête HTTP complète a montré :** Filament exige un
nom d'utilisateur, nos comptes s'identifient par leur e-mail (PAS-2), et toute
page du panneau échouait au rendu. Les tests de composants Livewire ne rendent
pas la mise en page — deux tests font désormais une vraie requête.

**Lot A4 clos.** Hors périmètre et inchangés : import JSON/CSV en volume,
gestion des rôles et des comptes du personnel.

### DET-48 — Le dégel du miroir désigné · `49f8b4b`
**Périmètre :** migration `000460` (redéfinition d'`assert_published_question_frozen`),
`QuestionAuthoringService::designerMiroir()`, `QuestionPolicy::designateMirror()`,
action `designer_miroir` de `QuestionsTable`, champ du formulaire de rédaction.

**Acceptation :** la désignation se modifie sur une question publiée, à travers
l'interface ; le reste du contenu — énoncé, justification, difficulté,
remédiation — demeure gelé ; une question retirée reste un mur ; une désignation
posée après publication est effectivement SERVIE par `QuestionsSoeurs` ; un
auteur sans `questions.publish` ne voit pas l'action, et elle ne s'offre pas sur
un brouillon, où le champ est déjà dans le formulaire.

**Les deux mutations :** élargir l'exemption du déclencheur à `stem` fait rougir
« le reste du contenu demeure gelé » ; l'annuler fait rougir « la désignation se
modifie sur une question publiée ». Chacune ne touche que son test.

**Un test du PAS-10 a été retiré de sa liste, délibérément.**
`mirror_question_id` figurait parmi les colonnes gelées et le gel la couvrait :
ce n'est pas un test qui était faux, c'est la règle qui a changé. Le
remplacement est écrit à l'emplacement de l'ancienne entrée pour que la
disparition ne se lise pas comme un oubli.

**Ce que le brief demandait et qui n'a pas pu être livré tel quel :** « le champ
cesse d'être en lecture seule sur une question publiée » — il l'est, mais le
formulaire ne s'ouvre pas pour autant, `QuestionPolicy::update()` le fermant
depuis A4a parce que toutes les autres colonnes y sont gelées. L'intention est
tenue par une action dédiée ; le champ, lui, dit où aller.

### Profil candidat — DET-42 close · `64df5af`
**Périmètre :** `GET`/`PUT me/profile` dans le groupe `auth:sanctum` +
`verified.api`, table `candidate_profiles` (isolée par tenant),
`CandidateProfile`, `CandidateProfileResource`, `ProfileController`,
`User::candidateProfile()`.

**Trois champs et une règle**, périmètre volontairement minimal : `exam_code`
(épreuve publiée, exigée), `objective` et `target_date` (optionnels). Ni
préférences d'interface, ni fuseau — DET-33 a déjà sa clé de configuration —, ni
avatar. Chacun de ces champs attend un demandeur.

**Acceptation :** un profil absent rend les mêmes clés à `null` et non une 404 ;
la forme de la réponse est identique avec et sans profil ; `PUT` rejoué donne le
même état et une seule ligne ; une épreuve non publiée ou inconnue est refusée
en 422 avec le message ambigu du PAS-4 ; le profil d'un autre candidat est
introuvable, et une charge utile portant `user_id` n'écrit pas chez autrui ; le
profil est porté par le tenant ; la ressource est une liste blanche stricte
éprouvée sur les clés rendues.

**CONTRAT POUR LE FRONTEND — il peut supprimer sa déduction (sa dette D-F15).**
`GET me/profile` est désormais la SEULE réponse à « quelle épreuve je
prépare ». `GET me/attempts` continue de dire quelle épreuve a été TOUCHÉE en
dernier, et c'est tout ce qu'elle dit. Quand `exam_code` est nul — compte neuf,
ou candidat qui n'a jamais déclaré —, le frontend peut PROPOSER cette dernière
épreuve travaillée comme suggestion à confirmer d'un clic ; ce clic fait un
`PUT me/profile`. Ce qu'il ne doit plus faire : l'appliquer sans confirmation,
ni la conserver dans une trace locale. Une déduction silencieuse serait une
seconde vérité, et c'est le défaut que ce pas ferme.

**`PUT` remplace, il ne fusionne pas.** Les trois champs sont écrits à chaque
appel ; un champ de plan absent est remis à `null`. C'est la sémantique du
verbe, et elle évite la question sans réponse d'un `PATCH` — « comment j'efface
mon objectif ? ». Le `GET` rendant les trois champs, un client qui n'en change
qu'un les renvoie tous.

**Les trois mutations :** retirer `Exam::published()` fait rougir le refus d'une
épreuve non publiée ; lire sans borner au candidat authentifié fait rougir « le
profil d'un autre candidat est introuvable » ; rendre le modèle entier fait
rougir la liste blanche.

### PAS-33 — Les transitions manquantes de la chaîne, par l'API · `1a198f3`
**Périmètre :** `POST admin/questions/{uuid}/{submit,review,validate}`,
`QuestionAdminController::{submit,review,validatePedagogy,transiter}`. Aucune
modification de service.

**L'inventaire qui a ouvert le pas.** `QuestionTransitionService` sert cinq
actes : `submitForReview`, `markReviewed`, `validate`, `publish`, `retire`. Les
deux derniers avaient leur route depuis le PAS-11 ; les trois premiers
n'existaient qu'en Filament depuis A4a. Ce sont ceux-là, et rien d'autre, que ce
pas expose.

**Acceptation :** la chaîne se parcourt de bout en bout par l'API, chaque étape
par le métier qui la porte, `reviewer_id` et `validator_id` enregistrés ; chaque
route sous SA permission, 403 sans elle (règle du PAS-28) ; l'auteur ne valide
pas sa propre question par la route non plus, et un second éditeur le peut ;
une transition invalide depuis l'état courant est refusée en 422
`QUESTION_TRANSITION_REFUSED` sans laisser la question à mi-chemin ; un uuid
inconnu reste 404.

**Un seul code d'erreur pour deux refus**, délibérément : le service lève un
`RuntimeException` dans les deux cas, et les distinguer supposerait de lire le
texte du message — un couplage qui casserait à la première reformulation, pour
une information que le message porte déjà.

**Ce que la recette exige, et qu'on découvre en la jouant :** publier POUR LE
DIAGNOSTIC demande une source vérifiée, et citer une source ne la vérifie pas
(DET-46). Le semis devra donc appeler `POST admin/sources/{uuid}/verify` — route
du PAS-28 — avant de publier. Le test de bout en bout le fait, et c'est la
recette de référence.

**Deux manques signalés et NON comblés (DET-50) :** le refus motivé n'existe pas
— les arêtes de retour sont déclarées dans la table des transitions mais aucune
méthode publique ne les emprunte, donc renvoyer une question à son rédacteur est
impossible par quelque chemin que ce soit, Filament compris — et
`retire(?string $reason)` accepte un motif qu'il n'écrit nulle part.

### PAS-34 — Les limiteurs de débit prennent un nom · `c6fe6ca`
**Périmètre :** `config/naja7i.php` (`rate_limits`), `AppServiceProvider`,
`routes/api.php` (treize déclarations), `tests/Feature/RateLimitProfileTest.php`.
Aucun contrôleur, aucun service, aucune migration.

**Ce qui a ouvert le pas.** La première exécution de la recette frontend en
intégration continue — un `429` à la création du deuxième compte candidat, à la
cinquième requête publique du passage, aucun plafond atteint.

**La cause, et elle datait du PAS-2.** `throttle:6,1` est un limiteur ANONYME :
`ThrottleRequests` retombe sur `resolveRequestSignature`, `sha1(domaine|ip)`
sans session et `sha1(user_id)` avec. La route n'entre pas dans la clé — toutes
les routes partageaient un seau, chacune le comparant à son propre plafond.

**Un défaut de produit était caché dedans.** `reponse` (120/min) et
`ouverture-serie` (10/min) partageaient le seau du candidat authentifié : onze
réponses fermaient l'ouverture d'une nouvelle série. Personne ne l'avait vu
parce que rien ne le mesurait.

**Acceptation :** deux routes publiques ne partagent plus un compteur ; le seuil
reste par identité et non global ; le profil par défaut est `production` et
refuse au septième essai comme avant ; un profil mal orthographié retombe sur
`production` ; `reponse` porte la même valeur dans les deux profils ; les
limiteurs de sécurité — `LoginThrottle`, renvoi de vérification — restent réels
SOUS le profil de recette.

**Les mutations :** retirer `->by()` fait rougir « le seuil est par identité » ;
revenir aux `throttle:N,1` anonymes fait rougir « deux routes publiques ne
partagent plus un compteur ». Un préfixe de clé écrit à la main s'est révélé
INERTE sous mutation — le nom du limiteur entre déjà dans la clé par
`md5($nom.$limit->key)` — et il est parti.

### PAS-35 — L'examen blanc · `b2fc0e0`
**Périmètre :** `SimulationController`, `SimulationReport`,
`SimulationReportResource`, `AttemptExpired`, `AttemptService::startSimulation`
et `::closeIfExpired`, index partiel `attempts_single_open_simulation`, deux
routes, traductions FR/AR. `DiagnosticComposer` réemployé SANS MODIFICATION.

**L'inventaire qui a ouvert le pas, et qui a contredit la consigne.** Le lot
demandait une note « sur le barème réel (coefficients du blueprint) ».
`BlueprintModel` ne porte ni sections, ni durée, ni barème : c'est un
enregistrement de provenance, et ses trois champs `official_*` sont nuls par
construction — le modèle ET la migration le disent, cette dernière ajoutant que
les inventer « serait la faute la plus coûteuse de ce projet ». Vérifié en base :
`official_question_count` NUL sur les trois épreuves, `official_scoring_note_fr`
= « Barème détaillé non précisé par le descriptif officiel ».

| Ce que le lot supposait | Où ça vit réellement | Verdict |
|---|---|---|
| sections et poids « du blueprint » | `competency_nodes.weight_percent` (ADR-0014) | existe → réemployé |
| durée « du blueprint » | `exams.duration_minutes` | existe → réemployé |
| barème / coefficients par question | nulle part, et explicitement non publié | **n'existe pas → non inventé** |
| nombre de questions officiel | `official_question_count`, NUL partout | n'existe pas → convention du produit, déclarée |

**Acceptation :** la série suit `weight_percent` et non la maîtrise — deux
candidats aux maîtrises opposées reçoivent la même répartition ; sans durée
officielle l'ouverture est refusée (`SIMULATION_DURATION_UNKNOWN`) ; une réponse
après l'échéance est refusée par `ATTEMPT_EXPIRED`, code distinct
d'`ATTEMPT_CLOSED` ; la tentative expirée est close par le SERVEUR, via
`submit()`, et alimente maîtrise et calendrier comme toute autre série ; les
cinq patrons de concurrence tiennent (clé rejouée, clé réutilisée refusée,
une seule ouverte, index en base, 201/200, interception nommée) ; R06 intact —
aucun score avant soumission ; le rapport n'expose ni `is_correct`, ni
`rationale`, ni `cause`.

**Le rapport rend un POURCENTAGE PONDÉRÉ, jamais une note sur 20.** Le barème
n'est pas public : le contrat sert `official.scoring_note` telle quelle et
annonce `not_official_scale`. Chaque section porte `asked`, le score porte
`weight_covered` — aucun score sans son volume d'évidence. Le seuil officiel est
CITÉ quand le descriptif le donne, jamais comparé au candidat : citer informe,
comparer prédirait.

**Les cinq mutations :** composer depuis l'ordonnance fait rougir la
répartition ; inverser l'ordre des `catch` fait disparaître `ATTEMPT_EXPIRED` ;
rendre `closeIfExpired` inerte fait rougir la clôture serveur ; retirer
l'exigence de durée fait rougir le refus ; retirer l'index de la migration fait
rougir l'unicité. Trois défauts de mes propres tests ont été trouvés en
chemin — un nœud PARENT choisi comme domaine lourd alors qu'il ne porte aucun
item, et deux scans de mots interdits qui mordaient sur une citation officielle
et sur un démenti.

### Lot PORTES phase 2 — côté serveur · `521cda9`
**Règle installée :** un écran qui mesure offre toujours la porte qui le
remplit ; aucun état vide ne se termine sans un chemin cliquable ; tout élément
qui a l'apparence d'un lien EST un lien. Voir `docs/regles/PORTES.md`.

**Acceptation :**

- **D-09** — une réponse sans option choisie n'entre plus dans le volume
  d'évidence et compte comme sautée. Traverser une question et ne jamais la
  toucher rendent exactement les mêmes nombres — `answered_count`,
  `skipped_count`, `evidence`, `score` et le motif d'ordonnance.
- **D-03** — `Couverture` ouvre sur l'épreuve qui a du travail (trous, puis
  candidats en attente, puis banque publiée), et non sur la première de
  l'alphabet. Son état vide nomme l'épreuve examinée, distingue « Aucun trou »
  de « Rien à mesurer », et offre la porte de la rédaction à qui porte
  `questions.create`.
- **D-05** — les 143 clés de Filament qui ne se résolvaient dans aucune des
  deux langues sont traduites. Deux garanties : le contrôle de rendu visite
  TOUTES les pages du panneau, et un test indépendant du rendu vérifie que
  chaque clé de chaque paquet se résout en `fr` et en `ar`.
- **D-13** — la page 403 nomme la surface, la permission qui l'ouvre et les
  permissions du compte ; elle ne nomme aucun autre compte. La permission est
  déclarée sur la surface et tenue contre sa politique par `RefusNommeTest`.

**Mutations, sur la suite complète de 634 tests :** sept posées, chacune
rougissant le ou les tests attendus et eux seuls. Le détail est dans le message
de `521cda9`.

---

### FRONT-1 — Socle d'interface · `43a140f`, `d72584c`
**Acceptation :** relais BFF, aucun appel direct du navigateur vers l'API ;
six écrans bilingues avec RTL ; recette manuelle en 11 points documentée.

---

### LOT-0A — Gouvernance préalable à l'évolution commerciale · livré · `5e65d0d`

OptimGov a autorisé le 21 août 2026 l'intégration documentaire des ADR-0025 à
ADR-0029 et des brouillons F01, F02, F04, F05, F06, F07 et F09. Le lot est
livré par `5e65d0d` et inscrit dans `docs/PAS.md`.

Décisions acquises pour la suite : offre et version d'offre distinctes,
capacités séparées des quotas, commande figée sur une version, demande d'accès
non financière, composition concurrente des droits, quota de questions consommé
au premier service idempotent. F05 miroir et F07 mémoire ne débitent pas ce
quota général ; leurs protections anti-aspiration sont tenues côté serveur et
leurs bornes chiffrées restent à spécifier avant implémentation.

Le lot documentaire 0A préalable à 3A ferme les contradictions d'autorité :
nom, description et rattachement au public versionnent ; une correction
éditoriale suit un flux distinct et journalisé ; aucune table d'inclusion entre
capacités n'existe, l'autorisation étant l'union des capacités atomiques des
droits actifs. DET-83 et DET-84 inscrivent les deux réserves d'invitation.

Le complément 0A.3 (`16c0ede`) ferme P-5 et P-6 : ADR-0030 fixe les neuf
capacités atomiques, dont huit commercialisables, et ADR-0031 fixe les portées
typées et leur règle de contenance. Les exemples historiques et l'hypothèse
d'une capacité agrégée de coaching sont explicitement supersédés.

Restent à implémenter dans le lot commercial : administration commerciale
complète, demandes d'accès gratuit, droits transitoires, politique de
redemande, bornes F05/F07 et traitement de la divergence juridique DET-82.

#### Lot 3A.1 · correction V-2 · livré · `117339b`

Un droit sans terme ne court-circuite plus le chaînage des droits datés de même
capacité. Il reste effectif, tandis que le premier achat daté démarre maintenant
et que les suivants réservent leur durée à la suite. DET-81 est close avant la
création de toute offre gratuite sans terme. Le scénario S-08 est couvert par
un test discriminant.

#### Lot 3A.2 · portées typées · livré · `1a425c7`

Les droits portent désormais le couple cohérent `(scope_type, scope_uuid)` et
la portée globale reste `(null, null)`. La résolution applique en une requête
sur les droits la contenance filière, famille, épreuve et nœud de compétence ;
matière et chapitre restent des `competency_node`. Les types `specialty`,
`track`, `matiere` et `chapitre` sont refusés en base.

Les droits existants, tous produits sans portée par le chemin livré, sont
normalisés à la racine. DET-87 et DET-88 tracent les deux suites qui exigent un
objet catalogue ou une garde sur `CompetencyNode` hors de ce pas.

#### Lot 3A.3 · registre exécutable des capacités · livré · `af66ff0`

Le code connaît exactement neuf capacités atomiques et n'en autorise que huit
dans une offre. `certification.take` est refusée par le modèle, Filament et
l'honoration d'une commande. Un référentiel FR/AR complet fournit libellés,
descriptions, ordre et marqueur de relecture ; l'API ajoute cette présentation
sans casser sa liste historique de codes.

`annales.practice` est déclarée mais aucun sélecteur ne l'applique avant l'audit
du marqueur prévu par D-3. Aucun code agrégé de coaching ou de pack n'entre dans
l'autorisation.

Le correctif `cfc3d93` fait passer le référentiel bilingue par son modèle
Eloquent. Le seul accès SQL bas niveau restant est la construction justifiée de
la chaîne d'ascendance globale des portées ; le garde-fou d'architecture le
nomme explicitement au lieu de tolérer toute la classe par accident.

#### Lot 3A.4 · versions d'offre immuables · livré · backend `2e0606b` · frontend `b9e9f4c`

`plans` reste la projection courante administrable, tandis que chaque création
ou modification contractuelle produit automatiquement une `plan_version`
immuable. Prix, devise, durée, capacités, noms et descriptions sont figés ;
ordre d'affichage et retrait de la vente ne versionnent pas. Les offres
historiques reçoivent une version initiale explicitement reconstruite.

Une commande référence désormais la version opaque affichée au candidat et
`AbonnementService` lit exclusivement ses capacités et sa durée. Une version
devenue périmée avant le clic est refusée avec invitation à actualiser, sans
substitution silencieuse et sans consommer le coupon. Le client Nuxt renvoie
ce marqueur pour le paiement simulé ; l'absence du marqueur reste tolérée sur
les anciens clients pendant la transition.

**Vérification :** 42 tests commerciaux ciblés et 245 assertions, typecheck
Nuxt vert, puis suite backend complète séquentielle verte à **726 tests et
2 563 assertions**. Aucune migration n'a été exécutée sur une base durable et
aucun push n'a été effectué.

#### Lot 3A.5 · profils de quota pédagogiques · livré · `85a7634`

`quota_profiles` porte un objet typé et borné — unité, valeur, fenêtre, borne
basse et borne haute, chacune avec sa justification écrite. L'unité et la
fenêtre sont des énumérations fermées **en code ET en base** : `questions`,
liée à `questions.answer`, et `cumulative_grant`, la règle Q-07. Le quota F03
reste hors de ce registre, avec son compteur propre et son comportement
inchangé.

Les bornes sont tenues par contrainte (`min_value <= value <= max_value`,
justifications d'au moins vingt caractères), pas seulement par le service. Un
profil ne se supprime jamais : il se retire de la sélection, et un déclencheur
refuse la suppression. `quota_profile_events` conserve en ajout seul l'auteur,
l'avant et l'après de chaque geste.

**Déplacer une borne exige une justification RENOUVELÉE**, pas seulement non
vide — sans quoi le refus du scénario S-16 se contournerait en ne touchant
qu'au nombre. Le code, l'unité et la fenêtre sont figés après création.

La surface Filament est réservée à `quotas.manage`, permission dédiée portée
par `editeur` et refusée à `finance` : celle qui vend ne borne pas. Le
formulaire des offres reste dépourvu de tout champ numérique de quota — un
test le vérifie champ par champ. `QuotaProfileService::assertSelectionnable()`
livre au pas commercial la garde serveur correspondante : profil actif, unité
compatible avec la capacité, valeur exactement celle du profil, message qui
nomme la borne.

Le profil « Découverte » est semé comme donnée de référence : 40 questions,
bornes 35 et 120, valeurs de l'ADR-0027 et du scénario S-16. Il ne porte aucun
événement de journal, un semis n'ayant pas d'auteur humain à inscrire.

**Hors périmètre, assumé :** aucun rattachement d'un profil à une version
d'offre — c'est le geste de l'admin commerciale, au pas suivant. Aucune
enveloppe, aucune consommation : lot 3B.

**Vérification :** 22 tests ciblés et 84 assertions, deux mutations éprouvées
(retrait de l'exigence de justification renouvelée → les deux tests S-16
rougissent, et eux seuls ; retrait du contrôle de cohérence des bornes → le
test de refus nommé rougit seul), puis suite complète séquentielle verte à
**748 tests et 2 648 assertions**. Aucune migration sur une base durable,
aucun push.

#### Préalable P-Q · le quota se fige dans la version d'offre · livré · `1dddf83`

Un `QuotaProfile` est amendable : une version qui relirait `quota_profiles`
livrerait à une commande d'hier la valeur d'aujourd'hui — le défaut V-3 sous un
autre nom. À la composition, la version COPIE donc le profil sélectionné :
code, unité, fenêtre, valeur, les deux bornes du moment et les deux
justifications qui les fondent. Des colonnes typées sur `plan_versions`, jamais
un JSON libre, et jamais une table fille : la spécification déclare un seul
profil par version, chaque unité désigne exactement une capacité, et une table
fille aurait exigé son propre déclencheur d'immuabilité en face de celui de
`plan_versions`.

Les justifications sont copiées et non pointées. « Référence de justification »
ne peut pas être un renvoi vers le profil — le profil est précisément ce qui
bouge — ni vers `quota_profile_events`, que le profil semé ne porte pas. Le
seul texte qui reste vrai dans dix mois est celui du moment de la sélection.

La SÉLECTION versionne, l'AMENDEMENT non. `plans.quota_profile_id` entre aux
champs contractuels : changer de profil est un geste commercial et crée une
version. Déplacer la valeur ou les bornes depuis le registre pédagogique ne
touche aucune version existante et ne compose rien — sans quoi l'admin
pédagogique recomposerait, depuis un écran qu'elle ne relie à rien, des offres
qu'elle ne voit pas, et les commandes en vol seraient refusées pour version
périmée. Le profil amendé ne sert qu'aux compositions futures.

`QuotaProfileService::assertSelectionnable()` reste le point de passage, à la
sélection comme à la composition, et un refus nommé s'y ajoute : un profil qui
borne une capacité que l'offre ne vend pas ne se compose pas — une enveloppe
que rien ne débite promettrait quarante questions à qui n'a pas le droit d'en
recevoir une.

L'honoration ouvre l'enveloppe LUE SUR LA VERSION : unité, fenêtre et valeur
sont inscrites sur l'octroi de la capacité que l'unité compte, les autres
capacités s'ouvrant sans enveloppe — l'illimité reste une absence, jamais un
nombre. Allocation seulement : ni reliquat, ni décrément, ni trace de service.
La consommation reste le lot 3B, et un compteur posé sans son verrouillage
serait un reliquat que personne ne sait débiter.

**Vérification :** 8 tests ciblés et 28 assertions, mutation éprouvée
(l'honoration relit `quota_profiles.value` → le test d'honoration rougit, et
lui seul, sur les 73 tests commerciaux et de back-office rejoués), puis suite
complète séquentielle verte à **756 tests et 2 676 assertions** en 224,6 s.
Aucune migration sur une base durable, aucun push.

#### Préalable P-E · le canal de correction éditoriale · livré · `d804621`

Le lot 3A.4 interdisait tout UPDATE sur une version ; la spécification
d'administration commerciale autorise depuis AR-1 la correction d'une coquille
**sans version nouvelle**. Les deux ne tenaient pas ensemble : versionner une
faute d'accord ferait de chaque coquille un contrat neuf, et laisser l'UPDATE
ouvert rendrait le prix réécrivable. L'immuabilité devient donc
**différenciée** — les quatre textes (`name_fr`, `name_ar`, `description_fr`,
`description_ar`) et rien d'autre.

Le déclencheur ne lit pas une liste de colonnes autorisées : il compare la
ligne entière MOINS les quatre textes. Toute colonne ajoutée demain à
`plan_versions` est donc immuable par défaut, sans que personne ait à penser à
l'inscrire — une liste positive aurait vieilli au premier ajout.

`corriger_version_editoriale(version, champ, nouveau_texte, auteur, motif)`
écrit la ligne de journal **puis** le texte, dans la même transaction : si
l'UPDATE lève, le journal disparaît avec lui, et il n'existe aucun état où le
texte a changé sans que le journal le dise. La marque qui autorise l'UPDATE est
posée par la fonction, locale à sa transaction, et porte l'identifiant EXACT de
la version corrigée — une marque booléenne aurait laissé réécrire une autre
ligne dans la même transaction. Elle est effacée juste après : une correction,
une ligne, une trace. Champ hors liste, motif de moins de dix caractères, texte
vide ou texte identique sont refusés **en base**, donc sur tous les chemins, y
compris une console.

`plan_version_editorial_fixes` est en ajout seul, comme le journal des profils
de quota. Son identifiant est un UUIDv7 produit par une fonction SQL `uuid7()`
écrite ici : la ligne étant insérée par le canal et non par Eloquent,
`HasPublicUuid` ne pouvait pas la servir, et `gen_random_uuid()` aurait produit
un v4 — la faute que le PAS-1.1 a déjà eu à réparer une fois.

L'autorisation, elle, reste en PHP : `plans.editorial_fix`, permission
plateforme dédiée portée par `editeur` et `super_admin`, vérifiée par
`PlanVersionPolicy::editorialFix` et exigée par le service. Un 403 explicite,
pas un 404 : la règle 404 protège ce qui appartient à autrui, pas une surface
d'administration refusée à un membre du personnel.

**Vérification :** 10 tests ciblés et 28 assertions, mutation éprouvée (le
canal n'écrit plus sa ligne de journal → deux tests rougissent, le test de
correction et celui de l'ajout seul, les deux seuls qui lisent le journal ;
aucun test hors journal ne bouge), puis suite complète séquentielle verte à
**766 tests et 2 704 assertions** en 220,2 s. Aucune migration sur une base
durable, aucun push.

#### Lot 3A.6 pas 1 · la catégorie de public · livré · `52ea8f4`

`audiences` est un objet de catalogue global : code figé après création,
libellé FR **et** AR obligatoires, rang d'affichage, retrait de la sélection.
Deux interdits du §3 de la spécification sont tenus par déclencheur et non par
l'écran : une catégorie ne se supprime jamais — une version vendue peut la
désigner — et son code ne se change pas, puisque des droits le désignent.

**Le rattachement se pose sur la FAMILLE, pas sur la filière.** Une filière
regroupe des concours par nature administrative, une catégorie de public
regroupe des candidats par situation : les deux se croisent sans se contenir, et
deux familles d'une même filière peuvent viser deux publics. C'est aussi là que
le scénario S-11 le pose — « une famille rattachée ».

L'ascendance des droits en découle et ferme **DET-87** : un droit
`(audience, X)` couvre la famille rattachée, ses épreuves et ses nœuds à toute
profondeur ; il ne couvre rien d'une autre catégorie, et rien d'une famille que
personne n'a rattachée. Les familles en liste d'attente n'ont donc reçu aucun
public : leur en inventer un aurait posé une donnée fausse dans un chemin
d'autorisation.

Le public éligible entre aux champs contractuels (Q-19) : en changer versionne,
et la version vendue garde le public sous lequel elle a été vendue. La migration
rattache à `crmef` les familles et les offres existantes ; les semis font de
même pour un catalogue frais, sans quoi une installation neuve porterait des
offres sans public.

**Vérification :** 15 tests ciblés et 35 assertions, puis suite complète
séquentielle verte à **781 tests et 2 740 assertions** en 220,6 s. Aucune
migration sur une base durable, aucun push.

#### Lot 3A.6 pas 2 · les libellés des capacités · livré · `5ec4499`

Le référentiel `capability_definitions` existe depuis le lot 3A.3 : neuf codes,
libellés et descriptions FR/AR, contrainte de code connu, et refus de
composition quand le référentiel bilingue est incomplet. Ce pas ne le recrée
pas — il lui donne son écran et prouve son refus.

L'écran n'ouvre que la PRÉSENTATION : le code se lit, il ne se saisit pas, et
ni la création ni la suppression n'existent — la liste est fermée dans
`CapabilityRegistry`, une ligne créée ici produirait un droit qui n'ouvre rien
et une ligne supprimée rendrait invendable une capacité que le code applique.
La contrainte `capability_definitions_code_known` tient la même règle en base.

**Enregistrer retire le badge « à relire »** (ADR-0032). Les neuf libellés
viennent d'une migration : ce sont des valeurs d'architecte, et le marqueur les
distingue d'une donnée confirmée. Il n'est pas un champ du formulaire — un badge
qu'on coche soi-même ne prouve rien — et il ne se repose pas.

**Vérification :** 5 tests ciblés et 13 assertions, dont le refus de
composition d'une capacité privée de son libellé, puis suite complète
séquentielle verte à **786 tests et 2 754 assertions** en 221,5 s.

#### Lot 3A.6 pas 3 · composer une offre sans développeur · livré · `656ddd9`

`PlanResource` est ÉTENDU, pas remplacé. Il porte désormais tout ce que la
matrice §5 confie à l'admin commerciale : catégorie de public, textes FR/AR,
note de catalogue interne, prix, devise, durée, calendrier de
commercialisation, capacités, profil de quota, portée typée, mise en vente et
rang.

**Ce qui versionne suit la règle, pas le formulaire** : public éligible,
calendrier et portée entrent aux champs contractuels ; la note interne, la mise
en vente et le rang n'y sont pas. Le calendrier est le cas limite — il dit quand
l'offre se voit, mais décide aussi si la souscription est possible : la matrice
le fait versionner, et la version porte sa propre fenêtre.

**Une date se compare par instant, pas par identité d'objet.** `sameContract`
normalise : deux `Carbon` égaux comparés par `!==` auraient composé une version
neuve à chaque lecture du catalogue, jusqu'à refuser pour « version périmée » la
commande du candidat qui a l'écran ouvert. Un test rejoue deux lectures et
vérifie qu'aucune version n'apparaît.

**Chaque interdit du §3 a son refus serveur**, jamais un masquage : type de
portée hors énumération (fermée en code ET en base), objet de portée inexistant
ou archivé, couple de portée mi-nul, fenêtre fermée avant d'être ouverte,
catégorie retirée, devise hors liste, capacité non commercialisable, profil hors
bornes. Hors calendrier, l'offre quitte le catalogue (`Plan::enVente`) et la
souscription lève `hors_periode` sans consommer le coupon — aucun bouton grisé.

**Le champ de quota est une liste, et le test le dit mieux qu'avant.** La garde
du lot 3A.5 refusait « tout champ dont le nom contient quota » ; elle tenait tant
que l'écran ne sélectionnait rien. Elle vérifie maintenant que le seul champ de
quota est `quota_profile_id`, que c'est un `Select`, que ses options sont
exactement celles du registre pédagogique, et qu'aucun champ de valeur ou de
borne n'existe ici. L'intention est inchangée, la garde est plus étroite.

**Vérification :** 15 tests ciblés et 28 assertions, plus la garde du quota
resserrée, puis suite complète séquentielle verte à **801 tests et 2 780
assertions** en 226,1 s.

#### Lot 3A.6 pas 4 · l'historique et ses dépendances · livré · `921c1ad`

Le journal des versions existe depuis 3A.4 ; ce pas l'EXPOSE et complète les
deux colonnes que la spécification §2.6 demandait sans qu'elles existent :
`composed_by` — nullable, parce que trois compositions n'ont pas d'auteur humain
et lui en fabriquer un serait la première ligne fausse du journal — et
`triggered_by`, liste fermée par `<@` sur le vocabulaire des champs
contractuels, au pluriel parce qu'un enregistrement peut en changer deux à la
fois.

Le panneau des versions rend, par version : numéro, date d'effet, auteur, champs
déclencheurs, prix, **commandes qui la référencent, droits qui en découlent et
droits encore actifs**. Les deux derniers nombres ne disent pas la même chose —
un droit expiré reste une dépendance. C'est cette lecture qui rend le §3
compréhensible plutôt que frustrant : un refus de modification qu'on ne peut pas
expliquer se lit comme une panne.

Les droits se comptent par la chaîne commande → octroi, sans dénormalisation :
`origin_reference` porte l'uuid de la commande. La sous-requête cast
explicitement (`uuid::text`), la colonne étant une chaîne qui accueille aussi
des références de dossier support.

Le bouton « corriger une coquille » APPELLE le canal éditorial de M-003 : champ
choisi dans la liste des quatre textes, motif obligatoire, journal écrit dans la
même transaction. Il n'apparaît que sous `plans.editorial_fix` — et s'il
apparaissait à tort, la fonction SQL refuserait quand même. Aucune version ne se
crée ni ne se supprime depuis l'écran.

**Une fuite de traduction fermée en chemin** : le panneau est la première
surface à rendre une section repliable, et `filament/support` ne traduit pas
`components/section` en français. `fallback_locale` valant `fr`, la clé
s'imprimait au lieu de se résoudre. Les six fichiers manquants du paquet sont
désormais traduits — même correctif que celui déjà appliqué aux dix autres
paquets Filament.

**Vérification :** 8 tests ciblés et 43 assertions, puis suite complète
séquentielle verte à **809 tests et 2 823 assertions** en 227,6 s.

#### Lot 3A.6 pas 6 · le scénario lycée, et l'acceptation du lot · livré · `b1fe327`

Le premier test d'acceptation de la spécification n'est pas une lecture de code :
ce sont les gestes, à l'écran, avec les permissions d'une admin commerciale. Le
test crée la catégorie `lycee`, puis le pack « Suivi mensuel » — 30 jours,
`questions.answer` + `mastery.detail` + `remediation.plan` + `memory.sessions`,
portée `(audience, lycee)` — et vérifie que la version 1 porte tout le contrat,
que son auteur est signé, que **le compte de migrations n'a pas bougé** et que
le catalogue rend l'offre au candidat dans la foulée. Les assertions d'USAGE de
S-11 et S-12 (couverture des épreuves, enveloppe neuve au renouvellement)
restent aux lots 3A.9 et 3B.

`certification.take` n'est ni proposée à l'écran ni acceptée par le serveur, et
le refus nomme la capacité **et** la raison. Un changement de prix compose une
version nouvelle pendant qu'une commande en attente garde la sienne et est
honorée à ses conditions. Aucun écran ne crée une capacité, un type de portée ni
une règle de consommation : les deux registres fermés se rendent en liste, et la
surface des libellés n'a pas de page de création.

**Vérification :** 4 tests ciblés et 35 assertions, puis suite complète
séquentielle verte à **813 tests et 2 858 assertions** en 226,1 s. Les quatre
mutations applicables du §6 sont éprouvées et consignées dans
`retours/M-004-retour.md` — la cinquième porte sur la demande de gratuité, qui
appartient au lot 4.

#### Lot 3A.7 pas 1 · le porteur du gratuit · livré · `46d267a`

L'ADR-0025 le dit : « le gratuit n'est ni l'absence d'offre, ni un réglage
global, ni un paiement nul ». C'est donc un `Plan` ordinaire — prix réellement
zéro, durée vide donc sans terme, `questions.answer` seule — semé par le même
chemin que l'écran commercial, et qui SÉLECTIONNE le profil « Découverte » du
registre pédagogique au lieu d'écrire quatre nombres. Sa version 1 en porte
l'instantané (M-003).

**`auto_granted` plutôt que `active = false`.** Le drapeau dit la seule chose
vraie : cette offre se reçoit au lieu de s'acheter. `active = false` aurait dit
« retirée de la vente » — ce qu'elle n'est pas — et `CouponGateway` lit cette
colonne pour refuser une souscription. Le drapeau n'est pas contractuel : il ne
vit pas sur la version et ne versionne pas, puisqu'il change par quel chemin on
obtient, pas ce qu'on obtient.

**Un seul porteur, tenu par un index unique partiel.** Deux offres
auto-attribuées et l'inscription choisirait en silence, à l'ordre d'insertion
près. L'index rend la question impossible plutôt qu'arbitraire.

L'offre quitte le catalogue candidat (`Plan::enVente`) sans quitter
l'administration. Changer son profil de quota versionne, et la version déjà
distribuée garde son enveloppe : personne ne perd rien.

**Vérification :** 8 tests ciblés et 26 assertions, puis suite complète
séquentielle verte à **821 tests et 2 884 assertions** en 226,3 s.

#### Lot 3A.7 pas 2 · l'auto-attribution à l'inscription · livré · `6aff8ef`

`AbonnementService::octroyerLesDroits()` est extrait de `honorer()` et devient
le seul endroit qui pose des octrois. L'origine, la référence et la note sont
des paramètres ; tout le reste — une ligne par capacité, l'échéance calculée sur
la durée de la version, l'enveloppe lue sur l'instantané — est commun. Sans
cette extraction, l'attribution gratuite aurait été un second circuit d'octroi,
et le gratuit aurait vieilli autrement que le payant.

`OffreGratuiteService` choisit l'offre, vérifie, et appelle ce service. Il
n'ouvre aucun droit lui-même : c'est la condition posée par l'ADR-0025 — « son
attribution crée des droits par la chaîne normale ».

**Attribué dans la transaction d'inscription**, pas à la vérification d'e-mail :
`RegistrationService` énonce déjà qu'« un compte sans identité, sans rôle ou
sans acceptation des CGU serait invalide » ; un compte sans son palier gratuit
l'est de la même façon. La vérification d'e-mail est une porte sur l'USAGE, pas
un changement de nature du compte.

**Origine `account_level`**, valeur qui existe depuis le PAS-8 et qui dit
exactement ce dont il s'agit : un droit qui vient du niveau du compte. Jamais
`purchase` — aucun agrégat de vente ne compte un droit que personne n'a acheté
(ADR-0028, C-05). Aucune commande n'est créée.

**Idempotence jugée sur TOUTES les versions**, pas sur la courante : un compte
inscrit sous la v1 ne doit pas recevoir la v2 parce qu'un rattrapage passe après
un changement de quota. L'index unique `(user_id, capability, origin_reference)`
reste la dernière serrure contre deux attributions concurrentes.

Le droit gratuit référence la version, et le journal des versions le compte
désormais dans ses dépendances — une version distribuée à dix mille comptes ne
peut pas s'afficher « sans dépendance » faute de commande.

**Vérification :** 6 tests ciblés et 28 assertions, dont le grandfathering
(inscrit avant : 40 ; inscrit après : 60), puis suite complète séquentielle verte
à **827 tests et 2 912 assertions** en 228,8 s.

#### Lot 3A.7 pas 3 · le rattrapage des comptes existants · livré · `5ed8d03`

Une commande, pas une migration : une migration de schéma qui distribue des
droits mélange la forme de la base, qui se déploie une fois, et une décision
d'exploitation, qui se prévisualise et se relance. `naja7i:rattraper-le-gratuit`
parcourt les comptes CANDIDATS, saute ceux qui portent déjà le gratuit — quelle
que soit la version dont il vient — et rend un compte rendu chiffré :
`poses`, `deja_porteurs`, `examines`, plus l'offre, sa version et son enveloppe.

`--dry-run` compte sans écrire. Une distribution de droits se prévisualise avant
de se faire (ADR-0025 : « geste explicite, prévisualisé et tracé »).

Les droits posés portent l'origine **`rattrapage`** : ils n'ont pas été posés à
l'inscription mais des mois après, par une décision d'exploitation, et un audit
doit pouvoir les distinguer sans deviner. Sans offre auto-attribuée, la commande
échoue en le disant plutôt que de deviner quoi distribuer.

**Elle n'a été exécutée sur aucune base durable.** Elle est livrée testée sur
une base peuplée ; son heure viendra à l'allumage de 3A.9, avec le droit
transitoire de 3A.8.

**Vérification :** 7 tests ciblés et 33 assertions, dont le rejeu, le mode sec,
l'exclusion du personnel et le grandfathering, puis suite complète séquentielle
verte à **834 tests et 2 945 assertions** en 232,8 s.

#### Lot 3A.7 pas 4 · ce que le candidat lit de son palier · livré · `94bb888`

`me/subscription` porte désormais `quotas` : **une entrée par octroi
enveloppé**, jamais un total. « Deux enveloppes sur des portées distinctes ne
sont jamais additionnées » (ADR-0031), et un renouvellement crée une enveloppe
neuve (ADR-0027) : un seul nombre effacerait la question qui compte pour le
candidat — laquelle se vide en premier. Il n'y en a qu'une aujourd'hui, celle du
palier gratuit ; la forme est juste dès maintenant.

Le reliquat vaut l'enveloppe, et c'est exact : rien ne consomme encore, le débit
est le lot 3B. Un reliquat inventé serait pire qu'un champ absent — le candidat
le lirait comme une mesure.

La NATURE du droit se dit en deux mots du produit (`gratuite` / `achetee`) et
leur libellé traduit FR/AR, jamais par le code d'énumération qui la porte en
base : `account_level` et `rattrapage` disent la même chose du point de vue du
candidat — il ne l'a pas payé. Liste blanche stricte, vérifiée clé par clé :
aucun identifiant, aucune origine technique.

**Vérification :** 6 tests ciblés et 31 assertions, puis suite complète
séquentielle verte à **841 tests et 2 978 assertions** en 232,9 s. Les trois
mutations de la mission sont éprouvées ; la deuxième a conduit à **renforcer**
le test de grandfathering, qui ne discriminait pas — détail au retour.

#### Lot 3A.8 pas 1 · la pose du droit transitoire · livré · `1c08918`

Q-17 tient en cinq mots — équivalent au 600, 60 jours, nommé, tracé, visible —
et chacun a sa contrepartie. **Nommé** : origine `transition`, jamais
`purchase`, aucune commande, aucun agrégat de vente. **Tracé** :
`transition_batches`, en ajout seul, qui fige l'auteur, le motif, le périmètre,
les trois nombres **et la version d'offre de référence** — « équivalent au
palier 600 » n'a de sens que si l'on peut relire à quoi, exactement.

**Le paramétrage d'abord, même pour un geste qui ne servira qu'une fois.**
Durée, public visé, date de pose et palier de référence sont les paramètres du
geste. 60 est la valeur de DÉPART, bornée 7–180 en base comme en service : sous
une semaine le sevrage est subi plutôt qu'annoncé ; au-delà de six mois ce n'est
plus une transition mais un palier gratuit déguisé.

**« Équivalent au palier 600 » se lit dans le catalogue, pas dans le code.**
Écrire les huit capacités ici en ferait une seconde source de vérité qui
mentirait au premier changement. On lit la composition d'une offre réelle — par
défaut la plus complète — et l'on **refuse en nommant la capacité** si elle porte
du non-commercialisable. Filtrer en silence serait pire : le geste réussirait en
donnant autre chose que ce qui a été demandé.

**La prévisualisation n'est pas un confort** (Q-17 l'exige) : commande
`--dry-run` et bouton d'écran annoncent comptes visés, déjà porteurs et à
servir, sans rien écrire. La commande **refuse de poser sans auteur** — une
commande anonyme serait la migration silencieuse que Q-17 refuse ; l'écran, lui,
prend l'auteur de la session.

Le public visé se déduit de l'épreuve déclarée par le candidat (épreuve →
parcours → famille → catégorie, rattachement livré en 3A.6). Un compte sans
épreuve déclarée n'a pas de public connu : un geste ciblé ne le touche pas.

Le droit gratuit sans terme et son enveloppe **restent intacts dessous**
(AR-2), et rejouer la pose ne double rien.

**Vérification :** 21 tests ciblés et 84 assertions, puis suite complète
séquentielle verte à **862 tests et 3 063 assertions** en 233,5 s.

#### Lot 3A.8 pas 2 · le sevrage s'annonce · livré · `a487bf2`

Le droit transitoire n'a pas d'enveloppe — comme tout palier payant — il
n'apparaissait donc nulle part sur un écran qui ne rendait que des quotas.
`me/subscription` porte désormais `droits` : **une ligne par (nature,
échéance)**, avec ce qu'elle ouvre, son libellé traduit et **sa date de fin**.

Jamais un état « abonné » unique : un compte porte le même jour un palier
gratuit sans terme et un accès transitoire de soixante jours, et rendre un
booléen effacerait la seule chose que le candidat a besoin de savoir — ce qui
s'arrête, et quand. Les lignes sont ordonnées par échéance la plus proche ; le
sans-terme ferme la liste, faute d'avoir une décision à demander.

La nature `transitoire` rejoint `gratuite` et `achetee` : trois mots du produit,
leurs libellés FR/AR par clés de traduction, et jamais le code d'origine.

**Vérification :** 6 tests ciblés et 25 assertions, puis suite complète
séquentielle verte à **868 tests et 3 088 assertions** en 243,6 s.

### LOT-Q0/Q1 — Contrôle du corpus QCM avant import · livré · `0f01fa6`

Le corpus externe reste hors base et inchangé. La commande
`qcm:verifier` en construit une projection déterministe, conserve les faits et
les métadonnées provisoires dans `import_metadata`, et interdit toute réponse
correcte inventée. Le mode normal produit le rapport d'inspection ; `--strict`
échoue tant qu'une anomalie bloquante subsiste. Aucun import ni aucune migration
sur une base durable ne fait partie de ce lot.

État mesuré du corpus : 1 413 questions, 29 sujets, 13 disciplines, 3 familles,
701 suggestions de réponse,
22 ambiguïtés, 75 doublons signalés, 5 énoncés illisibles et 27 fuites Markdown
strictes. Seules 212 projections sont importables ; 1 200 lignes sans domaine
doivent passer par une file de qualification pédagogique. Le lot suivant doit
donc construire une zone de préparation et son interface Filament, et non
affaiblir les invariants de la banque publiée ni fabriquer un domaine par défaut.

**Vérification :** 9 tests ciblés, 53 assertions ; suite complète verte à
682 tests et 2 392 assertions. Le mode strict retourne volontairement un code
d'échec sur le corpus actuel.

**Supersession :** le modèle d'exploitation de `schema-qcm.sql` est remplacé par
la zone de préparation ; la correspondance contrôle par contrôle et les cinq
contrôles sans équivalent sont dans `docs/IMPORT-QCM-SUPERSESSION.md`.

#### Correctif Q1.1 · livré · `af2ae76`

La difficulté arbitraire du corpus ne devient plus une donnée de production :
la projection laisse `questions.difficulty` nulle et conserve la valeur source
uniquement dans `import_metadata.provisional`. L'échelle déclarée accepte les
cinq crans décidés, sans inventer leurs libellés pédagogiques. Les métadonnées
d'import sont masquées de la sérialisation par défaut. Vérification ciblée
commune avec V-2 : 32 tests et 208 assertions.

Cette intégration périme comme sources exécutoires les ADR homonymes du dossier
externe `outputs/lot-0/` et, pour F07/F09, leurs fiches homonymes originales.
Elle ne remplace ni le rapport externe consolidé ni les arbitrages encore
ouverts des fiches brouillon.

### LOT-Q2.1 — Socle de préparation pédagogique · livré · `1b7f171`

Une zone de travail distincte reçoit les faits sources, hypothèses provisoires,
anomalies et décisions humaines sans devenir une seconde banque. Elle ne porte
ni publication, ni éligibilité candidat, ni transfert vers `questions`.

Une retranscription remplace la ligne active sans effacer l'historique ; un
doublon ne se transfère jamais ; suggestion et réponse confirmée restent deux
faits différents. Chaque geste humain est journalisé en ajout seul avec acteur,
date, ancienne et nouvelle valeur. La validation définitive reste exclusivement
dans la chaîne éditoriale existante.

**Vérification :** 19 tests Q2 et 61 assertions ; la première reconstruction a
révélé un ordre invalide des auto-références, corrigé avant commit. Avec les
portées 3A.2 et les régressions abonnement/parcours/mémoire : 94 tests et 500
assertions verts. Aucun corpus n'a été importé et aucun transfert n'existe.

L'interface Filament, le transfert contrôlé et l'ouverture aux experts restent
des pas séparés. DET-85 et DET-86 en portent les préalables pédagogiques.

### LOT-1A — Administration des personnes · livré · `821e732`

Le panneau Filament expose l'annuaire du tenant courant sous `members.view`, la
création et la mise à jour des comptes sous `members.invite`, et l'attribution
des rôles sous `roles.assign`. Les rôles restent des appartenances tenantées ;
aucun contrôle n'est fondé sur un nom de rôle. Un gestionnaire ne peut pas
modifier ses propres rôles et aucun effacement de compte n'est offert.

La création administrative est volontairement limitée au personnel : créer un
candidat depuis le panneau contournerait les actes juridiques de l'inscription.
Le socle ne possède pas encore de véritable invitation avec lien de définition
du mot de passe ; le lot exige donc un mot de passe temporaire transmis par un
canal sûr. Cette limite devra être fermée avant ouverture à une équipe large.

#### Correctif 1A.1 · livré · `3feb1bd`

La délégation est désormais bornée : un acteur ne peut attribuer que des rôles
dont il détient toutes les permissions, et `super_admin` ne se transmet que par
un super-administrateur. Un compte suspendu perd immédiatement l'accès au
panneau, une synchronisation ne peut pas retirer la dernière appartenance du
tenant, et inscription, réinitialisation et administration partagent la même
politique centralisée de mot de passe.

Vérification de sortie : suite complète séquentielle verte, **652 tests et
2 227 assertions**. L'interface peut encore afficher un rôle que le serveur
refusera à l'acteur courant ; c'est une amélioration UX mineure à traiter sans
affaiblir la garde serveur.

Cette amélioration UX est fermée par `4015a44` : le formulaire ne propose plus
que les rôles réellement attribuables par l'acteur, sans dupliquer ni affaiblir
la garde serveur.

### LOT-2A — Mon dossier backend et invitation du personnel · livré · `21fc459`

Le propre compte dispose de contrats communs candidat/personnel pour modifier
coordonnées, langue et mot de passe sans ouvrir rôles, statut, tenant ou actes
non révocables. L'invitation du personnel remplace le mot de passe temporaire :
jeton opaque stocké hashé, expirant, à usage unique, réinvitation révoquant le
précédent et compte inutilisable avant acceptation.

Vérification de sortie : suite complète séquentielle verte, **659 tests et
2 282 assertions**. Le rendu candidat/personnel reste à livrer au lot 2B.

Le rendu personnel Filament est livré par `b537d00`. Vérification backend après
ce rendu : suite complète verte, **667 tests et 2 313 assertions**. Le rendu
candidat Nuxt et l'acceptation d'invitation sont livrés dans le dépôt frontend
par `c43075e` ; typecheck, build de production et contrôles FR/AR/RTL sont verts.

#### Correctif 2.1 · livré · backend `d719f75` · frontend `76e0325`

Un changement effectif d'e-mail exige désormais le mot de passe courant sur
les deux surfaces, candidat et personnel ; les changements de téléphone ou de
langue n'en demandent pas. « Mon dossier » candidat écrit réellement le profil
préparé par `PUT /me/profile` : épreuve choisie dans le catalogue ouvert,
objectif et date cible, avec états de chargement, erreur et succès en FR/AR.

Vérification de sortie : suite backend complète séquentielle verte, **673 tests
et 2 339 assertions** ; frontend `dossier`, `locales`, `typecheck` et build de
production verts. L'envoi synchrone d'invitation reste rattaché à DET-14 ; la
consommation d'une invitation d'un compte suspendu ne donne aucun accès mais
mérite une révocation explicite lors d'un futur passage sur ce workflow.

### LOT-0A.2 — Arbitrages Q-07/Q-08/Q-13 · livré · `3eeff4a`

Le quota gratuit est cumulatif sans remise à zéro automatique, la consommation
se produit au premier service idempotent et tout changement contractuel crée
automatiquement une version d'offre. Les exemptions F05 miroir et F07 mémoire
restent acquises. La valeur initiale de 40 questions est un paramètre à calibrer,
jamais une constante de code.

## 4. Pas non ouverts — ne pas auditer comme manquants

| Lot | Contenu | Statut |
|---|---|---|
| Séries d'entraînement ciblées | Composition adaptative | Non ouvert |
| Simulateur d'examen | Chronomètre, barème, rapport | Non ouvert |
| Module Opportunités | Veille, annonces, alertes | Non ouvert |
| Commercial et CMI | Offres, commandes, paiement | Non ouvert |
| Import de questions en volume | JSON/CSV, prévalidation, rejet détaillé ligne à ligne | Non ouvert — n'a de sens qu'une fois la rédaction unitaire (PAS-27) éprouvée |
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
| Suite de tests non parallélisable : `--parallel` ne démarre pas, paratest absent. **Réduit au PAS-20** : la durée est passée de 249 s à ~117 s sans dépendance, la parallélisation n'est plus un besoin | DET-28 | Si la durée redevient un problème |
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

## 6 bis. Questions ouvertes d'infrastructure

Elles ne bloquent aucun lot, et se poseront au premier déploiement. Écrites ici
pour n'être ni découvertes ni tranchées dans l'urgence.

| Question | Pourquoi elle se pose | Quand |
|---|---|---|
| **`/admin` est-il exposé publiquement ou réservé au réseau interne ?** | Le panneau du lot A4 ouvre une route WEB, alors que toute la surface antérieure est sous `/api/v1` derrière le BFF. Exposé, il devient une cible d'authentification supplémentaire ; interne, il demande un accès réseau au personnel éditorial. La décision est d'infrastructure, pas de code — le panneau fonctionne dans les deux cas. | Premier déploiement |

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

### Critères de sortie d'un sous-cycle

Un sous-cycle de correction est clos lorsque les invariants qui l'ont ouvert
sont couverts par une règle générale exécutable, et non par une accumulation de
correctifs ponctuels.

Cette règle d'arrêt n'est pas celle des limites ci-dessus, et les deux ne se
contredisent pas : elles mesurent des unités différentes. **Le plafond de deux
rounds s'applique à un pas donné** — au-delà, OptimGov arbitre. **Les cinq
critères de sortie s'appliquent à une lignée thématique**, qui peut traverser
plusieurs pas.

Les critères de sortie sont les suivants :

1. la règle générale est formulée dans l'ADR concerné et son périmètre est
   explicite ;
2. chaque garde critique possède un test discriminant : retirer ou neutraliser
   la garde fait échouer au moins un test précis pour la raison visée ;
3. les tests de concurrence prouvent l'entrelacement recherché et n'observent
   pas un verrou incident produit par une clé étrangère ou une autre garde ;
4. une mutation qui ne peut que **réduire** les privilèges reste autorisée, sauf
   invariant métier contraire explicitement documenté et testé ;
5. après clôture, une nouvelle variante théorique du même motif ne suffit pas à
   rouvrir le sous-cycle : elle est évaluée selon le critère de risque
   ci-dessous.

La clôture d'un sous-cycle est **prononcée par OptimGov**, sur constat que les
cinq critères sont remplis. L'architecte et l'audit externe établissent ce
constat ; ils ne le prononcent pas (`METHODE.md` §4).

**Réouverture d'un sous-cycle clos.** Un scénario ne rouvre le sous-cycle que
s'il réunit simultanément les trois conditions suivantes :

- **Acteur existant :** l'acteur capable d'exécuter le scénario existe dans
  l'état actuel du système ;
- **Chemin atteignable :** le scénario est réalisable sans privilège
  d'administration de base de données ;
- **Dommage identifiable :** la réussite du scénario produit un dommage concret
  et identifiable sur les données, les droits, la confidentialité, l'intégrité
  du parcours ou un engagement métier.

À défaut de réunir ces trois conditions, le constat **ne rouvre pas** le
sous-cycle. Il est inscrit au registre de dette avec une **échéance explicite**,
liée au moment où l'hypothèse deviendra atteignable ou au jalon où son risque
devra être réévalué.

**Portée du critère de privilège.** Ce critère régit la RÉOUVERTURE d'un
sous-cycle, non le maintien ni la couverture des défenses déjà en place. Une
défense de profondeur reste légitime et testée même si le scénario qu'elle
couvre ne rouvre pas un sous-cycle : c'est le cas du contrôle porté par
`PermissionResolver`, éprouvé au PAS-12 en désactivant réellement les triggers,
et de la position d'ADR-0021 selon laquelle « la base garantit qu'aucun chemin
ne l'esquive ». Constater qu'un scénario exige un privilège d'administration de
base répond à la question « faut-il rouvrir ? », jamais à « faut-il retirer
cette garde ? ».

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

Depuis `9a9c2bd`, `phpunit.xml` réserve 256 Mio au seul processus de test. La
suite de 719 tests dépassait la valeur CLI de 128 Mio pendant la construction
du cache de métadonnées PHPUnit ; sans cette enveloppe, elle s'arrêtait avant
son verdict. La suite complète est verte à 2 547 assertions. Cette valeur ne
modifie aucune limite de l'application ou des conteneurs de production.

La suite tourne **en séquentiel**. Elle n'est toujours pas parallélisable —
Collision exige `brianium/paratest`, absent des dépendances — et **ce n'est
plus le sujet**. Mesurée au PAS-20, la lenteur ne venait pas du séquentiel :
67 % du temps était du montage, dont l'essentiel tenait à deux causes
réparables sans aucune dépendance (semis rejoué à chaque test, argon2id au coût
de production). L'interblocage sur les suppressions de tables décrit au §6
reste **non reproduit et non vérifié** : le vérifier suppose d'installer
paratest, ce que la mesure ne justifie plus. Voir DET-28. La CI exécute la
suite en séquentiel.

**Aucune durée n'est citée ici, et c'est délibéré.** Le PAS-25 a établi que les
écarts observés sont de l'attente d'hôte et non du travail — le CPU reste
constant quand l'horloge varie du simple au décuple. La méthode de mesure, avec
sa fourchette, vit dans `CLAUDE.md` (section « Conventions du dépôt ») et
NULLE PART AILLEURS : deux endroits portant le même chiffre finissent toujours
par diverger, et c'est le défaut qu'on vient de corriger sur `AGENTS.md`.
