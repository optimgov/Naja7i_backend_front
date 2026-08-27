<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ADR-0038 — Le second univers entre au catalogue : le lycée marocain.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE MODÈLE NOUS ATTENDAIT, ET IL LE DISAIT
 *
 * La migration `000670`, qui a fait de la catégorie de public un objet, écrit
 * noir sur blanc : « une catégorie de public regroupe des candidats par
 * situation (CRMEF, LYCÉE, grandes écoles) », et donne pour exemple un droit
 * `(audience, lycee)`. Elle n'a semé que `crmef`, en laissant les suivantes se
 * créer à l'écran. C'est la seconde, et elle arrive par migration plutôt que
 * par l'écran parce qu'elle amène toute une arborescence avec elle.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LES MONDES SONT SÉPARÉS, ET C'EST LA DÉCISION DU 26 AOÛT
 *
 * Un nœud n'appartient qu'à une épreuve (ADR-0014, DET-88). « Loi de Mendel »
 * existera donc deux fois : une pour le programme de 2ᵉ Bac, une pour la
 * spécialité SVT du CRMEF. Le propriétaire a tranché : les deux mondes sont
 * séparés, la duplication est assumée. La raison est pédagogique et non
 * technique — un lycéen APPLIQUE la règle, un futur professeur l'ENSEIGNE.
 * Fusionner les deux ferait compter la maîtrise de l'un vers la préparation de
 * l'autre, et la carte de maîtrise mentirait.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * COMMENT LE LYCÉE SE RANGE DANS UN MODÈLE PENSÉ POUR DES CONCOURS
 *
 *   filière        `lycee`                 la racine du catalogue
 *   famille        le NIVEAU               tronc commun · 1re Bac · 2e Bac
 *   parcours       la FILIÈRE SCOLAIRE     sciences · SE · SM · SP · SVT
 *   spécialité     la MATIÈRE              maths · physique-chimie · SVT
 *   épreuve        (parcours × matière)    l'unité qui porte un arbre
 *
 * L'épreuve n'est pas un examen ici : c'est le CONTENANT d'un arbre de
 * compétences. Le mot du modèle ne change pas, ce qu'il désigne si — et c'est
 * assumé plutôt que contourné, parce qu'inventer une seconde table « matière »
 * dupliquerait toute la mécanique de droits, de portées et de quotas qui pend
 * déjà de `exams`.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * TOUT ENTRE EN BROUILLON ET EN LISTE D'ATTENTE
 *
 * `status = draft`, `availability = waitlist`, `coefficient` NUL. Rien n'est
 * publié : aucune de ces onze épreuves ne porte encore une seule question, et
 * une porte qui montre sans ouvrir est proscrite. C'est l'expert pédagogue qui
 * ouvrira, matière par matière, quand sa banque le permettra.
 *
 * `provenance = unverified` sur les épreuves : les coefficients officiels du
 * baccalauréat existent et sont publics, mais aucun document du dépôt ne les
 * atteste. Les inscrire ici serait refaire DET-60 — des poids rapportés dont
 * personne n'a vu la pièce.
 *
 * Les NŒUDS ne sont pas posés ici : ils viennent du catalogue relevé
 * (`docs/corpus/lycee-maroc-noeuds-proposes-20260826.csv`) par une commande
 * rejouable, parce qu'ils sont des données que l'expert élague — et qu'une
 * donnée qu'on élague ne se pose pas par migration.
 */
