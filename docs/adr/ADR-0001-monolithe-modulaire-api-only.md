# ADR-0001 — Monolithe modulaire Laravel, API-only pour le front-office

**Statut :** accepté · 6 août 2026
**Contexte :** cadrage du 4 août 2026 §9.1 ; NAJAH-BACK-001 v1.3.

## Décision
Une seule application Laravel, trois pools d'exécution (`api`, `admin`, `worker`).
Ce dépôt porte le pool `api` : le front-office candidat, JSON pur, aucun rendu
de page. Le frontend est Nuxt 3 (dépôt séparé) via BFF. Filament (`admin`)
arrive au lot A4 sur `admin.naja7i.ma`.

## Conséquences
- Pas de microservices avant les seuils du cadrage §9.4.
- Application stateless : session/état en Redis, aucune donnée locale d'instance.
- Le contrat OpenAPI (`openapi.yaml`) est l'arbitre du contrat frontend/backend.
