# ADR-0008 — Envoi des e-mails : fournisseur paramétrable, jetons opaques

**Statut :** accepté · 7 août 2026
**Contexte :** PAS-3. Exigence OptimGov : le fournisseur doit être paramétrable.

## Décision

### 1. Le fournisseur est une variable d'environnement, pas un choix de code

`MAIL_MAILER` et les identifiants SMTP vivent dans `.env`. Changer de
prestataire est un redéploiement, pas une modification de code. Mailpit en
développement, fournisseur réel en production.

### 2. Le domaine d'expédition est le nôtre

`no-reply@naja7i.ma`, jamais un sous-domaine du prestataire. La réputation
d'expéditeur se construit sur des années : elle doit nous appartenir et
survivre à un changement de fournisseur. SPF, DKIM et DMARC seront posés sur
`naja7i.ma` (DET-09).

### 3. Les webhooks de rebond passeront par une couche de traduction

C'est là que les fournisseurs divergent réellement : chacun a son format de
notification pour un rebond, une plainte ou une désinscription. Ces formats ne
doivent jamais atteindre le code métier. Quand les webhooks seront branchés,
un adaptateur par fournisseur traduira vers nos propres événements internes.
Sans cette précaution, « SMTP paramétrable » serait une illusion : on resterait
couplé au prestataire par ses notifications.

### 4. Jetons opaques, pas d'URL signées

Les URL signées de Laravel calculent leur signature sur l'URL complète. Notre
topologie est BFF : le lien reçu pointe vers `www.naja7i.ma`, et Nitro relaie
en interne vers un hôte différent. La signature émise ne correspond alors plus
à l'URL vue à la validation. Le symptôme n'apparaît qu'en environnement
proxifié — donc jamais en test local, et toujours en production.

On émet donc un jeton aléatoire de 64 caractères. Le lien mène au frontend,
qui poste le jeton à l'API. Aucune dépendance à l'URL.

Le jeton est stocké **haché en SHA-256** : une fuite de la base ne permet pas
de valider les comptes en attente. Un seul jeton actif à la fois par usage :
un nouvel envoi invalide le précédent, sinon un lien intercepté resterait
utilisable après que le candidat en a redemandé un.

### 5. Les endpoints publics ne révèlent jamais l'existence d'un compte

`resend` et `password/request` répondent 202 avec le même message, que
l'adresse soit inscrite ou non. Ils sont limités à trois appels par quart
d'heure et par adresse : ce sont des endpoints qui envoient un e-mail à une
adresse fournie par l'appelant, donc des outils de harcèlement potentiels s'ils
ne sont pas bornés.

## Conséquences

- Le frontend Nuxt doit exposer `/{locale}/verifier-email` et
  `/{locale}/nouveau-mot-de-passe`, qui lisent le jeton dans l'URL et le
  postent à l'API.
- La réinitialisation régénère le `remember_token` : les sessions « rester
  connecté » ouvertes ailleurs sont invalidées. C'est voulu — un mot de passe
  oublié peut signifier un compte compromis.
