# ADR-0006 — Isolation des écritures, contexte scoped, bypass unique

**Statut :** accepté · 7 août 2026 — remplace partiellement ADR-0002
**Contexte :** revue externe du commit `c0ea420` (PAS-1), bloquants 1 à 3.

## Problème

L'ADR-0002 annonçait trois garanties que le code ne tenait pas :

1. **« Isolation par scope global »** ne couvrait que les *lectures* Eloquent.
   `tenant_id` figurait dans `$fillable` et le hook `creating` ne remplaçait la
   valeur que si elle était absente : une création pouvait viser un autre
   tenant. Pire, `Model::where(...)->update(['tenant_id' => X])` ne déclenche
   aucun événement de modèle et déplaçait silencieusement une ligne.

2. **« Requête sans tenant résolu → exception »** reposait sur une propriété
   statique. Sous Octane ou dans un worker de queue, le processus survit d'une
   exécution à l'autre : la garantie devenait « aucun *nouveau* tenant →
   réutilisation silencieuse du précédent ». Aucune erreur, aucune alerte.

3. **« Échappement uniquement via `acrossAllTenants()`, journalisé »** était une
   convention. `withoutGlobalScope('tenant')` restait accessible et muet.

C'est le mode de défaillance documenté du projet (écarts X01–X12) : une règle
énoncée dans un document et non exécutée par le code.

## Décision

- **`TenantContext` devient un service en binding `scoped`**, réinitialisé par
  le conteneur à chaque cycle de requête et à chaque job. Plus aucun état
  statique. Le middleware libère le contexte dans un `finally`.
- **Les jobs transportent leur tenant** via le trait `InteractsWithTenant` et
  le résolvent eux-mêmes. Un job n'hérite jamais du contexte du précédent.
- **`tenant_id` n'est jamais assignable** : le trait le retire de l'assignation
  de masse et refuse toute valeur divergente à la création.
- **Une ligne ne change jamais de tenant** : `updating` refuse
  `isDirty('tenant_id')`, et `TenantAwareBuilder` intercepte les mises à jour
  massives et les `upsert` — les chemins que les événements ne couvrent pas.
- **`TenantBypass::run($raison, $callback)` est le seul point de sortie**, avec
  raison d'au moins dix caractères et journal corrélé (acteur, request_id,
  tenant courant). Le builder refuse tout autre retrait de scope.
- **Un test architectural** (`TenancyArchitectureTest`) échoue la CI si un
  contournement apparaît hors liste blanche.
- **`grantCandidateRole()` exige le contexte plateforme** au lieu de forcer
  `tenant_id = 1` en contradiction avec le scope actif.
- **Le tenant plateforme est protégé par trigger PostgreSQL** : ni supprimable,
  ni transformable ; aucun autre tenant ne peut être promu plateforme.

## Ce que cela ne garantit toujours pas

Ces protections couvrent Eloquent. Un `DB::statement()` ou un accès SQL direct
reste physiquement possible : le test architectural l'interdit au niveau du
code, mais seule la **Row-Level Security** PostgreSQL le fermerait au niveau de
la base. Elle reste différée au gate « premier partenaire B2B », comme prévu —
tant que tout le B2C vit dans un tenant unique, l'exposition réelle est nulle.
Cette limite est assumée et documentée, pas ignorée.

## Conséquence sur la formulation de R4

La règle R4 doit être énoncée exactement :

> Toute opération Eloquent — lecture **et** écriture — sur un modèle portant
> `BelongsToTenant` exige un contexte tenant résolu, et ne peut viser que ce
> tenant.

Et non « toute opération sur une table isolée », qui inclurait le SQL brut.
