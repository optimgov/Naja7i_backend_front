<?php

namespace App\Console\Commands;

use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Question;
use App\Models\Remediation;
use App\Models\Source;
use App\Models\User;
use App\Services\DiagnosticComposer;
use App\Services\QuestionAuthoringService;
use App\Services\QuestionTransitionService;
use App\Services\SourceVerificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Installe le contenu de validation fonctionnelle CRMEF en préproduction.
 *
 * Le préfixe TEST-CRMEF-V1, la provenance ai_assisted et la source dédiée
 * rendent ce lot impossible à confondre avec une annale ou un corrigé expert.
 * La commande est rejouable et refuse explicitement l'environnement production.
 */
class PeuplerBanqueTestCrmef extends Command
{
    protected $signature = 'naja7i:peupler-banque-test-crmef {--dry-run : Vérifie le référentiel sans écrire}';

    protected $description = 'Peuple les trois épreuves CRMEF modélisées avec une banque de test traçable.';

    public function handle(
        QuestionAuthoringService $authoring,
        QuestionTransitionService $transitions,
        SourceVerificationService $verification,
        DiagnosticComposer $composer,
    ): int {
        if (! app()->environment(['local', 'testing', 'staging'])) {
            $this->error('Refus : la banque TEST-CRMEF-V1 est réservée aux environnements local, testing et staging.');

            return self::FAILURE;
        }

        /** @var list<array{ref:string,exam:string,node:string,locale:string,stem:string,correct:string,distractors:list<string>,explanation:string}> $questions */
        $questions = require database_path('data/banque_test_crmef.php');
        $this->verifierLot($questions);

        if ($this->option('dry-run')) {
            $this->info(count($questions).' questions valides dans le lot ; aucune écriture.');

            return self::SUCCESS;
        }

        $creees = DB::transaction(function () use ($questions, $authoring, $transitions, $verification): int {
            $acteur = User::firstOrCreate(
                ['email' => 'contenu-test-crmef@naja7i.invalid'],
                ['password' => Str::password(64), 'locale' => 'fr', 'status' => 'active'],
            );
            $acteur->forceFill(['email_verified_at' => $acteur->email_verified_at ?? now()])->save();

            $source = Source::firstOrCreate(
                ['code' => 'TEST-CRMEF-V1'],
                [
                    'kind' => 'autre',
                    'title_fr' => 'Banque assistée par IA — validation fonctionnelle CRMEF V1',
                    'title_ar' => 'بنك بمساعدة الذكاء الاصطناعي — اختبار وظيفي CRMEF V1',
                    'authority_fr' => 'Naja7i — préproduction uniquement',
                    'authority_ar' => 'نجاحي — بيئة الاختبار فقط',
                    'session_label' => 'Préproduction 2026-08',
                    'languages' => ['fr', 'ar'],
                    'location_note_fr' => 'Contenu synthétique destiné à tester les parcours. Ni annale ni corrigé officiel.',
                    'location_note_ar' => 'محتوى تركيبي لاختبار المسارات، وليس موضوعا أو تصحيحا رسميا.',
                ],
            );
            if (! $source->estVerifiee()) {
                $source = $verification->verifier($source, $acteur)['source'];
            }

            $creees = 0;
            foreach ($questions as $donnees) {
                if (Question::where('import_ref', $donnees['ref'])->exists()) {
                    continue;
                }

                $exam = Exam::where('code', $donnees['exam'])->firstOrFail();
                $node = CompetencyNode::where('exam_id', $exam->id)
                    ->where('code', $donnees['node'])
                    ->firstOrFail();

                $remediation = Remediation::firstOrCreate(
                    [
                        'competency_node_id' => $node->id,
                        'locale' => $donnees['locale'],
                        'title' => $donnees['locale'] === 'ar'
                            ? '[اختبار] مراجعة '.($node->name_ar ?: $node->name_fr)
                            : '[Test] Revoir '.$node->name_fr,
                    ],
                    [
                        'content' => $donnees['explanation'].' '.($donnees['locale'] === 'ar'
                            ? 'أعد صياغة القاعدة، ثم طبقها على مثال جديد قبل إعادة المحاولة.'
                            : 'Reformulez la règle, puis appliquez-la à un nouvel exemple avant de réessayer.'),
                        'estimated_minutes' => 8,
                        'status' => 'published',
                    ],
                );

                $options = [[
                    'content' => $donnees['correct'],
                    'is_correct' => true,
                    'rationale' => $donnees['explanation'],
                ]];
                foreach ($donnees['distractors'] as $distracteur) {
                    $options[] = [
                        'content' => $distracteur,
                        'is_correct' => false,
                        'rationale' => $donnees['locale'] === 'ar'
                            ? 'هذا الاختيار لا يطابق المفهوم أو القاعدة المطلوبة في السؤال.'
                            : 'Cette proposition ne correspond pas au concept ou à la règle demandée.',
                        'cause' => 'confusion_notions',
                    ];
                }

                $question = $authoring->rediger($acteur, [
                    'exam_id' => $exam->id,
                    'competency_node_id' => $node->id,
                    'locale' => $donnees['locale'],
                    'stem' => $donnees['stem'],
                    'explanation' => $donnees['explanation'],
                    'difficulty' => 2,
                    'remediation_id' => $remediation->id,
                    'authoring' => 'ai_assisted',
                    'import_ref' => $donnees['ref'],
                    'import_note' => 'Préproduction uniquement — contenu synthétique assisté par IA, non officiel.',
                ], $options, $source, $donnees['ref']);

                $question = $transitions->submitForReview($question);
                $question = $transitions->markReviewed($question, $acteur);
                $question = $transitions->validate($question, $acteur);
                $transitions->publish($question, forDiagnostic: true);
                $creees++;
            }

            return $creees;
        });

        foreach ([
            ['CRMEF-SE-2025', 'fr'],
            ['CRMEF-SE-2025', 'ar'],
            ['CRMEF-FR-DID-2025', 'fr'],
            ['CRMEF-FR-SPEC-2025', 'fr'],
        ] as [$code, $locale]) {
            $exam = Exam::where('code', $code)->firstOrFail();
            if (! $composer->isReady($exam, $locale)) {
                throw new RuntimeException("La banque reste insuffisante pour {$code}/{$locale} après peuplement.");
            }
        }

        $this->info("Banque CRMEF prête : {$creees} question(s) créée(s), lot rejouable sans doublon.");

        return self::SUCCESS;
    }

