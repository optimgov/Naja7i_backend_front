<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;

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

    /**
     * AUCUN TEST NE LAISSE L'HORLOGE FIGÉE DERRIÈRE LUI.
     *
     * `travel()` et `travelTo()` posent `Carbon::setTestNow()`. Laravel 12 le
     * remet à zéro dans son `tearDown` — vérifié dans
     * `InteractsWithTestCaseLifecycle`, et sans garde conditionnelle. La fuite
     * entre tests est donc déjà impossible aujourd'hui.
     *
     * CETTE ASSERTION N'EST PAS UNE CHASSE, C'EST UNE GARANTIE. Elle rend la
     * famille entière impossible plutôt que d'en traquer un membre, et surtout
     * elle DÉSIGNE LE TEST FAUTIF au lieu de faire échouer sa victime — un
     * temps figé se paie toujours dans un test ultérieur, qui n'y est pour rien
     * et qu'on accuse à tort. C'est exactement ce qui a coûté trois jours à
     * DET-71 : un symptôme lu dans la mauvaise classe.
     *
     * Elle tourne AVANT le `tearDown` du framework, donc sur l'état que le test
     * laisse réellement. Le jour où une version de Laravel cesse de nettoyer,
     * ou qu'un test appelle `Carbon::setTestNow()` à la main, on l'apprend par
     * son nom.
     */
    protected function tearDown(): void
    {
        $fige = Carbon::hasTestNow();
        $valeur = $fige ? Carbon::getTestNow()?->toIso8601String() : null;

        parent::tearDown();

        if ($fige) {
            $this->fail(
                'Ce test laisse l’horloge figée à '.$valeur.'. `travel()` et '
                .'`travelTo()` doivent être suivis de `travelBack()`, sans quoi '
                .'un test ULTÉRIEUR échouera à sa place. Voir DET-71.'
            );
        }
    }

    /** Compte de contrôle pour les fixtures qui isolent un acteur de relecture. */
    protected function relecteurDeControle(): User
    {
        $compte = User::firstOrCreate(
            ['email' => 'relecteur-de-controle@naja7i.test'],
            ['password' => 'une-phrase-de-passe-solide', 'locale' => 'fr'],
        );

        $compte->markEmailAsVerified();

        $role = Role::where('code', 'expert_pedagogue')->whereNull('tenant_id')->value('id');

        if (! $compte->memberships()->where('role_id', $role)->exists()) {
            $compte->memberships()->create(['role_id' => $role]);
        }

        return $compte->fresh();
    }
}
