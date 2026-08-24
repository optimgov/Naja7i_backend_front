# ADR-0036 — Le gratuit v1.1 est un diagnostic de dix questions

**Statut :** accepté · décision propriétaire du 24 août 2026
**Précise :** ADR-0027 et ADR-0033
**Dépend de :** ADR-0025, ADR-0029

## Décision

Le gratuit ouvre uniquement `questions.answer` et son enveloppe vaut exactement
dix questions. Il ne porte ni entraînement ciblé, ni simulation complète, ni
maîtrise détaillée, ni remédiation, ni séance mémoire. La valeur pourra devenir
paramétrable plus tard ; la v1.1 la fixe à dix.

Le profil `decouverte-v11-10` porte `value = min_value = 10` et conserve la
borne haute du registre à `120`. L'ancien profil `decouverte` à quarante reste
actif : des versions historiques le référencent sur une base peuplée. Le
désactiver ne modifierait pas ces versions, mais retirerait sans nécessité un
profil encore requis pour relire et éprouver l'historique.

## Passage d'une base peuplée

La migration `000830` ajoute le profil et ne change ni offre, ni version, ni
octroi. Le changement commercial est un geste d'allumage explicite :

```text
php artisan naja7i:activer-diagnostic-gratuit-v1-1 --dry-run
php artisan naja7i:activer-diagnostic-gratuit-v1-1
```

La commande vérifie le contrat exact du profil et l'unique offre gratuite,
puis modifie `Plan.quota_profile_id` par le modèle métier. Ce geste compose une
nouvelle `PlanVersion`. Il ne met à jour aucune ancienne version et aucune
ligne `access_grants`.

Le rejeu rend `resultat=deja_active` et ne compose pas une version
supplémentaire. Il ne faut pas rejouer `PlansSeeder` sur une base peuplée : ce
seeder rétablit toutes les offres du dépôt, alors que la commande ne vise que le
diagnostic gratuit.

Après la bascule, les nouvelles inscriptions reçoivent dix par la chaîne
normale `OffreGratuiteService`. Le rattrapage tardif utilise la même version
courante :

```text
php artisan naja7i:rattraper-le-gratuit --dry-run
php artisan naja7i:rattraper-le-gratuit
```

Sa garde recherche toutes les versions de l'offre. Un compte possédant déjà un
octroi à quarante est donc classé `deja_porteurs` : son grant, ses consommations
et son reliquat dérivé restent inchangés.

## Installation fraîche et retour arrière

`PlansSeeder` sélectionne directement `decouverte-v11-10` : la première version
de l'offre gratuite vaut dix sur une installation fraîche.

Le rollback de `000830` ne supprime pas le profil. Le registre interdit les
suppression définitives et une version peut déjà le référencer. Revenir au
profil précédent serait une nouvelle composition explicite, jamais une
réécriture ou une suppression d'historique.
