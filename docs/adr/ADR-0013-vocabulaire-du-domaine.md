# ADR-0013 — Vocabulaire du domaine

**Statut :** accepté · 8 août 2026 — fait autorité sur la nomenclature
**Contexte :** correction OptimGov du 8 août. Le mot « partenaire » avait été
employé pour désigner deux choses différentes, dont l'une n'existe pas.

---

## Pourquoi cet ADR

Un mot mal employé dans un modèle de données survit des années. « Partenaire »
a servi tour à tour à désigner une organisation cliente et un concours du
catalogue — deux objets que l'ADR-0002 sépare précisément parce que les
confondre produirait des tables dupliquées et des droits mal attribués.

Ce document fixe les termes. Le code, les tables, les libellés d'interface et
les prochains ADR s'y conforment.

---

## Les trois objets qu'il ne faut jamais confondre

### 1. Organisme — un **client**

Une organisation qui **paie pour ses membres** : région, province, commune,
délégation, institution de formation.

- C'est un **tenant** au sens de l'ADR-0002.
- Il achète des accès pour un ensemble de personnes, à tarif réduit,
  symbolique, ou gratuit selon la convention négociée.
- Ses membres restent des **candidats** au sens plein : leur compte est global,
  leur progression leur appartient, et elle survit à leur sortie de l'organisme.
- Un organisme ne possède ni concours, ni contenu, ni taxonomie.

**Conséquence sur les droits d'accès (ADR-0010) :** l'organisme est une
**source d'octroi**. Un candidat rattaché à un organisme reçoit ses capacités
par octroi explicite, pas par son rôle ni par un abonnement individuel.

### 2. Concours — un objet de **catalogue**

Ce que le candidat prépare. Organisé en arborescence, **globale et sans
`tenant_id`** :

```
Filière            Famille de concours              Spécialité / Session
─────────────────  ───────────────────────────────  ────────────────────
Post-baccalauréat  Médecine · ENCG · ENSA · ISCAE   selon la famille
Sciences de        CRMEF · Licences professionnelles
l'éducation        Agrégation · COPS
Fonction publique  Recrutement · Concours
                   professionnels
```

- Le catalogue est **partagé par tous les organismes**. Deux clients préparant
  le CRMEF voient le même concours, la même taxonomie, les mêmes questions.
- Un concours n'est **jamais** un tenant. C'est la confusion que l'ADR-0002
  rejette explicitement.
- La filière correspond aux « portails » du prototype.

### 3. Contributeur — une **personne**

Un expert qui fournit un cadre de référence, des annales, ou rédige des
questions. Souvent, ce sont ceux-là mêmes qui conçoivent les épreuves réelles.

- C'est un **utilisateur** portant un rôle (`auteur`, `reviseur`, `editeur`).
- Il **fournit** un cadre de référence ; il n'en devient pas propriétaire. Le
  cadre appartient au concours (ADR-0012 §3).
- Un contributeur n'est pas un organisme, et un organisme n'est pas un
  contributeur — même si une même institution peut être les deux, par deux
  objets distincts.

---

## Table de correspondance

Pour lire les documents antérieurs sans se tromper :

| Terme employé avant | Signification réelle | Terme retenu |
|---|---|---|
| « centre partenaire » | Organisation cliente payant pour ses membres | **Organisme** |
| « partenaire » (dans ADR-0009 à 0011) | Selon le contexte : organisme, ou concours | Voir ci-dessus |
| « allié » | Expert fournissant cadres et annales | **Contributeur** |
| « famille d'examens » | Regroupement de concours | **Famille de concours** |
| « porte », « portail » | Premier niveau du catalogue | **Filière** |

Les ADR-0009 à 0011 ne sont pas réécrits — un ADR ne se corrige pas en place
(METHODE §5). Ils se lisent avec cette table.

---

## Ce que cela implique, et qui reste à trancher

Le modèle d'organisme soulève des questions commerciales que la technique ne
peut pas décider :

| Question | Pourquoi elle compte |
|---|---|
| L'accès est-il compté par siège, ou illimité pour les membres ? | Détermine tout le modèle de facturation B2B |
| Que devient la progression d'un membre qui quitte l'organisme ? | Position retenue : elle lui appartient et le suit. À confirmer par écrit dans les conventions |
| Un organisme voit-il les résultats individuels de ses membres ? | Question de protection des données, pas de fonctionnalité. Position par défaut : agrégats uniquement |
| Un organisme peut-il restreindre les concours accessibles à ses membres ? | Change la conception du catalogue vu par le candidat |

Ces quatre points vont dans `docs/DETTE.md` avec la mention « avant le premier
contrat organisme ».

---

## Règle permanente

**Un objet de catalogue ne devient jamais un tenant, et un tenant ne possède
jamais de contenu.** Le jour où quelqu'un proposera « un tenant par concours
pour bien séparer les données », c'est cet ADR et l'ADR-0002 qu'il faudra lui
opposer.
