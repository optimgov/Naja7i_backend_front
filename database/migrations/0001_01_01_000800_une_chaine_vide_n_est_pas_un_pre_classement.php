<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Réparation — une chaîne vide n'est pas un pré-classement.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE L'IMPORT DU 24 AOÛT A LAISSÉ
 *
 * Le corpus écrit `domaine_code: ""` — chaîne vide, pas `null` — pour les 32
 * questions SE que le classement du 15 août n'a pas tranchées. La première
 * version de `ImporterLeCorpusQcm::provisoires()` les recopiait telles quelles.
 *
 * Le RAPPORT de la commande était juste : `filled()` voit bien la chaîne vide,
 * et l'import a bien annoncé « pre_classees=213, a_qualifier=32 ». C'est le
 * STOCKAGE qui mentait : en base, les 245 lignes portaient un `domaine_code`
 * de type `string`. Un écran de qualification filtrant « domaine_code
 * renseigné » en aurait compté 245, et l'expert aurait cru qu'un script avait
 * déjà tranché là où personne n'a rien dit.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI RETIRER LA CLÉ PLUTÔT QUE LA METTRE À `null`
 *
 * `provisional` est un sac d'aides facultatives. Une clé absente s'y lit
 * « personne n'a rien proposé » sans ambiguïté ; une clé à `null` demande au
 * lecteur de savoir que `null` et l'absence veulent dire la même chose. Le
 * moins de conventions à retenir, le mieux.
 *
 * La commande ne produit plus ces clés vides. Cette migration ne répare que ce
 * qui est déjà en base, et elle est sans effet sur une base neuve.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* IDEMPOTENTE ET BORNÉE : ne touche que les clés dont la valeur est la
         * chaîne vide, une par une. Une valeur réelle n'est jamais atteinte. */
        foreach (['domaine_code', 'domaine_confiance', 'domaine_motif', 'arbre_cible'] as $cle) {
            DB::statement(
                'UPDATE prepared_questions
                    SET provisional = provisional - ?
                  WHERE provisional ->> ? = \'\'',
                [$cle, $cle]
            );
        }
    }

    public function down(): void
    {
        /* On ne remet pas des chaînes vides : l'information retirée était
         * l'absence d'information. Le retour arrière n'a rien à reconstruire,
         * et fabriquer des clés vides recréerait le défaut. */
    }
};
