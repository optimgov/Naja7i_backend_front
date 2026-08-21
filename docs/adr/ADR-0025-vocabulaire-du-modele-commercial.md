# ADR-0025 — Le vocabulaire du modèle commercial

**Statut :** proposé révisé · intégré au lot 0A autorisé le 21 août 2026
**Contexte :** cadrage consolidé v1.2, lot 0A
**Étend :** ADR-0013 · **Dépend de :** ADR-0026 à ADR-0029

## Décision

Le modèle commercial emploie exactement sept termes.

1. **Compte** — personne authentifiée (`User`), candidate ou membre du personnel. Un compte peut exister sans droit.
2. **Offre** — proposition administrable au catalogue : identité, présentation, public et versions. Elle n'est pas ce que le compte possède.
3. **Version d'offre** — état contractuel immuable et affichable d'une offre. La commande ou la demande référence cette version, jamais l'offre courante.
4. **Capacité** — autorisation binaire, issue d'une liste fermée en code. Elle répond à « ai-je le droit ? », non à « combien ? ».
5. **Quota** — limite chiffrée et typée sur un usage, avec unité, valeur, portée et fenêtre. Il borne un usage autorisé sans devenir une capacité.
6. **Droit** — autorité effective détenue par un compte : capacité, portée, début, fin éventuelle et origine, matérialisée par `AccessGrantRecord`.
7. **Demande d'accès** — sollicitation non financière d'un droit, soumise à décision humaine et portée par `AccessRequestService`.

La chaîne normative est :

> Une offre propose. Une version fige. Une commande ou une demande référence la version affichée. Une décision produit des droits. Les droits seuls autorisent. Les compteurs de quota bornent leur consommation.

## Le porteur du gratuit

Le gratuit n'est ni l'absence d'offre, ni un réglage global, ni un paiement nul. Il est porté par une **offre gratuite versionnée et auto-attribuée**. Cette version porte ses capacités, son périmètre et son quota de questions. Son attribution crée des droits et une enveloppe de consommation explicites par la chaîne normale.

- changer le quota ou une autre promesse contractuelle publie automatiquement une nouvelle version gratuite ;
- les nouveaux comptes reçoivent la version gratuite alors en vigueur ;
- les comptes existants conservent leurs droits et leur enveloppe ;
- leur migration est un geste administratif explicite, prévisualisé et tracé ;
- aucune modification ne réduit rétroactivement un droit déjà accordé.

Le quota F03 existant demeure un **quota global par compte de causes révélées**, cumulatif à vie selon la fiche F03 en vigueur. Il reste distinct du futur quota de questions et n'est ni remplacé ni dérivé par lui.

## Capacités commercialisables

Une capacité présente dans le code n'est pas automatiquement vendable. La liste commercialisable est fermée en code. `CERTIFICATION` reste non commercialisable tant que la fonction et sa règle métier ne sont pas livrées. Les trois paliers doivent pouvoir distinguer au minimum l'accès aux annales/séries, la carte de maîtrise, l'ordonnance, le rendez-vous mémoire et le coaching automatique complet ; les capacités fines autorisent, le regroupement « coaching » présente l'offre.

## Conséquences

- Aucun objet unique « abonnement » ne fusionne offre, commande et droit.
- Capacités et quotas ne sont pas stockés dans un JSON libre commun.
- Le mur de droits lit les droits et compteurs, jamais le catalogue ni les commandes.
- Les compositions de droits concurrents relèvent d'ADR-0029.

## Tests de mutation

- On fait autoriser un écran depuis une commande ou une offre au lieu des droits effectifs : le test d'autorité unique rougit.
- On remplace le quota de questions par le quota F03 de causes : le test de séparation des unités rougit.
- On auto-attribue le gratuit sans version d'offre ni origine de droit : le test du porteur explicite rougit.

## Arbitrages encore ouverts

- Noms techniques et libellés FR/AR définitifs des capacités commercialisables.
- Capacité agrégée `COACHING_AUTOMATIQUE_COMPLET` éventuelle ou regroupement commercial sans capacité agrégée.
