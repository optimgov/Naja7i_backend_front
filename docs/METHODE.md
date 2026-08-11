# Méthode de travail — Naja7i

**Identifiant :** `NAJA7I-METHODE-001` · **Version :** 1.0 — 8 août 2026
**Objet :** comment une intention devient une règle, puis du code. Qui décide quoi.
**Statut :** fait autorité. Toute divergence entre ce document et une pratique
constatée doit être signalée, pas arbitrée en silence.

---

## 1. Le problème que ce document résout

Le projet a plusieurs sources d'information — un cadrage produit, un inventaire,
un prototype qui fonctionne, des ADR techniques, et des conversations. Sans
hiérarchie déclarée, chaque désaccord se rejoue depuis le début, et une décision
prise un mardi disparaît le jeudi.

Un cas concret l'a révélé : le document `NAJAH-FONC-ORIG-001`, cité comme
faisant autorité par l'inventaire pour les fonctions F01 à F14, **n'existe pas**.
Les règles métier de ces fonctions ne sont écrites nulle part. Elles ont vécu
dans des échanges, pas dans un fichier.

---

## 2. Hiérarchie des sources

En cas de contradiction, l'ordre suivant tranche. On ne discute pas la
hiérarchie au cas par cas.

| Rang | Source | Fait autorité sur | Où |
|---|---|---|---|
| 1 | **Fiches de règle** | Le comportement métier des fonctions | `docs/regles/` |
| 2 | **ADR** | Les décisions techniques et leurs raisons | `docs/adr/` |
| 3 | **Le prototype** | Ce qui existe déjà : écrans, parcours, règles R01–R14 | dépôt `Najah.ma` |
| 4 | **Cadrage MVP** (4 août 2026) | Périmètre, priorités, modèle économique | hors dépôt |
| 5 | **Inventaire** `NAJAH-INV-001` | Écarts constatés, décisions ouvertes D01–D10 | hors dépôt |

**Le prototype est une spécification, pas une maquette.** L'inventaire a été
produit par lecture de son code source, pas d'une description. Un prototype qui
fonctionne ne peut pas être ambigu, contrairement à un document — sur tout ce
qu'il couvre, il fait foi.

**Ce qui ne fait autorité nulle part :** une conversation. Une décision prise en
discussion n'existe que lorsqu'elle est écrite dans une des sources ci-dessus.
C'est la règle qui protège le projet contre sa propre mémoire.

---

## 3. Les fonctions F01 à F14

L'inventaire les décrit en une ligne chacune. Ce n'est pas une spécification :
« Identifie la cause probable parmi 8 codes » ne dit ni quels codes, ni sur
quelles données, ni ce qui se passe quand la cause est indéterminable.

**Règle : une fonction n'est pas codée sans sa fiche de règle validée.**

La fiche s'écrit **au moment où la fonction sert**, pas en amont. Une fiche
rédigée six mois avant son implémentation sera périmée le jour où elle servira,
et personne ne le remarquera.

### Le cycle d'une fiche

1. **Déclencheur.** Un pas de développement a besoin de la fonction.
2. **Brouillon.** Claude rédige la fiche à partir de la ligne d'inventaire, du
   prototype et du cadrage. Il remplit tout, y compris ce dont il n'est pas sûr,
   et marque explicitement ces points en « à trancher ».
3. **Arbitrage.** OptimGov valide, corrige ou tranche les points ouverts. C'est
   le seul moment où une décision produit se prend.
4. **Gel.** La fiche passe en statut « validée » avec sa date. Elle fait
   autorité à partir de là.
5. **Implémentation.** Le code et les tests **citent la fiche** — identifiant en
   commentaire, comme les ADR le sont déjà.
6. **Évolution.** Une règle qui change ne se réécrit pas en place : nouvelle
   version de la fiche, l'ancienne conservée. Même discipline que les documents
   juridiques versionnés (ADR-0005).

### Ce qu'une fiche doit contenir

Voir le gabarit `docs/regles/_GABARIT.md`. Les rubriques ne sont pas
décoratives : chacune correspond à un défaut observé ailleurs.

