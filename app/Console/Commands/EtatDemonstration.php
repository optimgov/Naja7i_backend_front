<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `php artisan naja7i:etat` — ce que la base contient, en clé=valeur.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI UNE COMMANDE PLUTÔT QU'UN `psql` DANS LE SCRIPT
 *
 * `naja7i-demo.sh` doit compter ce qu'il a produit — un installateur qui ne
 * mesure pas son résultat fabrique des verts, exactement comme un test qui ne
 * discrimine pas. La première écriture interrogeait PostgreSQL directement,
 * avec `psql -d naja7i`. Deux raisons de ne pas le faire :
 *
 *   · les identifiants sont dans `.env`, que le script n'a pas à lire — et
 *     qu'il ne DOIT pas lire ;
 *   · l'hôte, le port et l'utilisateur peuvent changer selon l'environnement.
 *     Un script qui suppose « base locale, utilisateur courant » se trompe dès
 *     qu'un conteneur entre en jeu — c'est-à-dire ici.
 *
 * L'application connaît sa propre connexion. On la lui demande.
 *
 * Sortie volontairement plate — `cle=valeur`, une par ligne — pour être lue
 * par un shell sans dépendre d'un analyseur JSON. Le code de sortie vaut 1 si
 * la base est injoignable : un compteur qu'on n'a pas su lire n'est pas un
 * compteur à zéro, et cette distinction est tout l'objet de la commande.
 */
class EtatDemonstration extends Command
{
    protected $signature = 'naja7i:etat';

    protected $description = 'Compte ce que contient la base, pour le script de démonstration';

    public function handle(): int
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->error('base_injoignable='.$e->getMessage());

            return self::FAILURE;
        }

        $compte = fn (string $table, string $ou = '') => (int) DB::table($table)
            ->when($ou !== '', fn ($q) => $q->whereRaw($ou))
            ->count();

        foreach ([
            'filieres' => ['filieres', ''],
            'epreuves' => ['exams', ''],
            'noeuds' => ['competency_nodes', ''],
            'questions_publiees' => ['questions', "status = 'published'"],
            'eligibles_diagnostic' => ['questions', 'eligible_for_diagnostic'],
            'eligibles_simulation' => ['questions', 'eligible_for_simulation'],
            'annales_importees' => ['questions', 'import_ref is not null'],
            'comptes' => ['users', ''],
            'comptes_equipe' => ['users', "email like 'editorial.%@naja7i.test'"],
            'comptes_candidats' => ['users', "email like 'recette.%@naja7i.test'"],
            'offres' => ['plans', ''],
        ] as $cle => [$table, $ou]) {
            $this->line($cle.'='.$compte($table, $ou));
        }

        return self::SUCCESS;
    }
}
