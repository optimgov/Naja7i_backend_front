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
  `--parallel` ne fonctionne pas, `paratest` est absent, et la mesure du PAS-20
  a montré que ce n'était pas le sujet (DET-28).
- **Une durée de suite se mesure par MÉDIANE SUR TROIS exécutions, et se cite
  avec sa fourchette.** Un chiffre ponctuel est trompeur ici : à code
  identique, la suite complète a été mesurée entre ~125 s et ~300 s selon la
  charge de la machine. Le PAS-25 a établi que ces écarts sont de l'attente et
  non du travail — le CPU reste constant, à ~1,2 s pour `AuthenticationTest`,
  que l'exécution dure 6 s ou 86 s. Comparer deux révisions sur une exécution
  unique ne prouve donc rien.
- **Ne lance jamais deux exécutions de la suite en même temps.** Elles partagent
  la base `naja7i_test` et se détruisent mutuellement : le résultat est une
  avalanche d'échecs qui n'a rien à voir avec le code.
- Le catalogue de test est semé **une fois par processus**, hors transaction
  (`Tests\TestCase::$seed`). N'appelle pas `$this->seed(CatalogueSeeder::class)`
  dans un `setUp()` : c'est déjà fait, et le rejouer coûte 0,22 s par test.

## Règles de conception non négociables

- **404, jamais 403 — pour ce qui appartient à AUTRUI.** Une ressource d'un
  autre candidat ou d'un autre organisme est introuvable, pas interdite : le
  filtre par `user_id` fait foi. La règle vise l'ÉNUMÉRATION — un 403 y
  confirmerait une existence.
  **Une permission de personnel refusée répond 403 explicite** (`RequirePermission`,
  PAS-9). Le refusé sait déjà que la surface d'administration existe ; lui
  répondre « introuvable » masquerait la raison sans rien protéger.
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
- Pour LIRE un fichier ou en extraire un passage : Read, grep -n -A/-B, head, tail — jamais sed pour lire. Le détecteur de sécurité de sed déclenche une demande d'approbation même sur un sed inoffensif, et chaque demande interrompt le travail.
