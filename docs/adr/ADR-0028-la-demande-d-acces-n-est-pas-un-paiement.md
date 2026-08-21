# ADR-0028 — La demande d'accès gratuit n'est pas un paiement

**Statut :** proposé révisé · intégré au lot 0A autorisé le 21 août 2026
**Dépend de :** ADR-0025, ADR-0026, ADR-0029

## Décision

La demande d'accès est initiée par un service applicatif propre, **`AccessRequestService`**. Ce service valide la politique de gratuité et l'éligibilité, exige la version affichée, puis crée une entrée non financière dans la table et la file de commandes existantes. Il ne dépend pas de `PaymentGateway` et n'en est pas une implémentation.

Après décision humaine, l'acceptation converge vers `AbonnementService` pour l'octroi des droits. Le refus reste un refus de demande : il ne lève pas `PaiementRefuse`, ne crée aucune transaction et n'emploie aucun vocabulaire de paiement côté candidat.

## Nature non financière

| Aspect | Règle |
|---|---|
| Entrée applicative | `AccessRequestService`, jamais `PaymentGateway` |
| Version | Identifiant de la version affichée, sans substitution silencieuse |
| Montant dû | Nul, car aucune créance n'existe |
| Prix catalogue | Conservé sur la version, sans devenir un montant encaissé |
| Méthode/type | Valeur dédiée `gratuite` ou type non financier équivalent, jamais `coupon` |
| Transaction | Aucune référence ou tentative de paiement |
| Comptabilité et CA | Exclus de tous les agrégats de vente et d'encaissement |
| Erreur de refus | Erreur métier de demande, jamais `PaiementRefuse` |
| Libellé candidat | « Demande d'accès », jamais « paiement », « commande » ou « achat » |

## Politique par version

Chaque version porte une politique versionnée : demande autorisée, fenêtre, plafond éventuel, public éligible, demande déjà en attente, droit actif et redemande après refus. Fermer la politique retire l'action du rendu et fait refuser la route sans redéploiement.

Si la version affichée n'est plus disponible au clic, `AccessRequestService` refuse et demande une actualisation. Une demande déjà créée reste attachée à sa version. L'acceptation compose les droits selon ADR-0029.

## Piège de migration de l'enum PostgreSQL

L'ajout du type non financier à l'enum PostgreSQL existant ne doit pas être exécuté dans la transaction automatique d'une migration Laravel : `ALTER TYPE ... ADD VALUE` est incompatible avec ce mode sur les versions PostgreSQL concernées. La migration doit déclarer **`$withinTransaction = false`** ou être scindée afin que l'ajout de valeur soit exécuté hors transaction. Ce précédent est documenté par DET-16.

La recette de migration vérifie séparément montée, redémarrage applicatif et retour arrière compatible ; elle ne simule jamais la nouvelle valeur par `coupon` si l'ajout échoue.

## Programme experts

Le dispositif ne dispense pas des prérequis documentaires. Les pages Conditions et Confidentialité sont présentes : l'ancien constat SEC-1/404 est fermé. La vérification des octets du 21 août confirme qu'elles rendent des messages i18n en dur et **n'appellent pas** `GET legal/documents` : elles ne rendent donc pas le document versionné contre lequel le backend enregistre `terms_accepted`. Leur contenu, leur statut provisoire, l'information sur les données de test et la date de purge restent à valider avant tout testeur externe réel.

Un octroi direct expert tracé, s'il est autorisé, est un autre geste réservé à une permission dédiée ; il ne transforme pas la demande en paiement.

## Tests minimaux

- aucun appel à `PaymentGateway` pour créer ou refuser une demande ;
- refus : aucune `PaiementRefuse`, transaction ou donnée de CA ;
- version retirée avant création : refus avec actualisation ;
- acceptation : droits exacts de la version et composition ADR-0029 ;
- politique fermée : action absente du rendu et route refusée.

### Tests de mutation

- On route la demande par `PaymentGateway` ou on autorise `coupon` : le test de nature non financière rougit.
- On remplace silencieusement une version indisponible par la courante : le test de version affichée rougit.
- On réactive la transaction Laravel autour de `ALTER TYPE ... ADD VALUE` : le test de migration PostgreSQL rougit.

## Arbitrages encore ouverts

- Nom persistant : `gratuite` ou type plus générique non financier.
- Délai et autorité de redemande après refus.
- Autorisation d'un octroi direct expert tracé ; recommandation : oui, permission dédiée.
