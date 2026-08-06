# ADR-0002 — Tenancy : tenant = organisation, plateforme = tenant 1

**Statut :** accepté · 6 août 2026
**Contexte :** NAJAH-BACK-001 v1.3 §1 ; cadrage, décision « multi-tenant ».

## Décision
- Un tenant est une **organisation** (centre partenaire). Jamais un concours,
  une spécialité, une session ou une AREF.
- Un tenant technique unique `platform` (id = 1, unicité garantie par index
  partiel) héberge tout le B2C au lancement. Il est créé par migration,
  pas par seeder : aucune installation n'existe sans lui.
- Le catalogue (concours, compétences, questions, blueprints) est **global** —
  aucune de ses tables ne portera `tenant_id`. Les tables d'activité et
  d'appartenance en portent un, obligatoirement, en tête d'index composé.
- Isolation applicative par scope global (`BelongsToTenant`) : requête sans
  tenant résolu → exception ; échappement uniquement via
  `acrossAllTenants(raison)`, journalisé.
- Ressource d'un autre tenant → **404, jamais 403**.
- Row-Level Security PostgreSQL : différée au gate formel « premier partenaire
  B2B activé », comme la résolution dynamique de tenant.

## Conséquences
Tout le code métier s'écrit dès maintenant contre `TenantContext` ; le passage
au B2B ne modifiera que le middleware `ResolveTenant`.
