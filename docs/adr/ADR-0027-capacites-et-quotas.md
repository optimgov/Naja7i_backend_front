# ADR-0027 — Capacités, quotas et consommation

**Statut :** proposé révisé · intégré au lot 0A autorisé le 21 août 2026
**Dépend de :** ADR-0025, ADR-0026

**Arbitrages Q-07 et Q-08 : tranchés le 21 août 2026.** Le quota gratuit est
cumulatif sans remise à zéro automatique et se débite au premier service
idempotent d'un item, sauf exemptions métier explicitement décidées ci-dessous.

## Décision de structure

Les capacités restent binaires et fermées en code. Les quotas sont des objets distincts, typés et bornés : unité, valeur, fenêtre et portée. Une unité inconnue ou une valeur hors bornes est refusée. Il n'existe ni JSON libre, ni valeur sentinelle négative pour « illimité ».

Le quota de questions est **cumulatif sur la durée du droit**. Un renouvellement crée une nouvelle enveloppe. Un droit sans terme ne se remet pas automatiquement à zéro.

**Amendement du 22 août 2026 (ADR-0033).** L'enveloppe d'ESSAI ne se compose avec aucune enveloppe payante, et ne lui survit pas : la première activation d'un forfait payant clôt l'essai, dont le reliquat n'est **ni transféré, ni additionné, ni retrouvé** à l'expiration du forfait. Il n'y a donc jamais deux enveloppes à départager pour une même capacité, et la consommation (3B) n'a pas à choisir laquelle débiter. La composition entre enveloppes PAYANTES successives, elle, reste celle décrite ici.

La valeur de départ recommandée pour l'offre gratuite est **40 questions**.
C'est un paramètre administrable à calibrer selon la taille réelle de la banque
et les données d'usage, notamment après les premières inscriptions. Ce nombre
n'est ni une constante de code, ni une règle métier figée, ni un minimum garanti.

Le quota F03 actuellement en vigueur est un compteur global par compte de causes révélées, cumulatif à vie. Il reste distinct du quota de questions ; ce lot ne change ni sa fiche validée, ni son comportement.

## Consommation idempotente

Une unité de question est consommée lorsque le serveur met **pour la première fois** l'item à disposition du candidat. L'opération atomique est identifiée par `(utilisateur, tentative, item)` et comporte dans la même transaction :

1. verrouillage ou primitive atomique de l'enveloppe applicable ;
2. vérification de la capacité, de la portée et du reliquat ;
3. création unique de la trace de service ;
4. décrément conditionnel du reliquat ;
5. émission de l'item seulement après succès.

Rechargement, reprise multi-appareil, rejeu hors ligne, double clic, réponse et soumission ne recomptent pas. Deux requêtes concurrentes pour le même triplet rendent la même trace. Une série ne compose et ne sert jamais plus d'items que le reliquat réservé.

## Coût annoncé et mur de droits

Le coût s'annonce **avant la composition** : une série de N questions affiche le débit prévu avant son ouverture, y compris lorsqu'un préchargement hors ligne est proposé. Le serveur ne compose ensuite jamais plus que le reliquat disponible. Si le reliquat ne permet pas N, il annonce la taille effectivement possible avant toute émission d'item ; il ne promet pas une série qu'il tronquera silencieusement.

Un quota atteint ne produit jamais un bouton grisé. Le candidat lit ce qui lui reste, pourquoi l'action n'est pas proposée et comment augmenter son accès. Le mur payant reste un champ : soit une action réelle est rendue, soit elle est absente et remplacée par une porte explicite.

## Matrice des chemins obligatoire

Chaque chemin doit répondre explicitement à la question : **« Ce premier service d'item consomme-t-il le quota de questions, sur quelle enveloppe et avec quelle clé idempotente ? »** Aucun chemin ne peut hériter d'une réponse implicite.

La matrice normative couvre diagnostic, entraînement, simulation, miroir,
mémoire, démonstration publique et reprise :

| Chemin | Débite le quota général ? | Règle |
|---|---:|---|
| Démonstration publique | Non | Aucun compte ni enveloppe. |
| Diagnostic | Oui | Premier service idempotent de chaque item. |
| Entraînement ciblé | Oui | Premier service idempotent de chaque item. |
| Examen blanc | Oui | Premier service idempotent de chaque item. |
| F05 miroir | **Non** | Exemption OptimGov, avec borne propre et protections anti-aspiration. |
| F07 mémoire | **Non** | Exemption OptimGov, limitée aux erreurs causées du compte et bornée par séance. |
| Reprise d'une tentative | Non | Les items ont déjà été servis. |
| Consultation d'une correction | Non | Aucun nouvel item n'est livré. |

Par décision OptimGov du 21 août 2026, F05 miroir et F07 mémoire ne débitent pas le quota général de questions. Cette exemption ne crée pas un accès libre à la banque :

- F05 est bornée côté serveur par compte et par couple `(compétence, cause)`, ne ressert jamais le même énoncé et ne permet aucune énumération des sœurs ;
- F07 ne peut servir un nouvel énoncé qu'à partir d'une erreur causée appartenant au compte, conserve le plafond existant de vingt rendez-vous par séance et trace chaque item servi ;
- les valeurs chiffrées complémentaires et limites de débit sont spécifiées avant l'implémentation des chemins concernés.

## Protection du contenu

- Le serveur réserve le quota avant de composer une série et plafonne la composition au reliquat.
- Aucun endpoint de prévisualisation, reprise ou correction ne permet de récupérer de nouveaux énoncés hors trace de service.
- La correction ne livre que les items déjà servis et seulement après soumission selon les règles F04.
- Les identifiants ne permettent pas l'énumération de la banque ; les réponses sont en liste blanche.
- Les échecs, répétitions et rythmes anormaux sont auditables et soumis à une limitation de débit.
- Une réservation abandonnée ne donne jamais accès à un énoncé non compté ; la politique de libération ne peut concerner qu'une unité non servie.

## Capacités commercialisables

La liste vendable est fermée en code. `CERTIFICATION` reste exclue tant que sa fonction n'est pas livrée. Les capacités fines nécessaires aux paliers couvrent annales/séries, carte de maîtrise, ordonnance et rendez-vous mémoire ; leur présentation peut être regroupée sous « coaching automatique ».

## Tests minimaux

- même `(utilisateur, tentative, item)` rejoué : une consommation et un même item ;
- deux appareils concurrents : aucune consommation double ;
- reliquat de 3 : aucune série de 4 items servie ;
- avant composition, une série annonce son débit et sa taille compatible avec le reliquat ;
- quota épuisé : aucun nouvel énoncé divulgué ;
- quota épuisé : aucun bouton grisé ; reliquat et porte d'augmentation visibles ;
- démonstration publique : zéro consommation ;
- F05 miroir et F07 mémoire : zéro débit du quota général, sans accès libre à la banque ;
- F03 : comportement inchangé et compteur distinct.

### Tests de mutation

- On retire la validation des bornes : la saisie d'un quota hors bornes est acceptée et le test dédié rougit.
- On compose avant d'annoncer/réserver le coût : le test « coût annoncé avant composition » rougit.
- On rend une action désactivée quand le quota est épuisé : le test du mur de droits rougit.

## Spécifications encore requises

Les principes O-1/O-2 sont fermés. Restent à fixer avant implémentation : le plafond F05 par compte/couple et sa fenêtre, les limites de débit F05/F07, et l'éventuel plafond de nouveaux énoncés sœurs F07. Ces valeurs ne peuvent pas supprimer les conditions d'origine, d'idempotence et de non-énumération.
