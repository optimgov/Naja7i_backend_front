<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Commande conservée comme point de refus explicite pour les anciens modes
 * opératoires. Une question n'est plus jamais supprimée définitivement.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI ELLE EST SUPERSÉDÉE
 *
 * Une réimportation « de zéro » exigeait de libérer `questions.import_ref`,
 * unique y compris pour une question retirée et gelée. La faire sans hard
 * delete supposerait de réécrire cette identité historique, ce qui serait une
 * fausse réimportabilité. Le lot v1.1 conserve donc les lignes existantes et
 * bloque l'ancienne commande sans muter ni `questions` ni
 * `prepared_questions`.
 */
class RetirerLesQuestionsImportees extends Command
{
    protected $signature = 'naja7i:retirer-les-questions-importees';

    protected $description = 'Commande supersédée : les questions importées ne sont jamais supprimées';

    public function handle(): int
    {
        $this->error('commande_supersedee=1');
        $this->line('Aucune question n’a été supprimée ou modifiée.');
        $this->line(
            '`import_ref` reste l’identité unique de la question, y compris après retrait logique ; '
            .'une réimportation de zéro n’est donc pas proposée.'
        );

        return self::FAILURE;
    }
}
