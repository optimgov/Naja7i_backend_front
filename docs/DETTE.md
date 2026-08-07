# Fichier de dette technique

Constats mineurs relevés en revue, acceptés et différés — avec leur échéance.
Un élément n'entre ici que s'il ne bloque ni la sécurité ni un lot en cours.

| ID | Constat | Origine | Échéance |
|---|---|---|---|
| DET-01 | `Role` n'a pas d'UUID public. À trancher : `Role::code` est-il une exception publique documentée à la règle UUIDv7 ? | Revue PAS-1, R6 | PAS-2 |
| DET-02 | Une requête pour résoudre le tenant plateforme à chaque requête HTTP. Ne pas corriger par un cache statique (ce serait réintroduire BLOC-2) ; un mémo par scope de requête suffira. | Revue PAS-1, P7 | Quand la latence le justifie |
| DET-03 | `users_email_or_phone` ne garantit pas une méthode de connexion utilisable (compte avec e-mail mais sans mot de passe ni identité sociale). Invariant applicatif à poser avec les identités sociales. | Revue PAS-1, §4.4 | PAS-3 |
| DET-04 | Anonymisation CNDP vs `cascadeOnDelete` sur `memberships.user_id` : la contrainte email/téléphone refuse un compte anonymisé, et la cascade détruirait l'historique. Politique de rétention à écrire. | Revue PAS-1, §4.5 | Lot conformité |
| DET-05 | Aucune vérification empêche une future migration de violer la matrice « catalogue global / activité isolée ». Test architectural à étendre au schéma. | Revue PAS-1, R3 | PAS-5 (catalogue) |
| DET-06 | Chaînes vides possibles en e-mail/téléphone selon le chemin d'écriture ; normalisation à imposer en amont. | Revue PAS-1, §4.4 | PAS-2 |
