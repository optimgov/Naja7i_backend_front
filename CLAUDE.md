# Naja7i — backend (Laravel)

## Protocole de décision — à lire en premier

**Par défaut, tu décides et tu avances.** Tu rends compte de tes décisions dans
le rapport final, avec leur raison. Tu ne demandes pas la permission pour ce que
tu peux défaire.

Décide seul, sans demander : nommage, structure de fichiers, choix de relation ou
de scope, découpage des commits, refactor local, ajout d'un test, formulation d'un
message de commit, ordre des étapes. Si deux options se valent, prends celle qui
suit la convention déjà présente dans le dépôt, et dis en une ligne pourquoi.

**Arrête-toi et demande uniquement dans ces cinq cas :**

1. L'action est **irréversible** : `git push`, une migration destructive, une
   suppression de données, une écriture en production.
2. C'est une **décision de produit** visible par un candidat — un libellé, une
   route, un parcours de navigation — qui n'est pas déjà tranchée dans `docs/`.
3. Une **contrainte annoncée devient impossible** à tenir. Tu ne la contournes
   jamais en silence.
4. **Deux sources de vérité se contredisent** — la consigne et un document de
   `docs/`, ou deux documents entre eux.
5. Il faut **installer une dépendance** qui n'est pas déjà au `composer.json`.

Hors de ces cinq cas : agis, puis explique. Un rapport qui dit « j'ai choisi X
plutôt que Y parce que Z » vaut mieux qu'une question posée à mi-parcours.

## Conventions du dépôt

- **Pas de branche.** Chaque pas est un commit sur `main`.
- **Chaque commit de pas est suivi d'un commit `docs:`** qui inscrit son SHA dans
  `docs/PAS.md` et `docs/BACKLOG.md`. Ce second commit n'est pas optionnel.
- La dette identifiée en chemin va dans `docs/DETTE.md`, les décisions
  d'architecture dans `docs/adr/`.
- Le message de commit explique **pourquoi**, pas seulement quoi. Relis les trois
  derniers pour la forme.
- `./vendor/bin/pint` avant de commiter. Les tests tournent **en séquentiel** —
  `--parallel` ne fonctionne pas, `paratest` est absent (DET-28).

## Règles de conception non négociables

- **404, jamais 403.** Une ressource d'un autre candidat est introuvable, pas
  interdite. Le filtre par `user_id` fait foi.
- **Le mur payant est un champ, pas une route.** Précédent à suivre :
  `CorrectionResource` rend la justification et ferme la cause avec
  `cause_locked`. Une route entière réservée serait invendable sous la règle 404.
- **Les ressources exposées sont des listes blanches strictes.**
  `AttemptQuestionResource` n'expose ni `is_correct`, ni `rationale`, ni `cause` —
  un champ ajouté demain au modèle ne doit pas apparaître par accident.
- **L'autorisation est portée par le middleware sur la route**, jamais par un `if`
  dans le contrôleur.
- **Aucune prédiction de réussite**, nulle part, même dérivée (METHODE §7.3).
- **Un score ne sort jamais sans son volume d'évidence** — c'est structurel dans
  `MasteryScore::toPublicArray`, pas une convention d'affichage.

## Outillage

- Préfère `git -C <chemin> <commande>` à `cd <chemin> && git <commande>`.
- Une seule autre session peut travailler sur le dépôt frontend en parallèle. Ne
  va jamais y vérifier quoi que ce soit sans y avoir été invité.
