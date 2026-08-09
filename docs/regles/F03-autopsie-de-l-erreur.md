# F03 — Autopsie de l'erreur

**Statut :** validée — aucune décision produit en attente
**Version :** 1.1 — 8 août 2026
**Décision d'étiquetage :** OptimGov, 8 août 2026
**Source de la ligne d'origine :** `NAJAH-INV-001` §8, F03
**Dépend de :** ADR-0014 (provenance), référentiel CRMEF 2025

---

## Pourquoi cette fonction existe

Un candidat qui se trompe sait qu'il s'est trompé ; il ne sait presque jamais
**pourquoi**. Sans cause identifiée, il refait la même erreur sous un autre
habillage — et son temps de révision se dilue.

Cette fonction propose une hypothèse sur la nature de l'erreur. C'est ce qui
distingue « vous avez eu faux » de « vous avez confondu deux notions voisines »,
et c'est de là que découle toute remédiation ciblée.

## Quand elle se déclenche

À l'affichage de la correction, après validation d'une réponse fausse.

**Condition nécessaire :** le distracteur choisi porte une cause étiquetée.
Sans étiquette, la fonction ne s'affiche pas — elle ne devine jamais.

## Ce qu'elle fait

1. Lit la cause associée au **distracteur choisi**, telle qu'étiquetée par
   l'auteur de la question. Ce n'est pas une inférence : c'est une donnée
   éditoriale.
2. L'affiche comme **hypothèse** : « Cette erreur vient souvent de… », jamais
   « Vous avez… ».
3. La relie au sous-domaine de rattachement de la question.
4. Propose l'action suivante (F06, Ordonnance).

### Les huit codes de cause

| Code | Nature de l'erreur |
|---|---|
| `confusion_notions` | Deux notions voisines confondues |
| `lecture_enonce` | Énoncé mal lu : négation, quantificateur, consigne |
| `regle_mal_appliquee` | Règle connue, appliquée hors de son domaine |
| `connaissance_absente` | La notion n'est pas acquise |
| `source_perimee` | Réponse juste selon un texte abrogé |
| `calcul` | Raisonnement correct, exécution fautive |
| `piege_formulation` | Formulation conçue pour induire l'erreur |
| `indetermine` | Cause non identifiable |

## Règle d'obligation — décision du 8 août 2026

**L'étiquetage est obligatoire pour toute question éligible au diagnostic ou à
la remédiation. Il reste facultatif ailleurs.**

| Usage de la question | Étiquetage des distracteurs |
|---|---|
| Diagnostic (`eligibleForDiagnostic`) | **Obligatoire** — publication refusée sans |
| Remédiation, question miroir | **Obligatoire** |
| Entraînement libre | Facultatif |
| Simulation (`eligibleForSimulation`) | Facultatif |

**Pourquoi ce partage.** Écrire pourquoi chaque mauvaise réponse est tentante
demande plus de réflexion que d'écrire la question elle-même. L'imposer partout
freinerait la production de volume ; ne l'imposer nulle part créerait deux
qualités de questions dans la même banque, sans que le candidat comprenne
pourquoi son expérience varie.

La règle place l'exigence là où la cause **sert réellement à quelque chose** :
dans le diagnostic, dont toute la valeur tient à la précision du retour.

**Conséquence de conception :** une question sans étiquetage ne peut pas
recevoir `eligibleForDiagnostic = true`. Le contrôle est en base et en test,
pas dans une consigne éditoriale — sinon la règle serait contournée le premier
jour où la production presse.

## Ce qu'elle ne fait jamais

- **Elle n'affirme pas.** Le candidat a pu répondre au hasard, ou pour une
  raison que l'étiquette ne couvre pas. Une hypothèse présentée comme un fait
  serait fausse dans une part mesurable des cas — et détruirait la confiance
  bien plus qu'un silence.
- **Elle ne produit aucun score** ni aucune probabilité de réussite.
- **Elle ne devine pas** en l'absence d'étiquette.
- **Elle ne juge pas la personne.** La cause décrit une erreur, jamais un
  candidat. Aucune formulation du type « vous êtes distrait ».

## Cas limites

| Situation | Comportement |
|---|---|
| Distracteur non étiqueté | La fonction ne s'affiche pas ; la correction reste complète |
| Cause `indetermine` | Affichée honnêtement : la cause n'a pas été identifiée |
| Question modifiée après la tentative | La cause reste figée avec la tentative |
| Question retirée du catalogue | L'historique du candidat reste lisible |
| Le candidat conteste la cause | Signalement éditorial, sans donnée personnelle |
| Premier usage | Aucun effet : la fonction est locale à une réponse |

## Ce que voit le candidat

