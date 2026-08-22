<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OffreGratuiteService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * `php artisan naja7i:rattraper-le-gratuit` — poser le palier gratuit sur les
 * comptes antérieurs à son existence.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * UNE COMMANDE, PAS UNE MIGRATION
 *
 * Une migration de schéma qui distribue des droits mélange deux choses qui ne
 * se rejouent pas au même rythme : la forme de la base, qui se déploie une
 * fois, et une décision d'exploitation, qui se prend un jour donné, se
 * prévisualise, et se relance si elle a été interrompue. Le rattrapage est la
 * seconde. Il est livré TESTÉ et n'est exécuté sur aucune base durable par le
 * lot qui le livre : personne ne perd rien aujourd'hui — aucun mur n'existe
 * encore — et son heure viendra à l'allumage de 3A.9.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * IDEMPOTENTE, DONC REJOUABLE APRÈS UNE INTERRUPTION
 *
 * Chaque compte est examiné par `OffreGratuiteService`, qui refuse de poser un
 * second droit gratuit — quelle que soit la version dont le premier venait. Une
 * commande interrompue à mi-chemin se relance sans double distribution, et sans
 * qu'on ait à savoir où elle s'était arrêtée.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * L'ORIGINE DIT QUAND, PAS SEULEMENT QUOI
 *
 * Ces droits portent `rattrapage` et non `account_level` : ils n'ont pas été
 * posés à l'inscription mais des mois après, par une décision d'exploitation.
 * Un audit doit pouvoir les distinguer sans deviner — c'est la même exigence
 * que pour le droit transitoire.
 */
class RattraperLeGratuit extends Command
{
    protected $signature = 'naja7i:rattraper-le-gratuit
                            {--dry-run : Compter sans rien écrire}';

    protected $description = 'Pose le palier gratuit sur les comptes candidats qui ne le portent pas';

    public function handle(OffreGratuiteService $gratuite, TenantContext $contexte): int
    {
        /* Les comptes candidats sont des objets de la PLATEFORME : le contexte
         * doit l'être aussi, sinon la lecture des adhésions ne voit rien. */
        $contexte->set(Tenant::where('kind', 'platform')->firstOrFail());

        $offre = $gratuite->porteuse();

        if ($offre === null) {
            $this->error('aucune_offre_gratuite=1');
            $this->line('Aucune offre auto-attribuée : il n’y a rien à rattraper.');

            return self::FAILURE;
        }

        $version = $offre->currentVersion()->firstOrFail();
        $sec = (bool) $this->option('dry-run');
        $roleCandidat = Role::where('code', 'candidat')->whereNull('tenant_id')->value('id');

        $poses = 0;
        $dejaPorteurs = 0;

        User::query()
            ->whereHas('memberships', fn ($q) => $q->where('role_id', $roleCandidat))
            ->orderBy('id')
            ->chunkById(200, function ($comptes) use ($gratuite, $offre, $sec, &$poses, &$dejaPorteurs): void {
                foreach ($comptes as $compte) {
                    if ($gratuite->porteDejaLeGratuit($compte, $offre)) {
                        $dejaPorteurs++;

                        continue;
                    }

                    /* En sec, on COMPTE ce qui serait posé — sans l'écrire. Une
                     * distribution de droits se prévisualise avant de se faire
                     * (ADR-0025 : « geste explicite, prévisualisé et tracé »). */
                    if ($sec || $gratuite->attribuer($compte, OffreGratuiteService::ORIGINE_RATTRAPAGE)) {
                        $poses++;
                    }
                }
            });

        $this->line('offre='.$offre->code);
        $this->line('version='.$version->version);
        $this->line('enveloppe='.($version->quota_value ?? 'aucune'));
        $this->line('poses='.$poses);
        $this->line('deja_porteurs='.$dejaPorteurs);
        $this->line('examines='.($poses + $dejaPorteurs));
        $this->line('mode='.($sec ? 'sec' : 'ecriture'));

        return self::SUCCESS;
    }
}
