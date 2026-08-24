# ADR-0035 — Une messagerie interne avant de réduire le support

**Statut :** accepté · décision propriétaire du 24 août 2026
**Précise :** ADR-0034, périmètre du rôle support
**Dépend de :** ADR-0002, ADR-0009, ADR-0019

## Contexte

Le candidat doit pouvoir adresser une réclamation à l'équipe sans exposer une
adresse personnelle de membre du personnel. Les experts pédagogues et le
support ont besoin du même fil dans le back-office. Réduire immédiatement les
anciens pouvoirs du support ferait toutefois disparaître des capacités avant
que leur remplacement ait été éprouvé par une recette croisée réelle.

## Décision

La livraison se fait en deux étapes compatibles.

L'étape A, portée par la migration `000820`, ajoute les réclamations, leurs
messages et les permissions réservées à la plateforme `complaints.view` et
`complaints.reply`. Elles sont attribuées à `expert_pedagogue`, `support` et
`super_admin`, jamais à `finance`. Les pouvoirs historiques de `support` ne
sont pas retirés à cette étape : son périmètre reste explicitement provisoire.

Une étape B ultérieure, dans une migration distincte et seulement après recette
croisée candidat/expert/support/super-administrateur/finance, réduira le rôle
support à la lecture et à la réponse des réclamations. Cette migration n'est
pas anticipée dans le présent lot.

## Frontières du domaine

Un candidat vérifié crée, liste, lit et complète uniquement ses propres fils.
Un fil étranger répond 404. L'API ne révèle jamais l'identité, l'e-mail ou le
rôle du membre du personnel : un auteur de message y est seulement
`candidate` ou `staff`.

Les seuls états sont `waiting_staff` et `waiting_candidate`, déduits du dernier
geste. Il n'existe en v1.1 ni clôture, pièce jointe, e-mail, temps réel,
assignation, SLA ni journal d'audit transverse.

Les messages sont immuables et en ajout seul. Les fils ne se suppriment pas ;
seuls leur état et la date du dernier message évoluent. Toutes les écritures,
qu'elles viennent de l'API ou de Filament, traversent `ComplaintService` sous
transaction et verrou.

## Retour arrière

Le rollback de `000820` retire d'abord les déclencheurs, puis les tables et les
deux permissions. Il ne modifie aucun autre pouvoir du support et ne touche ni
au corpus, ni à la taxonomie, ni aux données préparées.
