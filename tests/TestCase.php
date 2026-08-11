<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Les migrations créent des types ENUM PostgreSQL (app_locale, user_status,
     * tenant_kind, tenant_status). `migrate:fresh` supprime les tables mais pas
     * les types : sans cette option, la deuxième exécution de la suite échoue
     * sur « type "app_locale" already exists ».
     *
     * RefreshDatabase lit cette propriété pour passer --drop-types.
     */
    protected $dropTypes = true;

    /**
     * LE CATALOGUE EST SEMÉ UNE FOIS PAR PROCESSUS, PAS UNE FOIS PAR TEST.
     *
     * `RefreshDatabase` migre une seule fois puis enveloppe chaque test dans une
     * transaction annulée à la fin. Onze classes rejouaient pourtant
     * `CatalogueSeeder` et `Crmef2025Seeder` dans leur `setUp()` — donc à chaque
     * test, à l'intérieur de la transaction, pour un résultat identique à chaque
     * fois. Mesuré : 0,22 s par test, environ 53 s sur une suite de 249 s.
     *
     * `$seed` fait passer `--seed` à `migrate:fresh`, qui s'exécute AVANT
     * l'ouverture de la transaction et sous la garde `RefreshDatabaseState`.
     * Le catalogue est donc posé une fois et survit à tous les tests ; tout ce
     * qu'un test crée ensuite est annulé comme avant.
     *
     * Ce n'est légitime que parce que ce catalogue est une donnée de RÉFÉRENCE,
     * identique pour tous : filières, familles, épreuves, matrices de domaines.
     * Aucun test ne le modifie — et si l'un venait à le faire, sa modification
     * serait annulée par la transaction comme le reste.
     */
    protected $seed = true;
}