Formulation à finaliser avec un responsable pédagogique. Contrainte de ton :
hypothèse et non verdict, aucun jugement sur la personne.

- FR — « Cette erreur vient souvent d'une confusion entre deux notions voisines. »
- AR — « غالبا ما ينتج هذا الخطأ عن الخلط بين مفهومين متقاربين. »

## Tests d'acceptation

- [ ] Un distracteur étiqueté affiche sa cause, formulée comme hypothèse.
- [ ] Un distracteur non étiqueté n'affiche aucune cause, correction complète.
- [ ] **Une question dont un distracteur n'est pas étiqueté ne peut pas être
      marquée éligible au diagnostic.** Refus en base, pas seulement en
      interface.
- [ ] Une question éligible au diagnostic dont on retire une étiquette perd son
      éligibilité, elle ne devient pas silencieusement incomplète.
- [ ] La cause est figée avec la tentative.
- [ ] Les huit codes sont traduits en français et en arabe, sans clé brute.
- [ ] Aucune formulation affirmative ni jugeante dans les textes.
- [ ] La contestation d'une cause crée un signalement éditorial.

## Décisions annexes prises avec celle-ci

**Les causes sont conservées pour alimentation ultérieure de F10** (Atlas des
pièges). La donnée est collectée dès maintenant, la fonctionnalité viendra
quand le volume le justifiera. Coût nul aujourd'hui, reconstitution impossible
plus tard : c'est le critère de l'ADR-0011.

## Visibilité selon le public — décision du 8 août 2026

Trois publics, trois traitements distincts.

| Public | Ce qu'il voit | Nature |
|---|---|---|
| **Visiteur sans compte** | Une démonstration : un exemple d'erreur avec sa cause expliquée | Contenu illustratif, jamais un diagnostic |
| **Compte gratuit** | La cause réelle sur **deux questions au maximum**, puis invitation à s'abonner | Diagnostic réel, plafonné |
| **Abonné** | Toutes les causes | Diagnostic complet |

**Précision essentielle sur le visiteur.** La démonstration ne doit jamais
pouvoir être confondue avec un résultat personnel. Elle est présentée comme un
exemple, avec une formulation qui l'annonce. Sinon on attribue au visiteur une
erreur qu'il n'a pas commise — ce qui contredit frontalement la règle « elle ne
juge pas la personne » ci-dessus, et fausserait sa première impression du
produit.

**Précision sur le compte gratuit.** « Gratuit » suppose un compte créé : le
plafond de deux causes se compte par candidat, pas par appareil ni par session.
Sans compte, il n'y a pas de diagnostic du tout, donc pas de cause à afficher.

**Le plafond est un paramètre, pas une constante.** Il vit dans la
configuration du concours (ADR-0011 §5) et sera réglable en back-office. Deux
est la valeur de départ ; l'observation du taux de conversion dira si elle est
juste.

### Conséquences techniques

- Le plafond se vérifie **côté serveur**, à chaque affichage de cause, via le
  contrat de droit d'accès (ADR-0010). Une capacité dédiée, par exemple
  `corrections.cause`, avec un quota.
- Le compteur est **par candidat et cumulatif**, jamais remis à zéro
  quotidiennement : sinon le plafond n'incite plus à s'abonner.
- L'invitation à s'abonner s'affiche **après** la deuxième cause, pas à la
  place de la troisième. Le candidat doit avoir goûté la valeur avant qu'on la
  lui retire.
- La démonstration destinée au visiteur est un **contenu éditorial figé**, pas
  une tentative réelle : elle ne crée aucune ligne dans l'historique et ne
  consomme aucun quota.

### Tests d'acceptation complémentaires

- [ ] Un compte gratuit voit deux causes, puis l'invitation à la troisième.
- [ ] Le compteur ne se réinitialise pas au changement de jour ni de session.
- [ ] Un visiteur sans compte ne déclenche aucune tentative en base.
- [ ] Le contenu de démonstration est identifié comme exemple dans la réponse
      d'API, pas seulement dans l'interface.
- [ ] Modifier le plafond en configuration prend effet sans redéploiement.

## Réserve pédagogique — non bloquante

Les huit codes de cause sont une proposition d'architecte. Un responsable
pédagogique doit confirmer qu'ils couvrent les erreurs réellement observées en
CRMEF et qu'aucun n'est redondant. Une modification de cette liste après mise
en production imposerait de réétiqueter les questions existantes.

## Dépendances

- Banque de questions avec distracteurs étiquetés (PAS-6)
- F06 — Ordonnance Najah, pour l'action suivante
- Drapeaux `eligibleForDiagnostic` / `eligibleForSimulation` du référentiel CRMEF
