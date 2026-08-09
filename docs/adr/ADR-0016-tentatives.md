# ADR-0016 — Tentatives : idempotence, horloge serveur, correction différée

**Statut :** accepté · 9 août 2026
**Contexte :** PAS-6. Cadrage §7.3. Fiches F02 et F03.

---

## 1. Chaque réponse est écrite seule, immédiatement

Une réponse n'attend pas la soumission finale. Un candidat en examen blanc dont
le réseau coupe à la question 38 ne perd que celle-là.

Conséquence : la table `responses` a une ligne par item, avec unicité sur
`attempt_item_id`. Aucune écriture en lot, aucun tableau sérialisé.

## 2. Idempotence à deux niveaux

- **Clé fournie par le client** (`idempotency_key`, unique par utilisateur) :
  rejouer la même création retourne la même tentative.
- **Index partiel en base** : une seule tentative de diagnostic en cours par
  candidat et par épreuve. Sans lui, un candidat pourrait ouvrir dix
  diagnostics et ne garder que le meilleur — le diagnostic perdrait tout sens.

Répondre est également rejouable : la même réponse ne crée pas de doublon,
changer d'avis met à jour tant que la tentative est ouverte.

## 3. Le temps appartient au serveur

`answered_at` et `expires_at` viennent du serveur. L'heure déclarée par le
client est conservée dans `client_reported_at` — pour repérer les dérives, et
jamais pour arbitrer. Sans cette séparation, avancer l'horloge de son téléphone
donnerait du temps supplémentaire en simulation.

## 4. La correction n'est calculée qu'à la soumission

`is_correct` reste nul pendant toute la tentative. Le candidat ne doit pas
pouvoir déduire la bonne réponse de la réaction du serveur — ni du temps de
réponse, ni de la taille du payload.

## 5. Le rattachement de compétence est copié, pas lu

`attempt_items.competency_node_id` duplique la valeur portée par la question au
moment de la présentation. Si la question était re-rattachée plus tard,
l'historique de maîtrise du candidat deviendrait faux rétroactivement.

M�me raison pour laquelle une question présentée ne peut plus être supprimée
(`restrictOnDelete`) : elle se retire, elle ne s'efface pas.

## 6. La série reproduit les poids officiels

Un domaine qui pèse 40 % du concours reçoit 40 % des questions, par répartition
proportionnelle aux plus forts restes.

Une répartition uniforme donnerait un score flatteur à un candidat fort sur un
domaine mineur — et l'enverrait réviser la mauvaise chose. C'est exactement
l'erreur que la plateforme prétend éviter.

Quand un sous-domaine manque de questions publiées, on complète au niveau de
l'épreuve plutôt que de rendre une série incomplète. Et si l'épreuve entière
n'a pas assez de questions, **le diagnostic ne s'ouvre pas** : mieux vaut un
bouton absent qu'un diagnostic qui ne diagnostique rien.

## 7. Certitude+ précède la correction

Le niveau de certitude (`sure` / `hesitant` / `guess`) est déclaré avant de
voir la correction. Il sépare quatre situations que le score seul confond :

| Réponse | Certitude | Lecture |
|---|---|---|
| Juste | sûr | Acquis |
| Juste | hasard | **Non acquis, masqué par la chance** |
| Faux | sûr | **Erreur profonde** — la plus coûteuse à laisser passer |
| Faux | hésitant | Notion fragile |

Les deux lignes en gras sont invisibles sans cette déclaration. Elles
détermineront la priorité de remédiation au pas suivant.

## 8. Quota de causes cumulatif

Fiche F03 : deux causes en compte gratuit. Le compteur est **par candidat et
cumulatif**, jamais remis à zéro — un compteur quotidien cesserait d'inciter à
l'abonnement. Revoir une correction déjà consultée ne consomme rien.

## Ce que ce pas ne fait pas

Le calcul de maîtrise, le plan de remédiation, les rappels espacés et les
questions miroir. Ce sont les pas suivants — celui-ci se contente de garantir
qu'aucune donnée n'est perdue ni falsifiable.
