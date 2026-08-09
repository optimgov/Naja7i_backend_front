# ADR-0019 — Application des permissions : ce qui manquait à l'ADR-0009

**Statut :** accepté · 9 août 2026 — exécute l'ADR-0009
**Contexte :** PAS-9. Écart relevé par l'audit externe du 9 août, garde-fou G10.

---

## Ce qui n'allait pas

L'ADR-0009 décidait, le 8 août, que les permissions deviendraient des données
et que `hasPermission()` remplacerait `hasRole()` dans les policies.

**Un mois de code plus tard, rien n'était appliqué.** Le contrôle reposait
toujours sur sept rôles en bloc. C'est exactement le mode de défaillance que la
méthode du projet nomme et traque — une règle énoncée dans un document, non
exécutée par le code — et c'est un auditeur externe qui a dû le signaler.

Le fait mérite d'être écrit : nos propres garde-fous n'ont pas attrapé un écart
entre un ADR et le code, parce qu'aucun test ne compare l'un à l'autre. Seul le
regard extérieur l'a vu.

## Décisions d'exécution

### 1. Les permissions sont vérifiées, les rôles sont attribués

`PermissionResolver::has($user, 'questions.publish')` devient le contrôle de
référence. `hasRole()` reste pour les distinctions grossières (candidat contre
staff), jamais pour autoriser une action précise.

Le cumul de rôles donne l'**union** de leurs permissions : un utilisateur à la
fois auteur et réviseur peut faire les deux.

### 2. Aucune mise en cache persistante

Le résultat est mémoïsé pour la durée d'une requête, jamais au-delà. Retirer
une permission à un rôle doit prendre effet immédiatement — sans purge, sans
redéploiement, sans reconnexion. Deux tests le vérifient dans les deux sens.

### 3. Les rôles peuvent appartenir à un organisme

`roles.tenant_id` nullable. L'unicité du code devient partielle : unique parmi
les rôles de plateforme, unique par organisme pour les autres. Deux centres
peuvent nommer un rôle « coordonnateur » ; un même centre ne le peut pas deux
fois.

### 4. Les permissions réservées sont gardées par la base

Un rôle d'organisme ne peut jamais recevoir une permission `platform_only`.
Un trigger le refuse à l'attachement.

La raison est directe : un administrateur d'organisme ne doit pas pouvoir
s'octroyer `tenants.manage` ou `refunds.issue`, même par un chemin détourné —
un import, une commande console, un formulaire mal validé. Le formulaire du
back-office affichera la liste filtrée ; la base refusera le reste.

### 5. Une permission n'existe que si une policy l'utilise

Dix-neuf permissions initiales, correspondant à des actions réelles ou
immédiatement nécessaires. Pas de catalogue spéculatif : trois cents
permissions dont personne ne connaît l'effet seraient pires que sept rôles.

Chacune porte un libellé bilingue destiné à l'écran d'attribution. **Une
permission qu'on ne sait pas décrire en une phrase ne doit pas exister** — un
test vérifie qu'aucune n'est muette en arabe.

## Ce que ce pas ne fait pas

Les policies Laravel elles-mêmes, qui viendront avec le back-office : aucune
action administrative n'existe encore à autoriser. Ce lot pose le mécanisme et
le prouve ; le câblage suivra les écrans.

Le MFA reste non ouvert (ADR-0009 §4), avec ses trois propriétés de
préparation intactes.
