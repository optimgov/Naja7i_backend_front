# Naja7i — Backlog restant, de l'état actuel à la fin du projet

> **Statut.** Établi le 22 août 2026 par le chef d'orchestre, à partir de
> l'état réel du dépôt. C'est **l'ordre d'exécution courant** : il périme le §6
> du cadrage consolidé v1.2 et le backlog du cahier v1.1, qui ne doivent plus
> servir à décider quoi faire ensuite.
>
> Ce document dit **où l'on va**. `docs/BACKLOG.md` dit **ce qui a été livré** :
> les deux se lisent ensemble et ne se remplacent pas.

**22 août 2026, matin · chef d'orchestre Claude « Fable 5 »**
**Point de départ :** backend `f6b3982` (= `origin/main`, tout poussé), 841
tests verts. Livrés depuis hier : lots 0A, 1 (personnes), 2 (Mon dossier),
Q0/Q1 + Q1.1, Q2.1 (zone de préparation), 3A.1→3A.7 (dont P-Q/P-E), ADR-0025
à 0032, supersession import, proposition d'arbres DIDACTIQUE/SAVOIRS.
**Structure :** quatre jalons produits, chacun avec ses lots, ses décisions
propriétaire et son chemin critique. Les numéros de lots historiques sont
conservés.

---

## JALON 1 — « Le produit segmenté » : essai, conversion, épuisement

*Le moteur pédagogique existe déjà ; ce jalon le met sous le modèle commercial.
Ordre révisé le 22 août 2026 après la décision « le gratuit est un essai » :
**on ne segmente pas avant de savoir compter** — la consommation précède
l'allumage, et la recette précède la base durable. Horizon : jours.*

| # | Lot | Contenu | État | Dépend de |
|---|---|---|---|---|
| 1.1 | **La conversion** (M-006bis) | ADR-0033 · amendements ADR-0025/0027/0029 · clôture transactionnelle de l'essai · garde double d'attribution · état dérivé · S-01 réécrit, S-17/S-18 ajoutés | **en cours** | — |
| 1.2 | **3A.9 — les murs** (M-007) | Fermer `series.targeted`, `simulator.full`, `mastery.detail`, `remediation.plan`, `memory.sessions` dans les 4 contrôleurs ; **trois états** de restitution ; champ absent, jamais grisé ; S-02/S-05/S-06/S-19 | À faire | 1.1 |
| 1.3 | **3B — la consommation** (M-008) | Débit idempotent `(user, tentative, item)` au premier service ; **une seule enveloppe effective** ; coût annoncé avant composition ; bornes anti-aspiration (Q-22) ; S-09/S-10 | À faire | 1.2 |
| 1.4 | **Frontend des trois états** (M-009) | Abonnement, tableau de bord, catalogue ; appel à l'action « renouveler » ; **jamais de retour au gratuit** | À faire — après chaque contrat backend, jamais en même temps | 1.2, 1.3 |
| 1.5 | **Recette de bout en bout** (M-010) | Essai → conversion → épuisement → renouvellement, sans retour au gratuit, en concurrence | À faire | 1.4 |
| 1.6 | **L'allumage** (M-011) | Rattrapage puis murs, sur la base durable — **en dernier**, après la consommation et la recette | À faire — **uniquement sur votre ordre explicite** | 1.5 |

**Le droit transitoire est livré mais DORMANT.** Le lot 3A.8 existe, testé
(`1c08918` → `693ecd5`) ; il n'est posé sur aucune base. Sa pose appartient à
l'allumage — **qui passe après la consommation, sans quoi un gratuit annoncé
limité resterait illimité**. Il ne convertit pas et ne se pose jamais sur un
compte converti (ADR-0033).

**Décisions propriétaire de ce jalon :** l'**ordre d'allumage** (1.6) · **Q-22**
(miroirs par couple — reco : 3, paramètre borné).

---

## JALON 2 — « Le programme experts » : de vraies personnes, du vrai contenu

*Le vrai but des lots commerciaux. Le chemin critique n'est pas technique,
il est éditorial et juridique. Horizon : semaines.*

