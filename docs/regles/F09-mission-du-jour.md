# F09 — Priorités de préparation (vue F06)

**Statut :** **brouillon révisé — requalifié en vue F06**, jamais validé
**Version :** 0.3 — 21 août 2026
**Validée par :** — *(en attente d'arbitrage OptimGov)*
**Source de la ligne d'origine :** `NAJAH-INV-001` §8, F09
**Dépend de :** [[F06]], [[F07]], contrat `me/profile`, lot 2 « Mon dossier »

> **Avertissement de méthode.** Cette fiche décrit ce qui existe : une
> troncature de [[F06]] calculée côté frontend. OptimGov a tranché qu'en première
> version F09 n'est pas une fonction autonome, mais une vue compacte de F06.

---

## Pourquoi cette fonction existe

L'ordonnance dit quoi réviser, dans l'ordre, sur toute l'épreuve. C'est une
liste — et une liste ne se commence pas. Cette vue répond à une question plus
étroite : **par quelles priorités commencer maintenant ?**

Elle transforme un plan en un geste.

Elle ne promet pas une mission quotidienne : aucune notion de jour, de temps
disponible ou d'accomplissement n'existe dans le comportement livré.

## Quand elle se déclenche

À l'ouverture du tableau de bord, après lecture de `me/profile`. Le profil
détermine le parcours ou l'épreuve suivie avant toute interrogation de F06.

Si aucun profil ou parcours n'est déterminé, aucune requête d'ordonnance n'est
émise et l'écran présente la porte de configuration appropriée.

**État vérifié au 21 août 2026 :** `me/profile` n'a aucun appelant frontend
(M-05/FRONT-1). Le lot 2 « Mon dossier socle » doit fermer cette dépendance ; la
vue ne peut pas être déclarée livrée avant son câblage.

## Ce qu'elle fait

1. Elle lit `me/profile`, puis demande **trois lignes** à l'ordonnance de
   l'épreuve suivie.
2. Elle n'en affiche **jamais plus de trois** — la borne est tenue deux fois,
   dans la requête et dans le rendu.
3. Chaque ligne mène à l'entraînement ciblé qui la remplit.
4. Elle conserve l'ordre exact de F06 et ne duplique aucun calcul de priorité
   dans le frontend.
5. À côté d'elle, le tableau de bord affiche le **nombre de rendez-vous mémoire
   échus** — la seule information de l'écran qui change tous les jours, et donc
   la raison de revenir.

Le nombre trois est une **constante de présentation**, pas un paramètre
pédagogique et pas une constante métier F06. Il ne changerait de nature que si
une future fonction autonome était spécifiée.

## Ce qu'elle ne fait jamais

- **Aucune prédiction**, aucun « il vous reste X jours pour être prêt ».
- **Aucun objectif chiffré non tenu.** Annoncer une mission puis ne pas savoir si
  elle est accomplie serait pire que ne rien annoncer.
- **Elle ne remplace pas les rendez-vous mémoire.** Les deux coexistent sur le
  tableau de bord et ne se confondent pas : l'un est une dette de mémoire, l'autre
  une priorité de révision.
- **Elle ne culpabilise jamais.** Une mission non faite ne se reproche pas.
- **Elle n'affiche jamais le nom « Mission du jour ».** Le mot « jour » n'est
  porté par aucune règle.
- **Elle ne calcule jamais de priorité propre.** L'ordre vient intégralement de
  F06.
- **Elle ne mémorise aucun accomplissement** et ne prétend pas le faire.

## Cas limites

| Situation | Comportement |
|---|---|
| **Profil absent ou aucune épreuve suivie** | Aucune requête F06. L'écran porte la porte vers la configuration du dossier/parcours. |
| **Ordonnance vide** | La section reste présente et porte **le geste qui la remplit** — un lien vers le diagnostic. Un état vide sans sortie est un cul-de-sac. |
| **Moins de trois lignes** | On affiche ce qu'il y a. On ne complète pas avec du remplissage. |
| **Le candidat suit une priorité** | La vue ne stocke aucun état d'accomplissement ; les effets appartiennent à l'entraînement ciblé et à F06. |
| **Le candidat revient le lendemain** | Les priorités peuvent être identiques, puisqu'elles sont recalculées depuis F06 et non depuis une notion de jour. |
| **`me/profile` indisponible sur le réseau** | Aucune épreuve n'est devinée et aucune requête F06 n'est émise ; l'absence de donnée ne vaut pas absence de parcours. |
| **Tout premier usage** | Aucune ligne, et la porte vers le diagnostic. |

## Ce que voit le candidat

Un titre honnête, au plus trois lignes cliquables dans l'ordre exact de F06, et
à proximité le compte des rendez-vous échus.

**Formulation exacte :** *à trancher.* Recommandation française : « Priorités
de préparation ». Aucun libellé « Mission du jour » n'est permis dans cette
version.

## Tests d'acceptation

- [ ] Jamais plus de trois lignes, quelle que soit la taille de l'ordonnance.
- [ ] Chaque ligne est une **ancre ou un bouton** dans le rendu.
- [ ] Ordonnance vide → la section porte un chemin cliquable vers le diagnostic.
- [ ] `me/profile` sans parcours suivi → aucune requête d'ordonnance n'est émise
      et la porte de configuration est visible.
- [ ] Profil déterminé → l'appel au profil précède F06 et les lignes conservent
      l'ordre exact de l'ordonnance.
- [ ] Aucun calcul de priorité propre à F09.
- [ ] Aucun objectif chiffré n'est annoncé sans être mesuré.
- [ ] Aucun libellé « Mission du jour » n'est rendu.
- [ ] Avant le lot 2, le test d'intégration constate l'absence d'appelant de
      `me/profile` ; après câblage, il prouve la séquence profil puis F06.

## À trancher

| # | Question | Options | Conséquence du choix |
|---|---|---|---|
| 1 | **Libellé final FR et AR** | Recommandation FR : « Priorités de préparation » | Décision produit visible ; le libellé ne peut contenir « jour » ni promettre un accomplissement suivi. |
| 2 | **Créer plus tard une vraie mission quotidienne ?** | (a) non · (b) fonction autonome future | (b) exige de définir le jour et le fuseau, stocker l'accompli et articuler F06/F07 ; c'est hors du présent lot. |

## Dépendances

[[F06]] — dont cette vue est une troncature sans logique propre. [[F07]] reste
une information voisine distincte. Le contrat `me/profile` et le lot 2 « Mon
dossier » sont des prérequis techniques : FRONT-1 doit être fermé avant que la
vue soit déclarée livrée. La fiche ne peut pas être validée avant F06 ni avant
l'arbitrage de son libellé FR/AR.
