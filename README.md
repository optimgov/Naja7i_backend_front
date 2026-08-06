# Naja7i — Backend front-office

API Laravel du portail candidat de **naja7i.ma** (نجاحي — « ma réussite »),
plateforme marocaine de préparation aux concours. Ce dépôt est le pool `api`
de l'architecture : JSON pur consommé par le frontend Nuxt 3 via BFF.
Le back-office Filament vivra sur `admin.naja7i.ma` (lot A4).

## Principes non négociables

1. **SaaS multi-tenant** : tenant = organisation ; le B2C vit dans le tenant
   plateforme (id 1). Catalogue global, activité isolée. Voir `docs/adr/ADR-0002`.
2. **RBAC par tenant** : rôles globaux, appartenances par tenant, vérification
   toujours dans le tenant courant. Voir `docs/adr/ADR-0003`.
3. **Droits premium côté serveur** : les rôles disent qui vous êtes, les
   `access_grants` (à venir) disent ce que vous avez acheté.
4. **Identifiants** : bigint interne jamais exposé, UUIDv7 public partout.
5. **404, jamais 403**, pour une ressource d'un autre tenant.

## Démarrage

```bash
docker compose up -d
cp .env.example .env   # puis valeurs pgsql/redis (voir PAS-1_INSTRUCTIONS)
php artisan key:generate
php artisan migrate
php artisan test
```

## Références

- Plan backend `NAJAH-BACK-001` v1.3 et backlog `NAJA7i_BACKLOG_ARCHITECTURE_BD_v1_0`
- Cadrage produit du 4 août 2026 · Inventaire `NAJAH-INV-001`
- Journal des pas : `docs/PAS.md`