| # | Lot | Contenu | État | Dépend de |
|---|---|---|---|---|
| 2.1 | **Lot TAXO — l'écran des taxonomies** | Profils (niveaux FR/AR, min_depth), nœuds (création, déplacement gardé — ferme DET-88), **poids avec justification + badge « observé/validé »**, activation des 4 nœuds SE commentés | À faire — la réponse structurelle à D-1, réutilisable pour chaque concours futur | — (parallèle au jalon 1) |
| 2.2 | **D-1 — créer les arbres** | Valider la proposition du 22/08 (20 épreuves, domaines imprimés, option (c) « l'arbre pousse avec la qualification ») **dans l'écran TAXO** | **Proposition livrée — votre validation** | 2.1 |
| 2.3 | **Q2 service — l'interface experts** | File de qualification, saisie corrigés/justifications (jamais pré-remplie), permission dédiée (Q-10), libellés de difficulté (Q-09 bis, DET-86), mécanisme de retranscription (correction C-A), invariant doublons (C-B) | À faire | Q-09/Q-10 |
| 2.4 | **Import réel du corpus** | 1 413 lignes dans la zone (jamais dans la banque), sur base durable | À faire — sur votre ordre | 2.3 |
| 2.5 | **Lot 4 — accès gratuit + octroi expert** | `AccessRequestService` (version affichée exigée, refus sans substitution), **octroi direct expert tracé** (O-5, reco oui), funnel mesuré, **signalement éditorial structuré** (les experts valident le contenu en travaillant) | À faire | 3A/3B |
| 2.6 | **Prérequis juridiques du test externe** | SEC-1 **prouvé sur les octets** (page = document API versionné), textes provisoires marqués, info données de test + date de purge, question CNDP posée ; l'acte « participation à la recette » vs « J'accepte » | À faire — **bloque toute invitation** | juriste |
| 2.7 | **DET-83 + DET-14** | Invitations hors transaction, notifications en file | À faire avant les invitations | — |
| 2.8 | **Contenu SE — les 245 questions** | Qualification (32) + confirmation (213) + corrigés + causes par les experts ; décision DET-16 (`hors_nomenclature`, correctifs A/B) | Démarre dès 2.3 — **c'est le travail qui occupe les experts pendant que le reste se construit** | 2.3 |

**Décisions propriétaire :** **D-1** (valider les arbres — proposition prête) ·
**Q-09/Q-10** (échelle de difficulté et qui la pose) · **O-5** (octroi direct —
reco oui) · **O-4** (redemande après refus) · **O-6** (libellés FR/AR — dont
« au hasard », décisif pour la donnée) · **DET-16** (codes de cause) ·
composition du **panel d'experts** · **juriste** (textes) · ordre d'**import
réel** (2.4).

---

## JALON 3 — « L'ouverture publique CRMEF »

*Le produit vendu à de vrais candidats. Horizon : dépend du rythme éditorial.*

| # | Lot | Contenu | État |
|---|---|---|---|
| 3.1 | **Contenu en masse** | Les 1 168 questions DIDACTIQUE/SAVOIRS qualifiées et transférées ; retranscription des 2 sujets manquants et des 5 illisibles ; publication par la chaîne éditoriale | Dépend de 2.2 + 2.8 (les arbres + le rythme experts) |
| 3.2 | **Lot 8 — fiches et registre des paramètres** | Valider F01–F07/F09 (leurs « À trancher »), **registre des paramètres pédagogiques** (ADR-0032 implémenté : paliers mémoire, seuils d'évidence, facteurs d'ordonnance, bornes — journal, effet en avant) ; DET-89 (calibrer 40/35/120 sur l'usage réel) ; DET-33 (fuseau par candidat) | À faire |
| 3.3 | **Lot 7 — l'éditorial des questions** | Écran de saisie 4/5 options (A-09), 9ᵉ code de cause (Q-01), difficulté déclarée dans la chaîne | À faire |
| 3.4 | **Mission infra** | Attribuer et valider les 4 diffs TLS/SMTP non commités, `.env` de validation, recette Caddy/Compose, déploiement de la version courante en préproduction | À faire — **aucun agent n'y touche sans mission dédiée** |
| 3.5 | **Reliquat AUDIT-T5** | SEC-3 (chaîne IP), SEC-4 (en-têtes, MFA admin), SEC-2 (réacceptation légale), FRONT-2/FRONT-4, ABO-4 (politique coupon multi-usages), DATA-2/3 — **statuts à réauditer**, certains peut-être déjà soldés | Audit de reprise à faire |
| 3.6 | **SOCLE-1** | Nuxt 4 (fin de vie du 3 dépassée), Python du collecteur | À faire après premier déploiement |
| 3.7 | **Exploitation (SEC-5)** | Sauvegarde, restauration, supervision, reprise | À faire avant ouverture |
| 3.8 | **Juridique définitif** | CGU/confidentialité validées juriste, FR/AR ; DET-07/08 closes | Bloque l'ouverture, pas la préprod |

**Décisions propriétaire :** date d'ouverture · politique ABO-4 · MFA du
back-office · fournisseur d'e-mail définitif (délivrabilité SPF/DKIM).

---

## JALON 4 — « Les créneaux suivants » et les différés

| Lot | Contenu | Condition de réouverture |
|---|---|---|
| **Lot 10 — pilote lycée interne** | Catalogue lycée réel (catégorie créée à l'écran — S-11/S-12 déjà en tests), taxonomie Matière/Chapitre via TAXO, trois familles d'offres | TAXO livré ; contenu lycée |
| **Lot 9 — fonctions absentes** | F08 (indice de préparation), F10 (atlas des pièges), F11b, F12, F13, F14 — **une fiche validée chacune avant une ligne de code** | Fiches |
| **Lot 11 — certification** | Q-11/Q-12, règle métier, preuve, ouverture de `certification.take` à la commercialisation | Arbitrage produit |
| **Lot 12 — conformité avancée** | Q-04/DET-04 (rétention), export, anonymisation, consentements complets | Arbitrages CNDP |
| **Lot 13 — CMI** | Paiement réel, facturation, remboursement, récurrence ; DET-90 (version_uuid obligatoire) se solde ici au plus tard | Démarches CMI |
| **B2B / organismes** | Le socle multi-tenant attend (DET-23–26) | Un contrat en vue |

---

## La lecture d'ensemble

Le goulot du projet n'est plus le code — la machine de développement produit un
lot accepté toutes les deux heures. Le goulot est désormais, dans l'ordre :
**vos décisions** (D-1, Q-09/Q-10, allumage, panel experts), **le juridique
minimal** du test externe, puis **le rythme éditorial** des experts sur le
corpus. Tout le backlog technique restant des jalons 1 et 2 tient en quelques
missions du même format que cette nuit ; les jalons 3 et 4 se déroulent derrière
le contenu, pas devant.

*Chaque ligne « À faire » deviendra une mission M-xxx du protocole
d'orchestration, avec revue et verdict. Ce document remplace, comme ordre
d'exécution, le §6 du cadrage consolidé v1.2 et le backlog du cahier v1.1.*
