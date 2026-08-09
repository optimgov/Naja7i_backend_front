# ADR-0015 — Banque de questions : monolingue, versionnée, éligibilité contrôlée en base

**Statut :** accepté · 8 août 2026
**Contexte :** PAS-5. Fiche F03 validée, référentiel CRMEF 2025, ADR-0014.

---

## 1. Une question est monolingue

La version française et la version arabe d'une même notion sont **deux contenus
éditoriaux distincts**, reliés par un `sibling_group` — pas une question
bilingue à deux colonnes.

Le référentiel CRMEF l'impose : pour l'épreuve de sciences de l'éducation, le
candidat compose dans la langue de son choix, et les deux versions doivent être
rédigées séparément. Des colonnes `stem_fr` / `stem_ar` auraient forcé la
traduction, alors qu'un distracteur pertinent en français ne l'est pas
nécessairement en arabe : les confusions ne portent pas sur les mêmes mots.

**Coût assumé :** le travail éditorial double sur cette épreuve. C'est une
conséquence du concours, pas de notre conception.

## 2. La justification est obligatoire sur chaque option

Y compris sur la bonne réponse. Une contrainte de base refuse une justification
vide.

Sans justification par option, la fonction « Pourquoi pas B ? » n'existe pas, et
une correction se réduit à désigner la bonne réponse — ce que fait n'importe
quel QCM gratuit. C'est la ligne de démarcation du produit ; elle mérite une
contrainte, pas une consigne.

## 3. L'éligibilité au diagnostic est gardée par la base

Fiche F03 : une question ne peut pas être éligible au diagnostic si un seul de
ses distracteurs n'est pas étiqueté par une cause d'erreur.

Un **trigger de contrainte différé** l'impose des deux côtés : quand on rend
une question éligible, et quand on retire une étiquette à une question déjà
éligible. Différé, parce qu'une question et ses options se créent dans la même
transaction : un contrôle immédiat échouerait sur une question encore sans
options.

**Pourquoi en base et non dans un contrôleur.** La fiche F03 le dit : une règle
éditoriale seulement documentée est contournée le premier jour où la production
presse. Le back-office, un import de masse et une commande console passeraient
tous par des chemins différents ; la base est le seul endroit qu'aucun ne
contourne.

## 4. Deux sources, deux rôles

- La source du **blueprint** prouve le périmètre et les poids de l'épreuve.
- Les sources de **contenu** (`question_sources`) fondent la bonne réponse.

Un descriptif officiel ne prouve pas qu'une réponse est juste. Chaque source de
contenu porte son propre statut de vérification, et une question éligible au
diagnostic ou à la simulation exige au moins une source vérifiée.

**Nuance retenue :** l'entraînement libre n'exige pas de source vérifiée. Une
question dont la source reste à confirmer peut circuler à condition d'être
signalée ; une question de diagnostic, non — elle oriente la révision, donc le
temps du candidat.

## 5. Une question publiée ne se modifie pas

Une correction crée une nouvelle version (`version`, `supersedes_id`) et retire
l'ancienne. Les tentatives passées continuent de pointer vers la version
réellement présentée au candidat.

Sans cela, corriger une erreur dans une question rendrait fausses les
corrections déjà affichées à des centaines de candidats — et rendrait tout
historique de progression ininterprétable.

## 6. Le valideur n'est jamais l'auteur

Règle permanente §7.2 de `docs/METHODE.md`, désormais appliquée par un
contrôle. Une relecture par son propre auteur n'est pas une relecture.

## 7. Six statuts éditoriaux

`draft` → `a_verifier` → `reviewed` → `pedagogically_validated` → `published`
→ `retired`.

Seul `published`, non retiré et daté, autorise une présentation au candidat.
Les drapeaux d'éligibilité s'ajoutent par-dessus : publiée ne signifie pas
utilisable en diagnostic.

## Ce que ce lot ne décide pas

Le format de présentation au candidat, la composition des séries, le calcul de
maîtrise. Ce sont les pas suivants — cette couche ne fait qu'établir ce qu'une
question doit contenir pour être digne de confiance.