    /**
     * @param  list<array{ref:string,exam:string,node:string,locale:string,stem:string,correct:string,distractors:list<string>,explanation:string}>  $questions
     */
    private function verifierLot(array $questions): void
    {
        $attendus = [
            'CRMEF-SE-2025:fr' => 10,
            'CRMEF-SE-2025:ar' => 10,
            'CRMEF-FR-DID-2025:fr' => 10,
            'CRMEF-FR-SPEC-2025:fr' => 10,
        ];
        $comptes = [];
        $noeuds = [];

        foreach ($questions as $question) {
            $cle = $question['exam'].':'.$question['locale'];
            $comptes[$cle] = ($comptes[$cle] ?? 0) + 1;
            $noeuds[$question['exam']][$question['node']] = true;

            $nombreOptions = 1 + count($question['distractors']);
            $attendu = $question['exam'] === 'CRMEF-SE-2025' ? 5 : 4;
            if ($nombreOptions !== $attendu) {
                throw new RuntimeException("{$question['ref']} porte {$nombreOptions} options, {$attendu} attendues.");
            }
        }

        foreach ($attendus as $cle => $minimum) {
            if (($comptes[$cle] ?? 0) < $minimum) {
                throw new RuntimeException("Lot incomplet pour {$cle} : {$minimum} questions au minimum.");
            }
        }

        foreach (['CRMEF-SE-2025' => 6, 'CRMEF-FR-DID-2025' => 9, 'CRMEF-FR-SPEC-2025' => 10] as $exam => $minimum) {
            if (count($noeuds[$exam] ?? []) !== $minimum) {
                throw new RuntimeException("Tous les nœuds de {$exam} doivent être couverts.");
            }
        }
    }
}
