<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\DroitTransitoireService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * `php artisan naja7i:poser-le-droit-transitoire` — le geste d'exploitation
 * qui accompagne l'allumage du mur payant (Q-17).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ELLE NE SE LANCE PAS SANS AUTEUR NI SANS MOTIF
 *
 * « Il est posé par un geste d'administration, pas par une migration
 * silencieuse. » Une commande sans auteur serait exactement la migration
 * silencieuse que Q-17 refuse : l'auteur est donc un argument obligatoire, et
 * le motif aussi. Ce que l'écran obtient de la session, la ligne de commande le
 * demande explicitement.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LA PRÉVISUALISATION EST LE MODE PAR DÉFAUT DE LA PRUDENCE
 *
 * `--dry-run` annonce sans écrire. Ce n'est pas une option de confort : Q-17
 * exige une « prévisualisation de l'impact », et une distribution sur toute une
 * population ne se lance pas sur une intuition du nombre.
 */
class PoserLeDroitTransitoire extends Command
{
    protected $signature = 'naja7i:poser-le-droit-transitoire
                            {--auteur= : E-mail du membre du personnel qui pose le geste}
                            {--motif= : Ce que ce geste accompagne, en une phrase}
                            {--duree= : Durée en jours (défaut 60, borné 7–180)}
                            {--offre= : Code de l’offre dont la composition fait référence}
                            {--public= : Code de la catégorie de public visée (défaut : tous)}
                            {--pose-le= : Date de pose (défaut : maintenant)}
                            {--dry-run : Annoncer l’impact sans rien écrire}';

    protected $description = 'Pose le droit transitoire des comptes existants (Q-17), avec sa trace';

    public function handle(DroitTransitoireService $transition, TenantContext $contexte): int
    {
        $contexte->set(Tenant::where('kind', 'platform')->firstOrFail());

        $parametres = [
            'duree' => $this->option('duree') ?? DroitTransitoireService::DUREE_DEFAUT,
            'offre' => $this->option('offre'),
            'public' => $this->option('public'),
            'pose_le' => $this->option('pose-le'),
            'motif' => $this->option('motif'),
        ];

        try {
            $apercu = $transition->previsualiser($parametres);
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        foreach ([
            'offre' => $apercu['offre'],
            'version' => $apercu['version'],
            'capacites' => implode(',', $apercu['capacites']),
            'duree_jours' => $apercu['duree_jours'],
            'public' => $apercu['public'] ?? 'tous',
            'pose_le' => $apercu['pose_le'],
            'fin_prevue' => $apercu['fin_prevue'],
            'comptes_vises' => $apercu['comptes_vises'],
            'deja_porteurs' => $apercu['deja_porteurs'],
            'a_poser' => $apercu['a_poser'],
        ] as $cle => $valeur) {
            $this->line($cle.'='.$valeur);
        }

        if ($this->option('dry-run')) {
            $this->line('mode=sec');

            return self::SUCCESS;
        }

        $auteur = $this->auteur();

        if ($auteur === null) {
            return self::FAILURE;
        }

        try {
            $trace = $transition->poser($auteur, $parametres);
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $this->line('mode=ecriture');
        $this->line('auteur='.$auteur->email);
        $this->line('poses='.$trace->accounts_granted);
        $this->line('deja_porteurs_reels='.$trace->accounts_skipped);
        $this->line('trace='.$trace->uuid);

        return self::SUCCESS;
    }

    private function auteur(): ?User
    {
        $email = (string) $this->option('auteur');

        if ($email === '') {
            $this->error('auteur_absent=1');
            $this->line('Un geste tracé porte le nom de qui le pose : --auteur=<e-mail> est obligatoire hors mode sec.');

            return null;
        }

        $auteur = User::where('email', $email)->first();

        if ($auteur === null) {
            $this->error('auteur_introuvable='.$email);

            return null;
        }

        return $auteur;
    }
}
