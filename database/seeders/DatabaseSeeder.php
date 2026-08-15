<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /*
     * `WithoutModelEvents` — de l'échafaudage Laravel — a été RETIRÉ, et ce
     * n'est pas cosmétique. Nos modèles font leur travail dans les événements :
     *
     *  - `HasPublicUuid` pose l'UUIDv7 public sur `creating` ;
     *  - `CompetencyNode` calcule `depth` et `path` sur `saving` puis `created`,
     *    et refuse les cycles.
     *
     * Muets, l'insertion échoue sur `uuid NOT NULL` — au mieux. Au pire, elle
     * passe et l'arbre de compétences se retrouve sans profondeur ni chemin
     * matérialisé, donc silencieusement faux.
     *
     * Le « Test User » de l'échafaudage a été retiré pour une autre raison : il
     * écrivait une colonne `name` que la table `users` n'a pas (PAS-1), et
     * créait un compte hors de tout contexte tenant.
     */
    public function run(): void
    {
        // L'ordre compte : Crmef2025Seeder corrige et complète ce que
        // CatalogueSeeder a posé (parcours, épreuves séparées, matrices).
        $this->call([CatalogueSeeder::class, Crmef2025Seeder::class, PlansSeeder::class]);
    }
}
