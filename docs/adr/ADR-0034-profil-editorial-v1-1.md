# ADR-0034 — Unifier le poste éditorial sans effacer l'histoire

**Statut :** accepté · décision propriétaire du 24 août 2026
**Supersède :** ADR-0015 §6 et les mentions des « quatre yeux » de l'ADR-0032
**Dépend de :** ADR-0009, ADR-0015, ADR-0023, ADR-0032

## Contexte

Les profils `auteur`, `reviseur` et `editeur` découpaient une chaîne éditoriale
qui imposait aussi des personnes distinctes. Le modèle v1.1 retient quatre
profils de personnel : `expert_pedagogue`, `finance`, `support` et
`super_admin`.

## Décision

`expert_pedagogue` reçoit une allowlist fermée :
`questions.view/create/review/validate/publish/retire/difficulty`,
`catalogue.view/manage` et `taxonomy.manage`. Il peut accomplir seul toutes les
transitions jusqu'à la publication. Les états et les identités `author_id`,
`reviewer_id` et `validator_id` restent obligatoires et conservent la trace de
chaque acte ; seule l'inégalité entre personnes disparaît.

Les appartenances aux trois anciens rôles sont recopiées sans doublon vers le
nouveau profil. Les lignes historiques restent en base, mais leurs rôles sont
inactifs, inattribuables et ignorés par la résolution des permissions.

`super_admin` reçoit toutes les permissions existantes. Son code reste aussi
une règle d'anti-délégation : seul un compte déjà `super_admin` peut attribuer
ce rôle, même si un autre compte cumule les mêmes permissions.

Le rôle `support` conserve temporairement ses droits historiques. Il n'est pas
encore conforme au profil cible : les permissions et surfaces de réclamations
n'existent pas dans ce lot. Son resserrement devra être livré atomiquement avec
le lot réclamations/messagerie, afin de ne pas fermer l'assistance existante
avant son remplacement.

## Conservation et retrait

Une question n'est jamais supprimée définitivement. Le retrait est logique et
la base refuse tout `DELETE` sur `questions`. La commande historique
`naja7i:retirer-les-questions-importees` est supersédée : `import_ref` est
unique même pour une question retirée et les questions retirées sont gelées.
Prétendre réimporter « de zéro » sans hard delete exigerait donc d'altérer une
identité historique ; la commande refuse et ne mute rien.

## Retour arrière

Le rollback normal de la migration restaure la fonction de publication avec
séparation auteur/valideur, retire le déclencheur d'interdiction du `DELETE`,
réactive les trois anciens rôles, retire le rôle `expert_pedagogue` introduit
par ce pas et enlève `roles.is_active`. Les appartenances historiques aux
anciens rôles n'ayant jamais été effacées, elles redeviennent opérantes.

Ce retour arrière n'est sûr que dans la chaîne de migration normale : avant ce
pas, `expert_pedagogue` n'existe pas. Une création manuelle ou un déploiement
partiel de ce rôle hors migration doit être résolu explicitement avant tout
rollback ; la migration ne prétend pas distinguer des données hors contrat.