- **Ce que la fonction ne fait jamais** — évite le glissement de périmètre.
- **Cas limites** — le réseau coupe, la donnée manque, le candidat change d'avis.
- **Tests d'acceptation** — écrits avant le code, sinon ils justifient le code.
- **Points à trancher** — visibles, pas dissous dans le corps du texte.

---

## 4. Qui décide quoi

| Rôle | Décide | Ne décide pas |
|---|---|---|
| **OptimGov** | Périmètre, priorités, règles métier, arbitrages produit, validation de fin de pas | Les moyens techniques |
| **Claude (architecte)** | Conception technique, structure du code, contenu des ADR, rédaction des brouillons de fiches | Le métier et le périmètre |
| **Claude Code** | L'exécution dans le cadre donné, les choix de style | Rien de ce qui est écrit dans une fiche ou un ADR |
| **Audit externe** | Rien — il constate et argumente | Il ne tranche pas, il alerte |

**Un désaccord entre Claude et l'audit externe remonte à OptimGov.** Il n'est
jamais tranché entre eux.

---

## 5. Le cycle d'un pas de développement

1. OptimGov tranche les décisions bloquantes du pas.
2. Claude produit le lot : code, instructions, fiches de règle si nécessaires.
3. Claude Code exécute sur la machine d'OptimGov : installation, migrations,
   tests, commit, push.
4. La CI valide sur PostgreSQL réel. **Un pas dont la CI est rouge n'est pas
   livré**, quelle que soit la qualité apparente du code.
5. Audit externe sur le code poussé.
6. Claude répond à l'audit : ce qu'il accepte, ce qu'il conteste et pourquoi.
7. Correction si nécessaire — un pas correctif porte un numéro décimal
   (`PAS-1.1`, `PAS-3.1`), il ne remplace pas le pas d'origine.
8. OptimGov valide.

### Ce qui entre dans la dette plutôt que de bloquer

Seuls les constats **bloquants** et **majeurs** déclenchent une correction
immédiate. Les mineurs vont dans `docs/DETTE.md` avec une échéance. Sans cette
règle, aucun pas ne se termine jamais.

Une entrée de dette sans échéance n'est pas une dette, c'est un oubli.

---

## 6. Les fichiers du dépôt, et ce qu'ils sont

Pour lever une confusion fréquente :

| Fichier | Ce que c'est | Ce que ce n'est **pas** |
|---|---|---|
| `docs/PAS.md` | Journal de ce qui a été livré, avec les commits | Une feuille de route détaillée |
| `docs/DETTE.md` | Ce qui est différé, avec échéance et sévérité | Une liste de souhaits |
| `docs/adr/` | Pourquoi telle décision technique, et ce qu'elle exclut | De la documentation d'API |
| `docs/regles/` | Le comportement métier attendu | De la documentation utilisateur |
| `docs/METHODE.md` | Ce document | Un règlement intérieur |

---

## 7. Règles permanentes, indépendantes des pas

Elles ne se renégocient pas d'un pas à l'autre.

1. **Le serveur est seul juge.** Aucun contrôle d'accès ne repose sur
   l'interface. Une garde de route est un confort, jamais une sécurité.
2. **Aucune publication de contenu par IA sans validation humaine**, et le
   valideur n'est jamais le rédacteur.
3. **Aucun score prédictif de réussite au concours**, sous aucun nom.
4. **Aucun résultat affiché sans explication**, et aucun score de maîtrise sans
   son volume d'évidence.
5. **Aucune question publiée sans justification de chaque distracteur.**
6. **404, jamais 403** pour une ressource qui appartient à AUTRUI — un autre
   candidat, un autre organisme. Un 403 y confirmerait une existence, et
   permettrait d'énumérer ce qu'on ne possède pas. La règle vise l'énumération,
   pas la politesse du refus. **Une permission de personnel refusée répond 403
   explicite** : celui qui la reçoit sait déjà qu'une surface d'administration
   existe — elle est dans le code — et lui répondre « introuvable » masquerait
   la vraie raison sans rien protéger.
7. **Tests sur PostgreSQL réel**, jamais SQLite.
8. **Les tests ne sont jamais modifiés pour passer.** C'est le code qui change.

---

## 8. Ce document lui-même

Il vit dans le dépôt et se modifie par commit, comme le reste. Une méthode qui
ne serait consignée que dans une conversation reproduirait exactement le
problème décrit au §1.
