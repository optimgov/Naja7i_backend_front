# ADR-0017 — Maîtrise : pas de score sans évidence, pas de prédiction

**Statut :** accepté · 9 août 2026
**Contexte :** PAS-7. Règle R04, fiches F01, F02, F06. METHODE §7.3 et §7.4.

---

## 1. Aucun score sans son volume d'évidence

Un taux de 100 % sur deux questions et un taux de 100 % sur trente ne disent
pas la même chose. Les afficher identiquement serait la façon la plus efficace
de tromper un candidat tout en ayant l'air rigoureux.

En dessous de cinq réponses, **le score reste nul** et l'interface indique
combien de réponses manquent. La contrainte est en base — `CHECK (score IS NULL
OR evidence <> 'insufficient')` — parce qu'un service peut être contourné, une
contrainte non.

Trois niveaux : insuffisant (< 5), faible (5 à 9), suffisant (≥ 10).

## 2. La certitude pondère la réussite

| Réponse | Certitude | Poids |
|---|---|---:|
| Juste | sûr | 1,00 |
| Juste | hésitant | 0,85 |
| Juste | **hasard** | **0,35** |
| Faux | quel qu'il soit | 0,00 |

Le cas décisif est « juste au hasard ». Compter 1 masquerait une lacune que le
candidat découvrirait le jour du concours ; compter 0 punirait un savoir
partiel réel. **0,35 est un paramètre à ajuster sur données réelles, pas une
constante de nature.** Il est nommé et isolé pour cette raison.

Les réussites au hasard et les erreurs commises avec certitude sont en outre
comptées séparément : ce sont les deux situations que le score seul rend
invisibles.

## 3. L'agrégation suit les poids officiels, pas le volume

Le score d'un domaine est la moyenne de ses sous-domaines **pondérée par leur
poids officiel**, pas par le nombre de réponses.

Sinon un candidat qui s'entraîne beaucoup sur un sous-domaine mineur verrait
son score de domaine monter sans que sa préparation au concours ait progressé.

L'évidence, elle, s'additionne : un parent hérite du volume de ses enfants.

## 4. Aucune probabilité de réussite, sous aucun nom

Règle permanente METHODE §7.3. Ni « chances d'admission », ni « indice de
réussite », ni score prédictif déguisé. Un test parcourt les sorties et échoue
si ces termes apparaissent.

La raison n'est pas juridique mais produit : une prédiction fausse démobilise
un candidat qui aurait pu réussir, et rassure celui qui allait échouer. Aucun
des deux effets n'est réparable après le concours.

## 5. L'ordonnance croise trois facteurs, dans cet ordre

1. **Le poids officiel du domaine** — réviser un domaine à 5 % avant un
   domaine à 40 % est un mauvais emploi du temps du candidat, même si le score
   y est plus bas.
2. **L'écart de maîtrise** — ce qui manque, pas ce qui est acquis.
3. **La nature des erreurs** — une erreur commise avec certitude compte
   double : le candidat ne sait pas qu'il ne sait pas, donc il ne réviserait
   jamais ce point de lui-même.

Un domaine jamais évalué entre dans l'ordonnance avec un **motif distinct**
(`jamais_evalue`) : le candidat doit savoir qu'on lui propose de découvrir, pas
de corriger.

## 6. Chaque recommandation porte sa raison

Aucune ligne d'ordonnance ne sort sans motif lisible. Une recommandation sans
raison est une injonction, et le produit refuse d'en donner — c'est le même
principe que la justification obligatoire des distracteurs.

Quand aucune ressource de remédiation n'existe pour un nœud, la réponse le dit.
On n'en fabrique pas.

## Ce que ce pas ne fait pas

Les rappels espacés (F07), la mission du jour (F09), les questions miroir en
série et le simulateur. Le recalcul est ici synchrone ; il deviendra un abonné
d'événement `AttemptSubmitted` quand le volume l'exigera (ADR-0011 §1).
