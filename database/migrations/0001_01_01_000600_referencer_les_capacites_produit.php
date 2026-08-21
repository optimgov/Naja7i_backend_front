<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capability_definitions', function (Blueprint $table) {
            $table->string('code', 64)->primary();
            $table->string('label_fr');
            $table->string('label_ar');
            $table->text('description_fr');
            $table->text('description_ar');
            $table->boolean('a_relire')->default(true);
            $table->unsignedSmallInteger('position');
            $table->timestampsTz();

            $table->unique('position');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE capability_definitions
            ADD CONSTRAINT capability_definitions_code_known CHECK (code IN (
                'questions.answer',
                'corrections.cause',
                'annales.practice',
                'series.targeted',
                'simulator.full',
                'mastery.detail',
                'remediation.plan',
                'memory.sessions',
                'certification.take'
            ))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE capability_definitions
            ADD CONSTRAINT capability_definitions_bilingual_complete CHECK (
                btrim(label_fr) <> '' AND btrim(label_ar) <> ''
                AND btrim(description_fr) <> '' AND btrim(description_ar) <> ''
            )
        SQL);

        $now = now();

        DB::table('capability_definitions')->insert([
            $this->definition('questions.answer', 'Répondre aux questions', 'الإجابة عن الأسئلة', 'Permet de recevoir et traiter des questions de la banque.', 'يتيح تلقي أسئلة بنك الأسئلة والإجابة عنها.', 10, $now),
            $this->definition('corrections.cause', 'Causes d’erreur détaillées', 'أسباب الأخطاء بالتفصيل', 'Révèle sans limite la cause pédagogique associée à une erreur.', 'يكشف دون حد السبب التربوي المرتبط بالخطأ.', 20, $now),
            $this->definition('annales.practice', 'Entraînement sur les annales', 'التدرب على الامتحانات السابقة', 'Donne accès aux questions issues d’anciens sujets lorsque leur origine est fiable.', 'يتيح أسئلة الامتحانات السابقة عندما يكون مصدرها موثوقا.', 30, $now),
            $this->definition('series.targeted', 'Entraînement ciblé', 'تدريب موجّه', 'Permet de démarrer une série sur un domaine choisi.', 'يتيح بدء سلسلة تدريب في مجال مختار.', 40, $now),
            $this->definition('simulator.full', 'Examen blanc complet', 'امتحان تجريبي كامل', 'Permet de démarrer un examen blanc composé et chronométré.', 'يتيح بدء امتحان تجريبي متكامل ومحدد الزمن.', 50, $now),
            $this->definition('mastery.detail', 'Maîtrise détaillée', 'تفاصيل مستوى الإتقان', 'Affiche la maîtrise au-delà de la synthèse générale, notamment par matière et chapitre.', 'يعرض مستوى الإتقان بالتفصيل، خاصة حسب المادة والفصل.', 60, $now),
            $this->definition('remediation.plan', 'Plan de remédiation', 'خطة المعالجة', 'Affiche les priorités de remédiation et leurs motifs pédagogiques.', 'يعرض أولويات المعالجة وأسبابها التربوية.', 70, $now),
            $this->definition('memory.sessions', 'Séances de mémorisation', 'جلسات التثبيت', 'Affiche les échéances de révision et permet d’ouvrir une séance mémoire.', 'يعرض مواعيد المراجعة ويتيح بدء جلسة تثبيت.', 80, $now),
            $this->definition('certification.take', 'Attestation de niveau', 'شهادة المستوى', 'Permettra de passer une attestation lorsque cette fonction sera livrée.', 'سيتيح اجتياز شهادة المستوى عند إطلاق هذه الوظيفة.', 90, $now),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('capability_definitions');
    }

    /** @return array<string, mixed> */
    private function definition(
        string $code,
        string $labelFr,
        string $labelAr,
        string $descriptionFr,
        string $descriptionAr,
        int $position,
        mixed $now,
    ): array {
        return [
            'code' => $code,
            'label_fr' => $labelFr,
            'label_ar' => $labelAr,
            'description_fr' => $descriptionFr,
            'description_ar' => $descriptionAr,
            'a_relire' => true,
            'position' => $position,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
};
