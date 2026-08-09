# ADR-0018 — Surface HTTP du parcours candidat

**Statut :** accepté · 9 août 2026
**Contexte :** PAS-8. Applique ADR-0010 (droits d'accès) et la fiche F03.

---

## 1. Deux ressources séparées, jamais une seule paramétrable

`AttemptQuestionResource` présente une question pendant la tentative :
énoncé et options, rien d'autre. `CorrectionResource` présente la correction
après soumission.

Ce sont **deux classes distinctes**, pas une ressource avec un drapeau
`withCorrection`. Un drapeau finit toujours par être mal positionné quelque
part ; deux classes rendent la faute impossible à commettre par distraction.

Un test parcourt le corps JSON d'une tentative en cours et échoue si les
chaînes `rationale`, `cause` ou `is_correct` y apparaissent — même dans un
objet imbriqué.

## 2. Répondre ne renvoie aucun verdict

L'endpoint de réponse retourne l'avancement, jamais la justesse. Sinon le
candidat déduirait la bonne réponse en observant la réponse du serveur — voire
sa taille ou son temps.

## 3. Le droit d'accès est le seul point d'autorisation produit

Aucun contrôleur n'interroge un abonnement, un rôle ou un niveau de compte.
Tous passent par `AccessGrant::allows()`. Brancher CMI ajoutera une origine
d'octroi, pas une branche de code dans un contrôleur.

Les octrois sont **globaux, sans `tenant_id`** : le droit suit le compte. Un
candidat dont l'organisme a payé conserve compte et progression s'il en part
(DET-24). L'organisme émetteur reste tracé, jamais comme condition de validité.

La capacité est vérifiée **au moment de l'usage**, jamais mise en cache dans la
session : un abonnement qui expire pendant une session prend effet aussitôt.

## 4. Le quota masque la cause, pas la justification

Quand le quota gratuit est épuisé, la correction reste complète — énoncé,
justification de chaque option, remédiation — et seule la **cause d'erreur**
est remplacée par `cause_locked: true`.

Masquer aussi les justifications transformerait le compte gratuit en QCM
ordinaire, et retirerait au candidat la raison même de s'inscrire. Le partage
retenu laisse la valeur démontrable et réserve le diagnostic.

Le décompte est effectué une seule fois par réponse : revenir sur sa correction
ne recoûte rien.

## 5. La démonstration publique porte son marqueur dans l'API

`GET /demonstration/correction` retourne `is_example: true` et un avertissement
dans `meta.notice`. Ce marqueur est **contractuel**, pas décoratif : sans lui,
une interface pourrait un jour présenter la démonstration comme le résultat du
visiteur et lui attribuer une erreur qu'il n'a pas commise.

La démonstration puise dans les questions éligibles au diagnostic — donc dont
tous les distracteurs sont étiquetés, par la garde du PAS-5. C'est cette
garantie qui rend l'écran possible.

## 6. 404, jamais 403, entre candidats

Une tentative appartenant à un autre candidat est **introuvable**. Le scope
tenant filtre déjà ; le filtre par utilisateur ferme le reste. Un 403
confirmerait l'existence de la ressource.

## 7. Le recalcul de maîtrise suit la soumission

Synchrone à ce stade (DET-20). Il deviendra un abonné de l'événement
`AttemptSubmitted` avant la montée en charge — sans que la surface HTTP change.

## Ce que ce pas ne fait pas

Séries d'entraînement ciblées, simulateur, questions miroir en série, rappels
espacés, profil candidat. Le parcours livré est : diagnostic → correction →
maîtrise → ordonnance.