return new class extends Migration
{
    private const AUDIENCE = 'lycee';

    private const FILIERE = 'lycee';

    /** [slug, nom fr, nom ar, position] */
    private const NIVEAUX = [
        ['tronc-commun', 'Tronc commun', 'الجذع المشترك', 1],
        ['premiere-bac', '1re année du baccalauréat', 'السنة الأولى من سلك البكالوريا', 2],
        ['deuxieme-bac', '2e année du baccalauréat', 'السنة الثانية من سلك البكالوريا', 3],
    ];

    /** [slug, niveau, nom fr, nom ar, position] */
    private const PARCOURS = [
        ['sciences-tc', 'tronc-commun', 'Sciences', 'العلوم', 1],
        ['sciences-experimentales-1bac', 'premiere-bac', 'Sciences expérimentales', 'العلوم التجريبية', 1],
        ['sciences-mathematiques-1bac', 'premiere-bac', 'Sciences mathématiques', 'العلوم الرياضية', 2],
        ['sciences-physiques-2bac', 'deuxieme-bac', 'Sciences physiques', 'العلوم الفيزيائية', 1],
        ['svt-2bac', 'deuxieme-bac', 'Sciences de la vie et de la terre', 'علوم الحياة والأرض', 2],
        ['sciences-mathematiques-2bac', 'deuxieme-bac', 'Sciences mathématiques', 'العلوم الرياضية', 3],
    ];

    /**
     * [code d'épreuve, parcours, slug de spécialité, matière fr, matière ar, position]
     *
     * LE SLUG DE SPÉCIALITÉ PORTE SON PARCOURS — c'est la règle posée par
     * `000780` après DET-80/DET-101 : « mathématiques » existe six fois dans
     * cet univers, et l'unicité `(exam_family_id, slug)` la refuserait sans ce
     * suffixe. Le code d'épreuve, lui, reprend l'`arbre_code` du catalogue
     * relevé : c'est par lui que la commande d'import rattachera les nœuds.
     */
    private const MATIERES = [
        ['TCS-MATH', 'sciences-tc', 'mathematiques-sciences-tc', 'Mathématiques', 'الرياضيات', 1],
        ['TCS-PC', 'sciences-tc', 'physique-chimie-sciences-tc', 'Physique-Chimie', 'الفيزياء والكيمياء', 2],
        ['TCS-SVT', 'sciences-tc', 'svt-sciences-tc', 'Sciences de la vie et de la terre', 'علوم الحياة والأرض', 3],

        ['1BAC-SE-MATH', 'sciences-experimentales-1bac', 'mathematiques-se-1bac', 'Mathématiques', 'الرياضيات', 1],
        ['1BAC-SE-PC', 'sciences-experimentales-1bac', 'physique-chimie-se-1bac', 'Physique-Chimie', 'الفيزياء والكيمياء', 2],
        ['1BAC-SE-SVT', 'sciences-experimentales-1bac', 'svt-se-1bac', 'Sciences de la vie et de la terre', 'علوم الحياة والأرض', 3],

        ['1BAC-SM-MATH', 'sciences-mathematiques-1bac', 'mathematiques-sm-1bac', 'Mathématiques', 'الرياضيات', 1],

        ['2BAC-PC-MATH', 'sciences-physiques-2bac', 'mathematiques-sp-2bac', 'Mathématiques', 'الرياضيات', 1],
        ['2BAC-PC-PC', 'sciences-physiques-2bac', 'physique-chimie-sp-2bac', 'Physique-Chimie', 'الفيزياء والكيمياء', 2],

        ['2BAC-SVT-SVT', 'svt-2bac', 'svt-svt-2bac', 'Sciences de la vie et de la terre', 'علوم الحياة والأرض', 1],

        ['2BAC-SM-MATH', 'sciences-mathematiques-2bac', 'mathematiques-sm-2bac', 'Mathématiques', 'الرياضيات', 1],
    ];

    public function up(): void
    {
        $maintenant = now();

        $audienceId = $this->poser('audiences', ['code' => self::AUDIENCE], [
            'name_fr' => 'Lycée',
            'name_ar' => 'الثانوي التأهيلي',
            'active' => true,
            'position' => 2,
        ], $maintenant);

        $filiereId = $this->poser('filieres', ['slug' => self::FILIERE], [
            'name_fr' => 'Lycée — enseignement secondaire qualifiant',
            'name_ar' => 'التعليم الثانوي التأهيلي',
            'tagline_fr' => 'Maîtriser son programme et préparer ses examens de classe, toute l’année.',
            'tagline_ar' => 'إتقان المقرر والاستعداد لامتحانات القسم، طوال السنة.',
            'position' => 4,
            'status' => 'draft',
            'availability' => 'waitlist',
        ], $maintenant);

        $niveaux = [];
        foreach (self::NIVEAUX as [$slug, $fr, $ar, $position]) {
            $niveaux[$slug] = $this->poser('exam_families', ['filiere_id' => $filiereId, 'slug' => $slug], [
                'name_fr' => $fr,
                'name_ar' => $ar,
                'authority_fr' => 'Ministère de l’Éducation nationale',
                'authority_ar' => 'وزارة التربية الوطنية',
                'audience_id' => $audienceId,
                'position' => $position,
                'status' => 'draft',
                'availability' => 'waitlist',
            ], $maintenant);
        }

        $parcours = [];
        foreach (self::PARCOURS as [$slug, $niveau, $fr, $ar, $position]) {
            $parcours[$slug] = [
                'id' => $this->poser('tracks', ['exam_family_id' => $niveaux[$niveau], 'slug' => $slug], [
                    'name_fr' => $fr,
                    'name_ar' => $ar,
                    'position' => $position,
                    'status' => 'draft',
                    'availability' => 'waitlist',
                ], $maintenant),
                'famille' => $niveaux[$niveau],
            ];
        }

        foreach (self::MATIERES as [$code, $voie, $slug, $fr, $ar, $position]) {
            $specialiteId = $this->poser('specialties', [
                'exam_family_id' => $parcours[$voie]['famille'],
                'slug' => $slug,
            ], [
                'track_id' => $parcours[$voie]['id'],
                'name_fr' => $fr,
                'name_ar' => $ar,
                'position' => $position,
                'status' => 'draft',
                'availability' => 'waitlist',
            ], $maintenant);

            $this->poser('exams', ['code' => $code], [
                'track_id' => $parcours[$voie]['id'],
                'specialty_id' => $specialiteId,
                'name_fr' => $fr,
                'name_ar' => $ar,
                'coefficient' => null,
                'format' => 'qcm',
                'provenance' => 'unverified',
                'position' => $position,
                'status' => 'draft',
            ], $maintenant);
        }
    }

    /**
     * REJOUABLE PAR CONSTRUCTION. `updateOrInsert` sur la clé naturelle, et
     * l'uuid n'est engendré qu'à la création : rejouer ne casse aucune
     * référence, ce qui compte dès qu'un nœud ou un droit pendra de ces lignes.
     */
    private function poser(string $table, array $cle, array $valeurs, $maintenant): int
    {
        $existant = DB::table($table)->where($cle)->first();

        DB::table($table)->updateOrInsert($cle, $valeurs + [
            'uuid' => $existant->uuid ?? (string) Str::uuid7(),
            'updated_at' => $maintenant,
            'created_at' => $existant->created_at ?? $maintenant,
        ]);

        return (int) DB::table($table)->where($cle)->value('id');
    }

    /**
     * LE RETOUR ARRIÈRE REFUSE D'EFFACER CE QUI PORTE QUELQUE CHOSE.
     *
     * Une épreuve qui a reçu des nœuds, une famille qui porte un droit : les
     * supprimer emporterait du travail d'expert ou un accès vendu. La
     * migration se retire donc seulement si l'univers est resté vide, et
     * échoue bruyamment sinon plutôt que de détruire en silence.
     */
    public function down(): void
    {
        $filiereId = DB::table('filieres')->where('slug', self::FILIERE)->value('id');

        if ($filiereId === null) {
            return;
        }

        $familles = DB::table('exam_families')->where('filiere_id', $filiereId)->pluck('id');
        $epreuves = DB::table('exams')->whereIn('code', array_column(self::MATIERES, 0))->pluck('id');

        $noeuds = DB::table('competency_nodes')->whereIn('exam_id', $epreuves)->count();

        if ($noeuds > 0) {
            throw new RuntimeException(
                "Retour arrière refusé : {$noeuds} nœud(s) pendent des épreuves du lycée. "
                .'Retirez-les par `naja7i:retirer-les-noeuds-lycee` avant de défaire cette migration.'
            );
        }

        DB::table('exams')->whereIn('id', $epreuves)->delete();
        DB::table('specialties')->whereIn('exam_family_id', $familles)->delete();
        DB::table('tracks')->whereIn('exam_family_id', $familles)->delete();
        DB::table('exam_families')->whereIn('id', $familles)->delete();
        DB::table('filieres')->where('id', $filiereId)->delete();

        /*
         * L'AUDIENCE SE DÉSACTIVE, ELLE NE SE SUPPRIME PAS — et ce n'est pas
         * moi qui le décide, c'est la base : `refuse_audience_deletion` lève
         * « une catégorie de public se retire de la sélection, elle ne se
         * supprime jamais ». La règle est juste, et elle a mordu ici au
         * premier essai de retour arrière.
         *
         * Le motif tient en une phrase : une catégorie a pu porter un droit
         * vendu. L'effacer rendrait ce droit illisible au lieu de le rendre
         * caduc — clos, jamais effacé.
         */
        DB::table('audiences')
            ->where('code', self::AUDIENCE)
            ->update(['active' => false, 'updated_at' => now()]);
    }
};
