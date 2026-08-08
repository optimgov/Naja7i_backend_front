# ADR-0009 — RBAC affinable par l'administrateur, et prêt pour le MFA

**Statut :** accepté · 8 août 2026 — complète ADR-0003
**Contexte :** exigence OptimGov du 8 août 2026 : l'administrateur doit pouvoir
affiner les permissions, et le MFA doit pouvoir être ajouté sans reprise.

---

## Ce qui existe et ce qui manque

L'ADR-0003 a posé sept rôles en table et des appartenances par tenant. C'est
suffisant pour distinguer un candidat d'un éditeur, insuffisant pour un SaaS :

- Un rôle est un bloc. On ne peut pas autoriser un réviseur à publier sans en
  faire un éditeur.
- Les rôles sont globaux. Un centre partenaire ne peut pas définir les siens.
- Aucune policy n'existe : le contrôle d'accès est pour l'instant théorique.

## Décision

### 1. Les permissions deviennent des données, pas du code

Une table `permissions` (code, libellé FR/AR, domaine) et une table pivot
`permission_role`. Une permission est un code stable et lisible :
`questions.create`, `questions.publish`, `imports.run`, `subscriptions.refund`.

`hasPermission('questions.publish')` remplace `hasRole('editeur')` dans les
policies. Les rôles restent le moyen normal d'attribuer un paquet de
permissions ; ils cessent d'être le moyen de les vérifier.

**Conséquence directe :** ajouter une capacité ne demande plus de migration, et
un administrateur peut ajuster un rôle sans développeur.

### 2. Les rôles peuvent être globaux ou propres à un tenant

`roles.tenant_id` devient nullable :

- `NULL` — rôle de la plateforme, identique partout (les sept actuels).
- Renseigné — rôle défini par un centre partenaire, visible de lui seul.

C'est le mécanisme sans lequel le B2B ne tient pas : un centre voudra un rôle
« coordonnateur » qui n'existe nulle part ailleurs. Le poser maintenant coûte
une colonne ; le poser après coup coûte une migration sur des données vivantes.

**Garde-fou :** un rôle de tenant ne peut jamais recevoir une permission de
niveau plateforme. La liste des permissions réservées est explicite en code,
pas laissée à l'appréciation d'un administrateur de centre.

### 3. Ce que le RBAC ne décide pas

Le RBAC répond à « avez-vous le droit d'agir ». Il ne répond pas à « avez-vous
payé pour ce contenu ». Cette seconde question relève des droits d'accès
(ADR-0010). Aucun rôle ne s'appellera jamais `premium`.

### 4. Préparation au MFA — sans le construire

Le MFA n'est pas implémenté. Trois propriétés garantissent qu'il pourra l'être
sans reprise :

- **Un point de passage unique.** Toute connexion passe par `AuthController`.
  Insérer une étape de second facteur n'y touchera qu'un endroit.
- **La table `identities` existe déjà.** Une méthode TOTP s'y ajoutera comme
  ligne, sans changement de structure du compte.
- **`roles.is_staff` distingue déjà** les rôles back-office. C'est le
  discriminant qui rendra le MFA obligatoire pour eux.

**Décisions prises maintenant, applicables le jour venu :** MFA **obligatoire**
pour tout rôle `is_staff`, **facultatif** pour les candidats, et codes de secours
à usage unique fournis à l'activation — sans eux, un téléphone perdu se
transforme en compte perdu.

**Ce qu'on ne fait pas :** ajouter dès aujourd'hui des colonnes `mfa_secret`
spéculatives. Une colonne inutilisée pendant un an sera mal conçue le jour où
elle servira.

## Ce que ce choix coûte

Les permissions fines multiplient les points de vérification. Sans discipline,
on obtient trois cents permissions dont personne ne connaît l'effet.

Deux règles pour l'éviter :
1. Une permission n'est créée que lorsqu'une policy l'utilise réellement.
2. Chaque permission porte un libellé bilingue destiné à l'écran d'attribution.
   Une permission qu'on ne sait pas décrire en une phrase ne doit pas exister.

## Tests d'acceptation

- Un rôle sans la permission requise reçoit 403 sur l'action correspondante.
- Un rôle de tenant ne peut pas recevoir une permission réservée à la plateforme.
- Une permission retirée à un rôle prend effet sans redéploiement.
- Un utilisateur cumulant deux rôles obtient l'union de leurs permissions.
- Les permissions restent évaluées **dans le tenant courant**.
