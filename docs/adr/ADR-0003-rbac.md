# ADR-0003 — RBAC : rôles globaux, appartenances par tenant

**Statut :** accepté · 6 août 2026
**Contexte :** NAJAH-BACK-001 v1.3 §1.4, §8.4 ; cadrage §10.2.

## Décision
- `roles` est un **référentiel global** en table (pas un enum PostgreSQL) :
  sept rôles seedés par migration — candidat, auteur, réviseur, éditeur,
  support, finance, super_admin — extensibles en permissions fines sans
  migration de type.
- `memberships` (user × tenant × role, UNIQUE) est le **seul** lien entre un
  utilisateur et un tenant. Un compte est global ; il peut être candidat sur
  la plateforme et formateur dans un centre sans duplication.
- Toute vérification de rôle (`hasRole`) s'évalue **dans le tenant courant**,
  jamais globalement.
- Les rôles `is_staff` exigeront MFA TOTP et domaine admin séparé (lot A4).
- L'autorisation d'accès premium n'est PAS portée par les rôles : elle viendra
  des `access_grants` (lot A7). Rôle = qui vous êtes ; grant = ce que vous
  avez acheté.

## Conséquences
Les policies Laravel s'appuieront sur `hasRole()` + grants ; aucun contrôle
d'accès ne repose sur le frontend.
