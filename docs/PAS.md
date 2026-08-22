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
| — | SHA du PAS-24 au journal et au backlog, DET-43 | `405e593` |
| 25 | Mesure de DET-43 : la lenteur est de l'attente d'hôte, jamais du travail — clos en « environnement », la médiane sur trois devient la méthode | `51159d9` |
| — | Correction du SHA du PAS-25, puis AGENTS.md en lien et la durée renvoyée à la méthode | `11ef3f6`, `976e0bf`, `9833b6c` |
| 26 | F05 — la question miroir : sélecteur partagé, la correction n'annonce que l'existence, et l'unité de quota passe au couple | `c15e032` |
| — | SHA du PAS-26 au journal et au backlog, DET-45 | `b0a230b` |
| 27 | La chaîne éditoriale par l'API : rédiger, amender, lister, file de relecture — aucune règle métier neuve, et DET-46 signalé | `079b283` |
| — | SHA du PAS-27 au journal et au backlog, DET-46, import inscrit au §4 | `1cc6082` |
| 28 | Audit tournée 2 : la cause du miroir verrouillée, l'acquisition d'une cause matérialisée et atomique, l'empreinte revalidée après collision ; DET-46 tranché, portée de la règle 404/403 écrite | `b884280` |
| — | SHA du PAS-28 au journal et au backlog, DET-46 clos, DET-47 ; « À venir » remis à jour | `c80e595`, `88efe2e` |
| 29 | Une source modifiée cesse d'être vérifiée : invalidation et rétrogradation des citations en base — mesure d'attente, DET-47 reste ouverte pour le versionnement | `0de348d` |
| — | SHA du PAS-29 au journal et au backlog, DET-47 endiguée mais ouverte | `64e0d51` |
| 30 | DET-45 tranché : le miroir désigné l'emporte sur le couple, avec repli quand la désignation n'est pas servable | `88fbcf5` |
| — | SHA du PAS-30 au journal et au backlog, DET-45 clos, DET-48 | `73a1c39` |
| A4a | Back-office éditorial, première moitié : le panneau, la rédaction, la relecture — Filament ne porte aucune règle métier, l'écriture est détournée vers les services | `3ab2355` |
| — | SHA de l'A4a au journal et au backlog, DET-48 arbitrée, question d'infrastructure `/admin` | `3447920` |
| A4b | Back-office éditorial, seconde moitié : le registre des sources signé et daté, l'invalidation visible sans rechargement, et la couverture en page d'accueil — DET-41 close | `2db4f6c` |
| — | SHA de l'A4b au journal et au backlog, DET-41 close, DET-49 | `fea7762` |
| 31 | DET-48 : le miroir désigné sort du gel du contenu publié — le pointeur est de l'usage, et rien d'autre ne le suit | `49f8b4b` |
| — | SHA du PAS-31 au journal et au backlog, DET-48 close | `8a8c086` |
| 32 | Le profil candidat : l'épreuve préparée se déclare, la déduction est remplacée et non doublée — DET-42 close | `64df5af` |
| — | SHA du PAS-32 au journal et au backlog, DET-42 close | `dc69ed1` |
| 33 | Les trois transitions manquantes de la chaîne éditoriale passent par l'API, chacune sous sa permission — le semis de recette quitte tinker ; DET-50 signalée | `1a198f3` |
| — | SHA du PAS-33 au journal et au backlog, DET-50 | `f753665` |
| 34 | Les limiteurs de débit prennent un nom : un seau par geste au lieu d'un seau par IP, et un profil de recette qui ne touche ni la file d'envoi ni la sécurité | `c6fe6ca` |
| — | SHA du PAS-34 au journal et au backlog, DET-51 | `fc05630` |
| 35 | L'examen blanc : composition aux poids officiels, chronomètre dur pris sur la durée officielle, clôture serveur, et une note pondérée qui n'invente aucun barème | `b2fc0e0` |
| — | SHA du PAS-35 au journal et au backlog, DET-52 et DET-53 | `1d0e915` |
| — | DET-54 signalée : les citations officielles n'existent qu'en français | `3eee115` |
| 36 | DET-54 close : les deux citations officielles gagnent leur colonne arabe, servies par `localized()` avec repli français | `1308240` |
| — | SHA du PAS-36 au journal, DET-54 close | `2f05d1c` |
| — | Audit tournée 3 : les trois bloquants backend (F03 sans réponse, miroir hors cause, amendements partiels) | `c9b5ab2` |
| 37 | Lot ABO — le chemin de revenu : offre, commande, coupon, adaptateur de paiement, et l'octroi comme conséquence | `e821f62` |
| — | Corpus CRMEF du 15 août : l'extraction des 33 fichiers entre au dépôt, seule et avant tout usage | `62fbd17` |
| — | CRMEF-2 phase 1 : le rapprochement du corpus et de la base — voies, coefficients, les 40/30/30, six dettes | `d027f20` |
| 38 | CRMEF-2 phase 2 : les trois faits imprimés — cinq options, pénalité négative, numérotation réelle | `11116a3` |
| 39 | CRMEF-2 phase 3 : 53 annales en file éditoriale, sans corrigé et sans pouvoir en sortir | `887aa83` |
| — | Lot PORTES phase 2, côté serveur : la question sautée cesse d'être une erreur démontrée, le tableau de bord ouvre là où il y a du travail, la fuite de traduction est fermée sur toute sa famille, et le refus nomme ce qu'il refuse — DET-78, DET-79, DET-80 signalées | `521cda9` |
| 0A | Gouvernance préalable à l'évolution commerciale : vocabulaire, versionnement des offres, séparation capacités/quotas, demande d'accès non financière, composition des droits et fiches F01/F02/F04/F05/F06/F07/F09 | `5e65d0d` |
| 1A | Administration des personnes dans Filament : consultation, création de personnel, coordonnées et rôles sous permissions existantes, sans auto-escalade ni suppression | `821e732` |
| 1A.1 | Correctifs de sécurité de l'annuaire : délégation bornée aux permissions de l'acteur, super_admin protégé, suspension effective dans Filament, dernière appartenance conservée et politique de mot de passe centralisée | `3feb1bd` |
| 0A.2 | Arbitrages commerciaux Q-07/Q-08/Q-13 : quota gratuit cumulatif, consommation au premier service idempotent, versionnement automatique, exemptions F05/F07 préservées | `3eeff4a` |
| 1A.2 | L'annuaire ne propose plus dans Filament les rôles que l'acteur ne peut pas réellement attribuer ; super_admin reste réservé aux super-administrateurs | `4015a44` |
| 2A | Mon dossier backend et invitation du personnel : coordonnées et mot de passe du propre compte, lien opaque hashé, unique et expirant, aucun mot de passe transmis par l'administration | `21fc459` |
| 2C | Mon dossier personnel dans Filament : coordonnées et mot de passe par le service commun, vérifications, état et rôles en lecture seule | `b537d00` |
| 2.1 | Correctif Mon dossier : réauthentification avant changement d'e-mail et parcours candidat réellement modifiable | backend `d719f75` · frontend `76e0325` |
| Q0/Q1 | Corpus QCM : projection et validation sans écriture, conservation des métadonnées d'import, refus des réponses inventées et rapport strict d'anomalies | `0f01fa6` |
| Q1.1 | La difficulté arbitraire reste provisoire, l'échelle déclarée couvre cinq crans et les métadonnées d'import restent hors sérialisation | `af2ae76` |
| 3A.1 | V-2 : un droit sans terme reste effectif sans interrompre le chaînage des achats datés successifs | `117339b` |
| 0A.3 | Registre normatif des neuf capacités atomiques et règle de contenance des portées typées ; contradiction de la capacité agrégée close | `16c0ede` |
| Q2.1 | Zone de préparation pédagogique distincte de la banque : retranscription versionnée, doublons bloqués, décisions humaines tracées et aucun transfert réel | `1b7f171` |
| 3A.2 | Portées typées des droits et résolution par contenance : racine, filière, famille, épreuve et nœuds de compétence | `1a425c7` |
| 3A.3 | Registre exécutable de neuf capacités, huit commercialisables, certification fermée et référentiel bilingue | `af66ff0` |
| 3A.3c | Correctif d'architecture : référentiel par Eloquent et seule lecture bas niveau des portées explicitement justifiée | `cfc3d93` |
| — | Outillage : 256 Mio pour le processus PHPUnit ; suite complète verte à 719 tests et 2 547 assertions | `9a9c2bd` |
| 3A.4 | Versions d'offre immuables : toute modification contractuelle crée une version, la commande fige celle affichée et l'honoration ne relit plus le plan courant | backend `2e0606b` · frontend `b9e9f4c` |
| 3A.5 | Profils de quota pédagogiques : unité et fenêtre fermées en code, bornes justifiées tenues en base, déplacement d'une borne refusé sans raison nouvelle, registre réservé à `quotas.manage` | `85a7634` |
| P-Q | La version d'offre copie le profil de quota à la composition — code, unité, fenêtre, valeur, bornes et justifications ; amender le profil ne touche plus aucune version vendue, et l'honoration ouvre l'enveloppe figée | `1dddf83` |
| P-E | L'immuabilité des versions devient différenciée : les quatre textes se corrigent par `corriger_version_editoriale()`, qui journalise dans la même transaction sous `plans.editorial_fix` ; tout le reste, et le DELETE, lèvent toujours | `d804621` |
| P-V | Constat en lecture seule du frontend `e89e3a9` : le paiement simulé émet `version_uuid`, la saisie de coupon non — le repli « version courante » reste donc en place et part en dette DET-90, échéance avant le lot 4 | `e58391f` |
| P-D | Reliquats documentaires : ADR-0032 ferme Q-06 (frontière du paramétrable et règle du badge), `IMPORT-QCM-SUPERSESSION.md` repositionne le schéma d'import et consigne les cinq contrôles sans équivalent, DET-64 porte l'arbitrage D-2 coefficient 5 réversible sur pièce | `f66dfb5` |
| 3A.6.1 | La catégorie de public devient un objet du catalogue : code figé, libellé FR/AR, création à l'écran sous `orders.validate`, rattachement depuis une famille d'épreuves et ascendance des droits d'audience — DET-87 close | `52ea8f4` |
| 3A.6.2 | La table d'affichage des capacités s'édite sans développeur — libellés et descriptions FR/AR, ordre — pendant que la liste des neuf codes reste fermée : ni création, ni suppression, et enregistrer retire le badge « à relire » (ADR-0032) | `5ec4499` |
| 3A.6.3 | Le formulaire des offres compose tout ce que la matrice §5 autorise — public, textes FR/AR, note interne, prix et devise fermée, calendrier, capacités, profil de quota sélectionné, portée typée — et chaque interdit a son refus serveur | `656ddd9` |
| 3A.6.4 | L'historique des versions se lit sur la fiche de l'offre — numéro, date d'effet, auteur, champs déclencheurs, commandes, droits issus et droits actifs — et le bouton « corriger une coquille » appelle le canal éditorial sous `plans.editorial_fix` | `921c1ad` |
| 3A.6.6 | Le scénario lycée devient un test : catégorie, pack « Suivi mensuel » 30 j, quatre capacités et portée `(audience, lycee)` créés à l'écran sans migration — avec les tests d'acceptation §6 du lot et leurs mutations | `b1fe327` |

## Lots frontend

| Lot | Contenu | Commit |
|---|---|---|
| FRONT-1 | Socle Nuxt 3 + BFF, six écrans bilingues, recette manuelle | `43a140f` |
| — | Mise à jour de la recette après PAS-3.1 | `d72584c` |

## À venir

Simulateur, module opportunités, commercial et CMI, import de questions en
volume.
Voir `docs/BACKLOG.md`.
