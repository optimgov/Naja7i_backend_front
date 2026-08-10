# ADR-0024 — Garder les nœuds, pas seulement les arêtes

**Statut :** accepté · 9 août 2026
**Contexte :** revue PAS-13. Deux blocants, tous deux fondés.

---

## La même erreur, un cran plus haut

L'ADR-0022 énonçait : *une garde placée sur une transition doit être doublée
d'une garde sur l'état*. Je l'ai appliquée aux tables pivot — `memberships`,
`permission_role` — et pas aux tables qu'elles référencent.

Résultat : les **arêtes** du graphe d'autorisation étaient gardées, les
**nœuds** ne l'étaient pas.

```
Le rôle « candidat » est attribué dans dix organismes.        ← arête gardée
On le passe ensuite en is_staff, ou on lui donne un tenant_id. ← nœud libre
Aucune ligne de pivot n'est écrite : aucun trigger ne s'exécute.
```

M�me mécanique pour `permissions.platform_only` : la passer à vrai après
l'attachement n'écrit rien dans `permission_role`, donc ne déclenche rien.

## Règle

**Quand un invariant relie deux tables par un pivot, il faut trois gardes :
sur le pivot, et sur chacune des deux tables référencées.**

Garder le pivot seul revient à verrouiller la porte en laissant les murs
mobiles. C'est la troisième variante du même défaut en trois lots — chemin
oublié, ordre d'exécution, puis mutation d'un attribut référencé — et il vaut
mieux l'écrire comme une règle générale que de la redécouvrir une quatrième
fois.

## Décisions

### 1. La portée d'un rôle distribué est immuable

`roles.tenant_id` ne change plus dès qu'une appartenance existe. Déplacer un
rôle d'un organisme à un autre transférerait silencieusement ses utilisateurs :
c'est une opération qui doit passer par la création d'un autre rôle, visible et
tracée.

### 2. Un rôle distribué hors plateforme ne devient pas back-office

`is_staff` ne peut pas passer à vrai sur un rôle global déjà attribué dans un
organisme. En revanche, un rôle attribué uniquement sur la plateforme le peut :
c'est le cas légitime, et il reste ouvert.

### 3. Un rôle d'organisme portant une permission réservée ne devient pas global

Cas croisé, moins évident : rendre global un rôle d'organisme lui permettrait
d'être attribué partout, avec les permissions réservées qu'il porte déjà.

### 4. Une permission ne devient réservée que si personne ne l'a hors plateforme

`platform_only` ne passe à vrai que si aucun rôle d'organisme ne la porte et
qu'aucune appartenance hors plateforme n'en bénéficie.

**Le sens inverse reste toujours permis** : lever une réservation ne peut que
restreindre l'accès, jamais l'élargir. Une garde qui interdirait les deux sens
serait une gêne sans contrepartie.

### 5. Les gardes de nœud se sérialisent comme celles de pivot

Elles verrouillent les appartenances et les rôles concernés, dans l'ordre des
identifiants — même ordre que les triggers de pivot, pour qu'aucun interblocage
ne remplace la sérialisation.

Les deux tests de concurrence emploient `FOR NO KEY UPDATE` sur la seconde
connexion : ce mode entre en conflit avec le `FOR UPDATE` réclamé par la garde,
tout en restant compatible avec le `FOR KEY SHARE` qu'une clé étrangère
prendrait de toute façon. Sans cette précaution, le test attendrait pour la
mauvaise raison — c'est la leçon du PAS-13, et elle s'applique ici telle quelle.

## Récapitulatif des gardes du graphe d'autorisation

| Table | Écriture gardée | Depuis |
|---|---|---|
| `memberships` | Attribution d'un rôle hors de sa portée | PAS-11 |
| `permission_role` | Attachement d'une permission réservée | PAS-9, renforcé PAS-12 |
| `roles` | Mutation de `tenant_id` ou `is_staff` | **PAS-14** |
| `permissions` | Passage à `platform_only` | **PAS-14** |

Le graphe est désormais gardé sur ses quatre tables. Toute nouvelle table
participant à l'autorisation devra l'être aussi — la règle du §« Règle »
s'applique par construction.

## Sur le dispositif

Cinq revues, vingt-trois constats, vingt-deux fondés. Le motif se répète avec
une régularité qui mérite d'être nommée : **je corrige le cas signalé, l'audit
cherche la variante suivante du même défaut.**

C'est pourquoi cet ADR énonce une règle générale plutôt qu'un correctif. Si la
prochaine revue trouve une cinquième variante, ce sera le signe que la règle
elle-même est mal formulée — pas qu'il manquait un trigger.
