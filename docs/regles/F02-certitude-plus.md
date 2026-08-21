# F02 — Certitude+

**Statut :** **brouillon — reconstruite depuis le code livré**, jamais validée
**Version :** 0.1 — 21 août 2026
**Validée par :** — *(en attente d'arbitrage OptimGov)*
**Source de la ligne d'origine :** `NAJAH-INV-001` §8, F02
**Dépend de :** ADR-0016 (tentatives), ADR-0017 §2

> **Avertissement de méthode.** Fiche écrite après le code. Voir l'avertissement
> identique en tête de [[F01]].

---

## Pourquoi cette fonction existe

Une bonne réponse ne dit pas si le candidat savait. Un QCM à quatre options
donne 25 % de réussite à qui ne sait rien — et sur une série de vingt, cela
suffit à fabriquer une impression de compétence qui ne survivra pas au concours.

Certitude+ demande au candidat de déclarer ce qu'il pense de sa propre réponse
**avant de connaître le verdict**. C'est ce qui permet à tout le reste du
produit — maîtrise, ordonnance, rendez-vous mémoire — de distinguer un savoir
d'une chance.

## Quand elle se déclenche

**À chaque réponse, sans exception.** Ce n'est pas un champ facultatif :
la validation le refuse absent.

```
'confidence' => ['required', 'in:sure,hesitant,guess']
```

Trois valeurs, et trois seulement : **sûr**, **hésitant**, **au hasard**.

## Ce qu'elle fait

1. **Elle enregistre la certitude avec la réponse**, avant que le verdict ne
   soit connu du candidat.

2. **Elle pondère la réussite dans le calcul de maîtrise** :

   | Réponse | Certitude | Poids | Ce que ça dit |
   |---|---|---:|---|
   | Juste | sûr | 1,00 | Savoir établi |
   | Juste | hésitant | 0,85 | Savoir probable, pas assuré |
   | Juste | **au hasard** | **0,35** | **Chance, pas savoir** |
   | Fausse | quelle qu'elle soit | 0,00 | — |

   Le cas décisif est « juste au hasard ». Compter 1 masquerait une lacune que
   le candidat découvrirait le jour du concours. Compter 0 punirait un candidat
   honnête qui a peut-être un savoir partiel — et surtout, apprendrait aux
   candidats à ne jamais déclarer « au hasard ».

3. **Elle alimente deux signaux distincts et nommés :**
   - la **réussite au hasard** — le score surestime le savoir réel ;
   - l'**erreur commise avec certitude** — le candidat ne sait pas qu'il ne sait
     pas, donc il ne réviserait jamais ce point de lui-même. C'est le signal le
     plus précieux du produit.

4. **Elle pèse dans l'urgence de l'ordonnance** : une erreur commise avec
   certitude compte double ([[F06]]).

## Ce qu'elle ne fait jamais

- **Elle ne juge pas le candidat sur sa déclaration.** Déclarer « au hasard »
  n'est jamais pénalisé au-delà de la pondération, et n'apparaît jamais comme un
  reproche.
- **Elle ne se déduit pas du temps de réponse**, ni d'aucun signal implicite.
  Une certitude inférée serait une invention — le candidat seul sait.
- **Elle n'est pas modifiable après coup.** La certitude vaut au moment de la
  réponse ; la réviser après le verdict lui retirerait tout sens.
- **Elle ne s'affiche jamais comme une note de lucidité**, un « indice de
  métacognition » ou tout autre score dérivé qui reviendrait à noter la
  personne plutôt que le savoir.

## Cas limites

| Situation | Comportement |
|---|---|
| **Question sautée** (aucune option choisie) | La certitude reste exigée par la route, mais la question compte comme **sautée**, pas comme fausse. Voir §À trancher, point 1. |
| **Réseau coupé** entre la sélection et l'envoi | La réponse est mise en file côté client et rejouée ; la certitude voyage avec elle. La soumission attend l'acquittement de toute la file. |
| **Candidat change d'avis** avant de valider | Libre : rien n'est enregistré tant que la réponse n'est pas envoyée. |
| **Tout premier usage** | Aucun historique nécessaire — la fonction est autonome dès la première question. |

## Ce que voit le candidat

Trois choix, présentés **avant la validation de la réponse** et jamais après le
verdict.

**Formulation exacte :** *à trancher.* Ce qui est acquis : aucun code
d'énumération brut à l'écran (`sure`, `hesitant`, `guess` se traduisent), et les
trois libellés doivent être également **acceptables** — un libellé « au hasard »
formulé comme un aveu ferait mentir les candidats, et détruirait la donnée qui
justifie toute la fonction.

## Tests d'acceptation

- [ ] Une réponse sans certitude est **refusée** par la validation.
- [ ] Une valeur hors des trois est refusée.
- [ ] Juste + sûr et juste + hasard sur la même question produisent des scores
      de maîtrise **différents**.
- [ ] Une erreur déclarée « sûr » pèse **double** dans l'urgence de l'ordonnance
      par rapport à une erreur hésitante.
- [ ] La certitude ne peut pas être modifiée après soumission.
- [ ] Aucune sortie de l'API n'expose de score dérivé de la seule certitude.
- [ ] **Mutation :** on rend `confidence` facultative → le premier test rougit,
      et lui seul.

## À trancher

| # | Question | Options | Conséquence du choix |
|---|---|---|---|
| 1 | **Que vaut la certitude d'une question sautée ?** | (a) exigée mais ignorée · (b) non exigée · (c) valeur dédiée | Aujourd'hui la route l'exige toujours. (c) donnerait un signal propre mais ajoute une quatrième valeur à une échelle qui tient sa force de sa brièveté. |
| 2 | **Les trois poids (1 / 0,85 / 0,35) deviennent-ils des paramètres pédagogiques ?** | (a) constantes · (b) paramètres bornés | Cohérent avec A-10, mais ils sont **le cœur du calcul de maîtrise** : les rendre réglables sans borne stricte permettrait de fabriquer n'importe quel score. |
| 3 | **Formulation exacte des trois libellés, FR et AR** | — | Décision produit, et elle est plus lourde qu'elle n'en a l'air : le libellé de « au hasard » décide de l'honnêteté de toute la donnée. |
| 4 | **Une échelle à trois crans suffit-elle ?** | — | Le prototype en avait trois ; aucun document ne dit pourquoi. À confirmer plutôt qu'à supposer. |

## Dépendances

[[F01]] (qui consomme la pondération), [[F06]] (qui consomme les signaux),
[[F07]] (dont la sortie du calendrier exige deux réussites **certaines**),
ADR-0016, ADR-0017 §2.
