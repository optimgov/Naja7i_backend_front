<?php

namespace Database\Seeders;

use App\Models\CompetencyNode;
use App\Models\ExamFamily;
use App\Models\ExamSession;
use App\Models\Filiere;
use App\Models\Specialty;
use App\Models\TaxonomyProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catalogue initial — reprend la structure validée dans le prototype et le
 * vocabulaire fixé par l'ADR-0013.
 *
 * ATTENTION : les DATES de session sont volontairement marquées
 * `dates_confirmed = false`. Aucune date de ce fichier n'a été vérifiée sur
 * une source officielle. Elles servent à faire fonctionner le calendrier en
 * développement, pas à informer un candidat. Le back-office devra les
 * remplacer par des dates sourcées avant toute ouverture publique.
 */
class CatalogueSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $education = $this->filiere('sciences-education', 'Sciences de l\'éducation', 'علوم التربية',
                'Les concours des métiers de l\'enseignement et de l\'encadrement.',
                'مباريات مهن التدريس والتأطير.', 1, 'open');

            $postbac = $this->filiere('post-bac', 'Concours post-baccalauréat', 'مباريات ما بعد الباكالوريا',
                'Les concours d\'accès aux grandes écoles et facultés à accès régulé.',
                'مباريات ولوج المدارس العليا والكليات ذات الاستقطاب المحدود.', 2, 'waitlist');

            $publique = $this->filiere('fonction-publique', 'Concours de la fonction publique', 'مباريات الوظيفة العمومية',
                'Recrutement et concours professionnels des administrations.',
                'التوظيف والمباريات المهنية بالإدارات العمومية.', 3, 'waitlist');

            // --- Sciences de l'éducation --------------------------------------
            $crmef = $this->family($education, 'crmef', 'CRMEF', 'المراكز الجهوية لمهن التربية والتكوين',
                'Ministère de l\'Éducation nationale', 'وزارة التربية الوطنية',
                'Concours d\'accès aux Centres régionaux des métiers de l\'éducation et de la formation.',
                'مباراة ولوج المراكز الجهوية لمهن التربية والتكوين.', 1, 'open');

            $this->family($education, 'licences-education', 'Licences d\'éducation', 'الإجازة في التربية',
                'Universités marocaines', 'الجامعات المغربية',
                'Concours commun d\'accès aux filières universitaires d\'éducation.',
                'المباراة الموحدة لولوج مسالك التربية الجامعية.', 2, 'waitlist');

            $this->family($education, 'agregation', 'Agrégation', 'التبريز',
                'Ministère de l\'Éducation nationale', 'وزارة التربية الوطنية',
                'Concours d\'agrégation de l\'enseignement secondaire.',
                'مباراة التبريز في التعليم الثانوي.', 3, 'waitlist');

            $this->family($education, 'cops', 'COPS', 'مستشارو التوجيه والتخطيط',
                'Ministère de l\'Éducation nationale', 'وزارة التربية الوطنية',
                'Conseillers en orientation et en planification de l\'éducation.',
                'مستشارو التوجيه والتخطيط التربوي.', 4, 'waitlist');

            // --- Post-baccalauréat --------------------------------------------
            $this->family($postbac, 'medecine', 'Médecine et pharmacie', 'الطب والصيدلة',
                'Facultés de médecine', 'كليات الطب', null, null, 1, 'waitlist');
            $this->family($postbac, 'encg', 'ENCG', 'المدارس الوطنية للتجارة والتسيير',
                'Réseau ENCG', 'شبكة المدارس الوطنية للتجارة والتسيير', null, null, 2, 'waitlist');
            $this->family($postbac, 'ensa', 'ENSA', 'المدارس الوطنية للعلوم التطبيقية',
                'Réseau ENSA', 'شبكة المدارس الوطنية للعلوم التطبيقية', null, null, 3, 'waitlist');
            $this->family($postbac, 'iscae', 'ISCAE', 'المعهد العالي للتجارة وإدارة المقاولات',
                'ISCAE', 'المعهد العالي للتجارة وإدارة المقاولات', null, null, 4, 'waitlist');

            // --- Fonction publique --------------------------------------------
            $this->family($publique, 'recrutement-administration', 'Recrutement — administrations',
                'التوظيف بالإدارات العمومية', null, null, null, null, 1, 'waitlist');
            $this->family($publique, 'concours-professionnels', 'Concours professionnels',
                'المباريات المهنية', null, null, null, null, 2, 'waitlist');

            // --- Spécialités pilotes CRMEF -------------------------------------
            $this->specialty($crmef, 'francais', 'Français', 'الفرنسية',
                'Secondaire qualifiant', 'التعليم الثانوي التأهيلي', 1, 'open');
            $this->specialty($crmef, 'mathematiques', 'Mathématiques', 'الرياضيات',
                'Secondaire qualifiant', 'التعليم الثانوي التأهيلي', 2, 'open');

            $this->session($crmef, 2026);
            $this->taxonomieCrmef($crmef);
        });
    }

    private function filiere(
        string $slug, string $fr, string $ar, ?string $tagFr, ?string $tagAr,
        int $position, string $availability,
    ): Filiere {
        return Filiere::create([
            'slug' => $slug, 'name_fr' => $fr, 'name_ar' => $ar,
            'tagline_fr' => $tagFr, 'tagline_ar' => $tagAr,
            'position' => $position, 'availability' => $availability,
            'status' => 'published', 'published_at' => now(),
        ]);
    }

    private function family(
        Filiere $filiere, string $slug, string $fr, string $ar,
        ?string $authFr, ?string $authAr, ?string $descFr, ?string $descAr,
        int $position, string $availability,
    ): ExamFamily {
        return ExamFamily::create([
            'filiere_id' => $filiere->id, 'slug' => $slug,
            'name_fr' => $fr, 'name_ar' => $ar,
            'authority_fr' => $authFr, 'authority_ar' => $authAr,
            'description_fr' => $descFr, 'description_ar' => $descAr,
            'position' => $position, 'availability' => $availability,
            'status' => 'published', 'published_at' => now(),
        ]);
    }

    private function specialty(
        ExamFamily $family, string $slug, string $fr, string $ar,
        ?string $cycleFr, ?string $cycleAr, int $position, string $availability,
    ): Specialty {
        return Specialty::create([
            'exam_family_id' => $family->id, 'slug' => $slug,
            'name_fr' => $fr, 'name_ar' => $ar,
            'cycle_fr' => $cycleFr, 'cycle_ar' => $cycleAr,
            'position' => $position, 'availability' => $availability,
            'status' => 'published', 'published_at' => now(),
        ]);
    }

    private function session(ExamFamily $family, int $year): ExamSession
    {
        return ExamSession::create([
            'exam_family_id' => $family->id,
            'label_fr' => "Session {$year}",
            'label_ar' => "دورة {$year}",
            'year' => $year,
            'dates_confirmed' => false,           // aucune date vérifiée : voir l'en-tête
            'source_note_fr' => 'Dates non confirmées. À remplacer par une source officielle.',
            'source_note_ar' => 'التواريخ غير مؤكدة. يجب استبدالها بمصدر رسمي.',
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    /**
     * Taxonomie CRMEF à quatre niveaux (ADR-0012, décision OptimGov).
     *
     * Le niveau « domaine » est conservé par décision, mais il n'est défini
     * dans aucun document existant (DET-27). Les domaines ci-dessous sont une
     * structure minimale de départ : ils doivent être revus par les
     * responsables pédagogiques avant toute production de contenu.
     */
    private function taxonomieCrmef(ExamFamily $crmef): void
    {
        TaxonomyProfile::create([
            'exam_family_id' => $crmef->id,
            'levels' => [
                ['name_fr' => 'Pilier',          'name_ar' => 'ركيزة'],
                ['name_fr' => 'Domaine',         'name_ar' => 'مجال'],
                ['name_fr' => 'Compétence',      'name_ar' => 'كفاية'],
                ['name_fr' => 'Microcompétence', 'name_ar' => 'كفاية دقيقة'],
            ],
            'min_depth_for_publication' => 3,   // microcompétence, comme l'impose le prompt experts
            'source_note_fr' => 'Structure provisoire. Le niveau « domaine » reste à définir par les responsables pédagogiques (DET-27).',
            'source_note_ar' => 'بنية مؤقتة. مستوى «المجال» يبقى في انتظار تحديده من طرف المسؤولين البيداغوجيين.',
        ]);

        $piliers = [
            ['SE', 'Sciences de l\'éducation', 'علوم التربية'],
            ['DI', 'Didactique de la discipline', 'ديداكتيك المادة'],
            ['SP', 'Spécialité disciplinaire', 'التخصص'],
        ];

        foreach ($piliers as $i => [$code, $fr, $ar]) {
            CompetencyNode::create([
                'exam_family_id' => $crmef->id,
                'parent_id' => null,
                'code' => $code,
                'name_fr' => $fr,
                'name_ar' => $ar,
                'position' => $i + 1,
            ]);
        }
    }
}
