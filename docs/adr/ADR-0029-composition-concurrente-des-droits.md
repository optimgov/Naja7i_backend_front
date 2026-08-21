# ADR-0029 — Composition concurrente des droits

**Statut :** proposé · intégré au lot 0A autorisé le 21 août 2026
**Dépend de :** ADR-0025 à ADR-0028

## Constat vérifié

Le fait V-1 a été vérifié le 21 août 2026 dans `app/Services/AbonnementService.php`, `departDe()` vers les lignes 216–230 : le chaînage ABO-2 est corrigé pour les droits datés, et un nouvel octroi commence après la fin la plus tardive des droits datés actifs de même capacité. Ce comportement doit être conservé par test de non-régression.

Réserve V-2, dans la première branche de la même fonction : `if ($courants->contains(fn ($o) => $o->ends_at === null)) return $maintenant;`. Dès qu'un droit sans terme existe, cette branche fait démarrer chaque achat daté suivant maintenant ; un second pack daté chevauche alors le premier au lieu de le prolonger. Ce défaut est latent tant qu'aucun sans-terme n'est posé et devient certain avec l'offre gratuite auto-attribuée. Il constitue une dette à corriger au lot 3B.

Cet ADR formalise l'existant et traite uniquement les compositions encore sensibles : concurrence, droit sans terme avec droit daté, gratuit avec payant, capacités inclusives et portées.

## Invariants

1. Deux décisions concurrentes ne calculent jamais la même date de départ pour une même capacité et une même portée.
2. Un droit sans terme n'est jamais raccourci, masqué ou remplacé par un droit daté.
3. Un droit payant n'est jamais réduit par l'attribution ou la migration d'un droit gratuit.
4. Une offre supérieure ajoute ses capacités et prolonge les capacités communes sans retirer le reliquat antérieur.
5. Les capacités différentes coexistent.
6. Une capacité large ne retire pas une capacité fine ; l'autorisation est l'union des droits actifs, avec une règle d'inclusion fermée en code.
7. Deux portées ne fusionnent que si la relation d'inclusion est définie ; sinon elles coexistent séparément.

## Algorithme transactionnel

Pour chaque capacité atomique et portée normalisée de la version à honorer :

1. ouvrir une transaction et verrouiller une clé stable `(compte, capacité, portée-normalisée)` ;
2. relire tous les droits actifs et futurs qui se composent avec cette clé ;
3. calculer le départ du nouvel octroi daté comme le maximum entre maintenant et la fin la plus tardive des seuls droits datés actifs de même capacité et portée ; ignorer les droits sans terme dans ce calcul ;
4. conserver par ailleurs l'autorisation sans terme et enregistrer chaque origine sans créer de fin réductrice ;
5. créer l'octroi et son lien d'origine de façon unique ;
6. recommencer dans un ordre canonique des clés pour éviter les interblocages.

L'idempotence porte aussi sur l'origine : la même décision rejouée ne crée pas un second octroi.

## Règles de composition

| Situation | Résultat |
|---|---|
| Même capacité, même portée, octrois séquentiels | Prolongation selon `departDe` — existant à préserver. |
| Même capacité, même portée, octrois concurrents | Sérialisation sous verrou ; le second part après la fin réservée par le premier. |
| Capacités différentes | Coexistence, sans date commune artificielle. |
| Droit sans terme + premier droit daté | Le daté démarre immédiatement ; le sans-terme reste effectif et ne bloque pas. |
| Droit sans terme + plusieurs droits datés | Les datés se chaînent entre eux ; le sans-terme est ignoré dans leur calcul de départ. |
| Gratuit + payant, capacité commune | Le payant conserve ou prolonge le meilleur droit ; le gratuit ne le réduit pas. |
| Gratuit + payant, capacités différentes | Union des capacités actives. |
| Capacité inclusive + capacité incluse | Union effective ; aucune suppression physique du droit fin. |
| Même capacité, portées disjointes | Coexistence indépendante. |
| Portée large + portée incluse | La large autorise l'ensemble ; la fine reste traçable et reprend effet si la large expire avant elle. |

## Capacités inclusives et portées

La relation d'inclusion est déclarée dans une table fermée en code, acyclique et testée. Elle n'est pas déduite d'un nom commercial tel que « coaching complet ». Les portées sont normalisées selon une hiérarchie explicite (catalogue, épreuve, matière, chapitre). Une portée large couvre ses descendantes mais ne modifie pas leurs dates ni leur origine.

## Composition des enveloppes

Trois règles gouvernent les quotas de droits couvrants :

1. **Le sans-terme ne bloque ni ne court-circuite.** Le départ d'un nouvel octroi daté est `max(maintenant, fin la plus tardive des droits datés actifs de même capacité et portée)`. Les droits sans terme sont ignorés pour ce calcul : le premier daté démarre immédiatement, les suivants se chaînent entre eux.
2. **L'illimité gagne, les enveloppes ne s'additionnent pas.** À l'instant de la consommation, si un droit actif couvrant ne porte aucun quota pour l'unité, la consommation est libre. Sinon, une seule enveloppe est débitée : celle du droit couvrant qui expire le plus tôt.
3. **Les reliquats survivent.** Une enveloppe non gouvernante ne se débite pas. Elle reprend avec le même reliquat quand le droit plus permissif expire ; elle n'est ni remise à sa valeur initiale, ni vidée par la période illimitée.

La sélection de l'enveloppe et son débit sont atomiques sous le même verrou que la consommation idempotente d'ADR-0027.

## Tests minimaux

- test de non-régression de `departDe` sur deux octrois séquentiels ;
- deux validations concurrentes de 30 jours : 60 jours réservés sans chevauchement ;
- sans-terme actif + premier achat 30 jours : départ immédiat ; second achat 30 jours : départ à la fin du premier daté ;
- illimité puis daté, et daté puis illimité : droit effectif illimité sans casser le chaînage des datés ;
- gratuit 20 questions avec reliquat 7 + pack illimité 30 jours : consommation libre pendant 30 jours, puis reprise au reliquat 7 ;
- deux enveloppes chiffrées actives : débit de celle qui expire le plus tôt ;
- gratuit après payant : aucune réduction ;
- capacité large expirée avant une fine : la fine demeure active ;
- portées disjointes : aucune prolongation croisée ;
- même décision rejouée : un seul octroi.

### Tests de mutation

- On restaure la branche V-2 `contains(null) → maintenant` : le test sans-terme + deux achats datés rougit.
- On additionne deux enveloppes couvrantes au lieu de choisir la plus proche de l'expiration : le test des deux enveloppes rougit.
- On débite ou réinitialise le reliquat non gouvernant pendant l'illimité : le test de reprise à 7 rougit.

## Arbitrages encore ouverts

- Table exacte des inclusions entre capacités commercialisables.
- Hiérarchie exacte et règles de chevauchement des portées pédagogiques.
- Représentation d'une origine supplémentaire lorsqu'un droit sans terme rend inutile un nouvel intervalle daté.
