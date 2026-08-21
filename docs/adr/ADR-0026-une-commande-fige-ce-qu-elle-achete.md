# ADR-0026 — Une commande fige ce qu'elle achète

**Statut :** proposé révisé · intégré au lot 0A autorisé le 21 août 2026
**Dépend de :** ADR-0025

**Arbitrage Q-13 : tranché le 21 août 2026.** Le versionnement est automatique
sur tout changement contractuel ; il ne dépend ni d'une case à cocher ni d'une
décision ponctuelle de l'administrateur.

## Décision

Une commande et une demande d'accès référencent l'identifiant de la **version d'offre affichée au candidat**. Cette version est immuable. À l'acceptation, l'octroi lit cette version, jamais l'offre courante.

Une modification contractuelle crée automatiquement une nouvelle version ; l'administrateur ne déclenche pas manuellement le versionnement. Les anciennes versions restent lisibles et ne sont jamais supprimées.

## Table de versionnement champ par champ

| Champ | Versionne ? | Règle |
|---|---:|---|
| Prix catalogue | Oui | Élément contractuel, y compris passage à gratuit ou inversement. |
| Devise | Oui | Modifie le prix promis. |
| Durée du droit | Oui | Modifie la prestation due. |
| Capacités | Oui | Ajout ou retrait d'une autorisation. |
| Quotas : unité, valeur, fenêtre | Oui | Modifie la quantité utilisable. |
| Portée pédagogique | Oui | Matière, chapitre, épreuve ou catalogue couvert. |
| Éligibilité | Oui | Modifie qui peut demander ou recevoir l'offre. |
| Politique d'accès gratuit | Oui | Ouverture, période, plafond, public et règles de redemande. |
| Description commerciale | Oui, toujours | Toute modification versionne mécaniquement, sans jugement sur un changement de sens. |
| Nom commercial | Oui, toujours | Toute modification versionne mécaniquement, sans exception ni case à cocher. |
| Correction éditoriale | Non | Flux distinct, sous permission propre, qui amende la version en place et journalise auteur, date, avant et après. Il ne modifie pas directement les champs contractuels par le flux normal. |
| Note de catalogue | Non | Champ non contractuel séparé pour le texte libre interne ; il ne versionne jamais. |
| Ordre d'affichage | Non | Propriété de catalogue. |
| Mise en avant visuelle | Non | Propriété de catalogue. |
| Retrait de la vente / visibilité | Non | Empêche de nouvelles demandes sans altérer les versions. |
| Données internes et notes d'administration | Non | Non exposées et non contractuelles. |

Le rattachement à une catégorie de public ne versionne pas s'il ne fait que classer le catalogue ; toute modification effective d'éligibilité ou de périmètre versionne.

La distinction est mécanique : le flux normal de modification du nom ou de la description crée toujours une version. Une coquille ne se qualifie jamais par appréciation humaine dans ce flux ; elle passe exclusivement par « correction éditoriale », avec permission et journal dédiés.

## Version affichée et indisponibilité

Le rendu expose un identifiant opaque de version. La création de commande ou de demande exige cet identifiant. Si la version n'est plus disponible pour une nouvelle demande au moment du clic, le serveur refuse avec une erreur métier invitant à actualiser. Il ne remplace jamais silencieusement la version par la version courante.

Une commande déjà créée reste attachée à sa version, même retirée de la vente. Le valideur voit le numéro de version, son statut et le contenu exact accordé.

## Migration et tests

Les commandes historiques sans version reçoivent une version initiale reconstituée, explicitement marquée comme telle.

- modification contractuelle après ouverture d'une commande : l'octroi reste celui de la version demandée ;
- modification non contractuelle : aucune nouvelle version ;
- toute modification du nom ou de la description commerciale : nouvelle version ;
- correction éditoriale : version inchangée et journal avant/après complet ;
- version retirée avant le clic : refus et actualisation, sans substitution ;
- version retirée après création : décision possible sur la version figée.

### Tests de mutation

- On autorise la modification directe de la description sans versionner : le test « toute description versionne » rougit.
- On fait relire l'offre courante à l'octroi au lieu de la version demandée : le test de commande figée rougit.

## Arbitrages encore ouverts

- Durée de conservation du journal des corrections éditoriales non versionnantes.
- Libellé candidat de l'erreur « version indisponible, actualisez » en FR/AR.
