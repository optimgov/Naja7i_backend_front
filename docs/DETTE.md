# Fichier de dette technique

| ID | Constat | Origine | Échéance |
|---|---|---|---|
| DET-01 | `Role` n'a pas d'UUID public. Trancher : `Role::code` est-il une exception publique documentée à la règle UUIDv7 ? | Revue PAS-1, R6 | PAS-3 |
| DET-02 | Une requête pour résoudre le tenant plateforme à chaque requête HTTP. Ne pas corriger par un cache statique (ce serait recréer BLOC-2). | Revue PAS-1, P7 | Quand la latence le justifie |
| DET-03 | `users_email_or_phone` ne garantit pas une méthode de connexion utilisable. Invariant applicatif à poser avec les identités sociales. | Revue PAS-1, §4.4 | PAS-3 |
| DET-04 | Anonymisation CNDP vs `cascadeOnDelete` sur `memberships.user_id`. Politique de rétention à écrire. | Revue PAS-1, §4.5 | Lot conformité |
| DET-05 | Aucune vérification n'empêche une future migration de violer la matrice « catalogue global / activité isolée ». | Revue PAS-1, R3 | PAS-5 |
| DET-06 | Normalisation des e-mails et téléphones : chaînes vides encore possibles selon le chemin d'écriture. | Revue PAS-1, §4.4 | PAS-3 |
| DET-07 | **Textes juridiques provisoires.** `legal_documents` contient des placeholders marqués `0.1-provisoire`. La mise en ligne publique est bloquée tant que les textes FR et AR validés par un conseil juridique marocain ne sont pas fournis, avec version et empreinte. | PAS-2, ADR-0005 | **Avant ouverture publique** |
| DET-08 | Qualification juridique des trois actes (contrat / information / consentement) décidée en architecture, non validée par un juriste. | PAS-2, ADR-0005 | Avant ouverture publique |
| DET-09 | Fournisseur d'e-mail non choisi : domaine d'expédition, SPF, DKIM, DMARC, gestion des rebonds. Ne bloque pas le développement, bloque les tests de délivrabilité. | PAS-2, Q4 | Avant pilote |
| DET-10 | Écart NIST assumé (12 caractères au lieu de 15) sans second facteur. À rouvrir quand le MFA existera. | PAS-2, ADR-0007 | Lot sécurité |
| ~~DET-11~~ | ~~Signature des liens de vérification derrière le proxy Nitro.~~ **Clos au PAS-3** : les URL signées sont abandonnées au profit de jetons opaques, précisément parce que la signature ne survit pas au relais (ADR-0008 §4). Le risque n'est plus à tester, il n'existe plus. | PAS-2, D2-12 | clos · PAS-3 |
| ~~DET-12~~ | ~~Le lien de vérification pointe vers l'API et répond du JSON.~~ **Clos au PAS-3** : le lien mène à `/{locale}/verifier-email` sur le frontend, qui poste le jeton à l'API. Vérifié dans Mailpit en FR et en AR. | PAS-2 | clos · PAS-3 |
| DET-13 | `RegisterRequest` s'arrête à la première règle en défaut : le formulaire d'inscription ne peut donc pas signaler plusieurs champs d'un coup. Acceptable sur cinq champs ; à rouvrir si le profil progressif (PAS-4) allonge le formulaire. | PAS-2 | PAS-4 |
| DET-14 | **Envoi synchrone.** Les notifications n'implémentent pas `ShouldQueue` : l'aller-retour SMTP se fait pendant la requête d'inscription. Un fournisseur lent ralentit l'inscription ; un fournisseur en panne la fait échouer en 500 alors que le compte est déjà créé. À basculer sur la file (Redis est en place) en même temps que le choix du fournisseur. | PAS-3 | Avec DET-09 |
| DET-15 | Les endpoints publics `resend` et `password/request` sont limités par adresse e-mail, pas par IP : un appelant qui fait tourner les adresses n'est borné que par `throttle:6,1`. Suffisant tant que l'envoi est peu coûteux ; à revoir quand le fournisseur facturera au message. | PAS-3, ADR-0008 §5 | Avec DET-09 |
