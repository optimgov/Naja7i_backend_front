<?php

namespace App\Console\Commands;

use App\Enums\QuotaPeriodicity;
use App\Enums\QuotaUnit;
use App\Models\Plan;
use App\Models\QuotaProfile;
use App\Services\PlanVersionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bascule ciblée de l'offre gratuite vers le quota v1.1.
 *
 * Aucun seeder n'est rejoué. L'UPDATE traverse le modèle Plan et son service
 * de versionnement : les versions et les octrois déjà accordés restent
 * immuables, seule la projection courante de l'offre change.
 */
final class ActiverDiagnosticGratuitV11 extends Command
{
    private const OFFRE = 'decouverte-gratuite';

    private const PROFIL = 'decouverte-v11-10';

    protected $signature = 'naja7i:activer-diagnostic-gratuit-v1-1
                            {--dry-run : Vérifier et prévisualiser sans rien écrire}';

    protected $description = 'Bascule l’offre gratuite vers le diagnostic v1.1 de dix questions';

    public function handle(PlanVersionService $versions): int
    {
        $profil = QuotaProfile::query()->where('code', self::PROFIL)->first();

        if ($profil === null) {
            $this->error('profil_absent='.self::PROFIL);

            return self::FAILURE;
        }

        if (! $this->profilConforme($profil)) {
            $this->error('profil_non_conforme='.self::PROFIL);
            $this->line('attendu=questions,cumulative_grant,10,10,120,actif');

            return self::FAILURE;
        }

        $offre = Plan::query()->where('code', self::OFFRE)->where('auto_granted', true)->first();

        if ($offre === null) {
            $this->error('offre_absente_ou_non_auto_attribuee='.self::OFFRE);

            return self::FAILURE;
        }

        $courante = $offre->currentVersion()->first();

        if ($courante === null) {
            $this->error('version_courante_absente='.self::OFFRE);

            return self::FAILURE;
        }
        $dejaActive = (int) $offre->quota_profile_id === (int) $profil->id
            && $courante->quota_profile_code === self::PROFIL
            && $courante->quota_value === 10;

        $this->line('offre='.self::OFFRE);
        $this->line("version_avant={$courante->version}");
        $this->line('enveloppe_avant='.($courante->quota_value ?? 'aucune'));
        if ($dejaActive) {
            $this->line('resultat=deja_active');
            $this->line('mode='.($this->option('dry-run') ? 'sec' : 'ecriture'));

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line('profil_cible='.self::PROFIL);
            $this->line('enveloppe_cible=10');
            $this->line('resultat=a_activer');
            $this->line('mode=sec');

            return self::SUCCESS;
        }

        $nouvelle = DB::transaction(function () use ($offre, $profil, $versions) {
            /** @var Plan $verrouillee */
            $verrouillee = Plan::query()->whereKey($offre->id)->lockForUpdate()->firstOrFail();

            if ((int) $verrouillee->quota_profile_id !== (int) $profil->id) {
                $verrouillee->update(['quota_profile_id' => $profil->id]);
            }

            return $versions->current($verrouillee->fresh());
        });

        $this->line("version_apres={$nouvelle->version}");
        $this->line("profil_apres={$nouvelle->quota_profile_code}");
        $this->line("enveloppe_apres={$nouvelle->quota_value}");
        $this->line('resultat=activee');
        $this->line('mode=ecriture');

        return self::SUCCESS;
    }

    private function profilConforme(QuotaProfile $profil): bool
    {
        return $profil->active
            && $profil->unit === QuotaUnit::QUESTIONS
            && $profil->periodicity === QuotaPeriodicity::CUMULATIVE_GRANT
            && $profil->value === 10
            && $profil->min_value === 10
            && $profil->max_value === 120;
    }
}
