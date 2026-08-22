<?php

namespace Database\Seeders;

use App\Models\BlueprintModel;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\ExamFamily;
use App\Models\Source;
use App\Models\Specialty;
use App\Models\TaxonomyProfile;
use App\Models\Track;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * CRMEF — session de référence novembre 2025.
 *
 * Source : docs/regles/CRMEF-2025-referentiel.md, transposé des descriptifs
 * du Centre national des examens scolaires et de l'évaluation des
 * apprentissages.
 *
 * DISCIPLINE DE PROVENANCE, appliquée sans exception :
 *  - `official`   — figure explicitement dans un descriptif officiel ;
 *  - `editorial`  — choix de Naja7i pour l'apprentissage ;
 *  - `unverified` — à valider par un humain.
 *
 * Ce qui n'est PAS dans les descriptifs reste nul : nombre de questions,
 * barème détaillé, seuil d'admission, coefficients des spécialités autres que
 * le français. Ne jamais les remplir « pour que ce soit plus complet ».
 */
class Crmef2025Seeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $crmef = ExamFamily::where('slug', 'crmef')->firstOrFail();
            $sources = $this->sources();

            [$bilingue, $amazigh] = $this->parcoursPrimaires($crmef);
            $this->specialitesPrimaireBilingue($bilingue);
            $this->specialitesPrimaireAmazigh($amazigh);

            $secondaire = $this->trackSecondaire($crmef);
            $this->purgerSpecialitesOrphelines($crmef);
            $francais = $this->specialitesSecondaire($secondaire);

            $se = $this->epreuveSciencesEducation($secondaire, $sources['SE']);
            $did = $this->epreuveDidactique($secondaire, $francais, $sources['DID']);
            $spec = $this->epreuveSpecialite($secondaire, $francais, $sources['SPEC']);

            $this->matriceSciencesEducation($se, $sources['SE']);
            $this->matriceDidactique($did, $sources['DID']);
            $this->matriceSpecialite($spec, $sources['SPEC']);

            $this->rattacherSourcesTransposees($sources, $secondaire, $francais);
            $this->carteDuCorpus($bilingue, $amazigh, $secondaire);
        });
    }

    /**
     * Le PAS-4 avait créé des spécialités provisoires rattachées à la famille
     * sans parcours (« francais », « mathematiques »). Le référentiel officiel
     * les remplace ; les laisser produirait deux entrées pour la même
     * discipline, dont une invisible au candidat.
     */
    private function purgerSpecialitesOrphelines(ExamFamily $crmef): void
    {
        Specialty::where('exam_family_id', $crmef->id)
            ->whereNull('track_id')
            ->whereDoesntHave('family.tracks')   // sécurité : rien qui soit déjà rattaché
            ->delete();

        Specialty::where('exam_family_id', $crmef->id)
            ->whereNull('track_id')
            ->delete();
    }

    /** @return array<string, Source> */
    private function sources(): array
    {
        $autorite = 'Centre national des examens scolaires et de l\'évaluation des apprentissages';
        $autoriteAr = 'المركز الوطني للامتحانات المدرسية وتقويم التعلمات';

        $faire = fn (string $code, string $titre, ?array $langues, string $loc) => Source::create([
            'code' => $code,
            'kind' => 'descriptif_officiel',
            'title_fr' => $titre,
            'authority_fr' => $autorite,
            'authority_ar' => $autoriteAr,
            'session_label' => 'Novembre 2025',
            'languages' => $langues,
            'location_note_fr' => $loc,
        ]);

        return [
            'SE' => $faire(
                'SRC-CRMEF-2025-SE',
                'Descriptif des domaines des épreuves écrites — Sciences de l\'éducation',
                ['ar', 'fr'],
                'Page 1 : métadonnées ; pages 2-3 : domaines et poids.'
            ),
            'DID' => $faire(
                'SRC-CRMEF-2025-FR-DID',
                'Descriptif de l\'épreuve de didactique — spécialisation Langue française',
                ['fr'],
                'Page 1 : métadonnées ; pages 2-3 : domaines et poids.'
            ),
            'SPEC' => $faire(
                'SRC-CRMEF-2025-FR-SPEC',
                'Descriptif de l\'épreuve de spécialité — discipline Langue française',
                ['fr'],
                'Page 1 : métadonnées ; pages 2-7 : domaines, contenus et poids.'
            ),
        ];
    }

    /** @return array{0: Track, 1: Track} */
    private function parcoursPrimaires(ExamFamily $crmef): array
    {
        // Descriptifs existants, mais aucune banque dans ce lot : liste d'attente.
        $bilingue = Track::create([
            'exam_family_id' => $crmef->id, 'slug' => 'primaire-bilingue',
            'name_fr' => 'Primaire bilingue', 'name_ar' => 'الابتدائي بالتعليم المزدوج',
            'description_fr' => 'Sciences de l\'éducation, didactique du primaire bilingue et matières de spécialité (arabe, français, mathématiques, activité scientifique).',
            'position' => 1, 'availability' => 'waitlist',
            'status' => 'published', 'published_at' => now(),
        ]);

        $amazigh = Track::create([
            'exam_family_id' => $crmef->id, 'slug' => 'primaire-amazigh',
            'name_fr' => 'Primaire — Langue amazighe', 'name_ar' => 'الابتدائي — اللغة الأمازيغية',
            'description_fr' => 'Sciences de l\'éducation, didactique de la langue amazighe et spécialité Langue amazighe.',
            'position' => 2, 'availability' => 'waitlist',
            'status' => 'published', 'published_at' => now(),
        ]);

        return [$bilingue, $amazigh];
    }

    private function trackSecondaire(ExamFamily $crmef): Track
    {
        return Track::create([
            'exam_family_id' => $crmef->id, 'slug' => 'secondaire',
            'name_fr' => 'Secondaire collégial et qualifiant',
            'name_ar' => 'الثانوي الإعدادي والتأهيلي',
            'description_fr' => 'Les descriptifs officiels fournissent un même descriptif d\'épreuve pour les deux cycles.',
            'position' => 3, 'availability' => 'open',
            'status' => 'published', 'published_at' => now(),
        ]);
    }

    /**
     * Les treize spécialités du secondaire. UNE SEULE est ouverte.
     *
     * Le référentiel est explicite : ne pas déduire les coefficients ni les
     * durées des autres spécialités depuis le français. Leur fiche existe,
     * leurs épreuves ne sont pas créées.
     */
    /**
     * Les ONZE spécialités du secondaire.
     *
     * L'inventaire des sources documentaires fait foi : il recense onze
     * disciplines au secondaire, chacune avec son descriptif de didactique et
     * son descriptif de spécialité. « Sciences économiques et gestion » et
     * « Technologie », qui figuraient dans un document antérieur, n'y
     * apparaissent pas — elles ne sont donc pas créées.
     *
     * UNE SEULE est ouverte. Le référentiel interdit de déduire les
     * coefficients et durées des autres depuis le français : leur fiche
     * existe, leurs épreuves ne sont pas créées.
     */
    private function specialitesSecondaire(Track $track): Specialty
    {
        $liste = [
            ['langue-arabe',        'Langue arabe',                       'اللغة العربية'],
            ['langue-francaise',    'Langue française',                   'اللغة الفرنسية'],
            ['langue-anglaise',     'Langue anglaise',                    'اللغة الإنجليزية'],
            ['mathematiques',       'Mathématiques',                      'الرياضيات'],
            ['physique-chimie',     'Physique-Chimie',                    'الفيزياء والكيمياء'],
            ['svt',                 'Sciences de la Vie et de la Terre',  'علوم الحياة والأرض'],
            ['histoire-geographie', 'Histoire-Géographie',                'التاريخ والجغرافيا'],
            ['philosophie',         'Philosophie',                        'الفلسفة'],
            ['informatique',        'Informatique',                       'المعلوميات'],
            ['education-islamique', 'Éducation islamique',                'التربية الإسلامية'],
            ['eps',                 'Éducation physique et sportive',     'التربية البدنية والرياضية'],
        ];

        return $this->specialites($track, $liste, ouverte: 'langue-francaise');
    }

    /**
     * Primaire bilingue : quatre disciplines, une seule épreuve de didactique
     * commune aux quatre — contrairement au secondaire, où chaque discipline a
     * son propre descriptif de didactique. Le modèle le gère par un
     * `specialty_id` nul sur l'épreuve.
     */
    private function specialitesPrimaireBilingue(Track $track): void
    {
        $this->specialites($track, [
            ['langue-arabe',     'Langue arabe',                      'اللغة العربية'],
            ['langue-francaise', 'Langue française',                  'اللغة الفرنسية'],
            ['mathematiques',    'Mathématiques',                     'الرياضيات'],
            ['svt',              'Sciences de la Vie et de la Terre', 'علوم الحياة والأرض'],
        ], ouverte: null);
    }

    private function specialitesPrimaireAmazigh(Track $track): void
    {
        $this->specialites($track, [
            ['langue-amazighe', 'Langue amazighe', 'اللغة الأمازيغية'],
        ], ouverte: null);
    }

    /**
     * @param  list<array{0:string,1:string,2:string}>  $liste
     */
    private function specialites(Track $track, array $liste, ?string $ouverte): ?Specialty
    {
        $retenue = null;

        foreach ($liste as $i => [$slug, $fr, $ar]) {
            $estOuverte = $ouverte !== null && $slug === $ouverte;

            $specialite = Specialty::updateOrCreate(
                ['track_id' => $track->id, 'slug' => $slug],
                [
                    'exam_family_id' => $track->exam_family_id,
                    'name_fr' => $fr, 'name_ar' => $ar,
                    'cycle_fr' => $track->name_fr, 'cycle_ar' => $track->name_ar,
                    'position' => $i + 1,
                    'availability' => $estOuverte ? 'open' : 'waitlist',
                    'status' => 'published', 'published_at' => now(),
                ]
            );

            if ($estOuverte) {
                $retenue = $specialite;
            }
        }

        return $retenue;
    }

    // --- Les trois épreuves --------------------------------------------------

    private function epreuveSciencesEducation(Track $track, Source $source): Exam
    {
        return Exam::create([
            'track_id' => $track->id,
            'specialty_id' => null,            // commune aux treize spécialités
            'code' => 'CRMEF-SE-2025',
            'name_fr' => 'Sciences de l\'éducation', 'name_ar' => 'علوم التربية',
            'coefficient' => 8, 'duration_minutes' => 120, 'format' => 'qcm',
            'languages_allowed' => ['ar', 'fr'],   // au choix du candidat
            'position' => 1, 'provenance' => 'official',
            'status' => 'published', 'published_at' => now(),

            /*
             * LA NUMÉROTATION RÉELLE, IMPRIMÉE. Le sujet de la voie B, session
             * نونبر 2025, est dans le corpus (§1.4) : « من السؤال Q 61 إلى
             * السؤال Q 120 ». Un examen blanc qui numérote 1, 2, 3 entraîne au
             * report sur la mauvaise ligne d'une feuille commune à plusieurs
             * blocs — et « un décalage d'une seule ligne invalide la totalité
             * du bloc ».
             *
             * ─────────────────────────────────────────────────────────────
             * CINQ OPTIONS, ET C'EST DÉCLARÉ MÊME SI ÇA DÉRANGE.
             *
             * Le même sujet imprime « خمسة (5) اختيارات لأجوبة مقترحة (A, B, C,
             * D, E) واحد منها فقط ». Cette épreuve PRÉTEND être ce sujet : elle
             * déclare donc cinq options.
             *
             * La première écriture de ce lot avait laissé la colonne nulle,
             * parce que la déclarer rendait rouges sept tests de la suite. Le
             * rouge disait vrai : notre banque de démonstration, écrite à quatre
             * options sous l'ancienne règle, ne ressemble plus à l'épreuve
             * réelle depuis 2024. Taire le fait pour retrouver du vert aurait
             * fait du seeder le gardien d'une fiction confortable.
             *
             * La règle ne bloque QUE la publication et l'éligibilité, jamais
             * l'existence : un brouillon à quatre options vit normalement, ce
             * que deux tests prouvent des deux côtés. C'est ce qui permettra à
             * l'import des annales de la phase 3 d'entrer sans se heurter à ce
             * mur.
             * ─────────────────────────────────────────────────────────────
             */
            'options_count' => 5,
            'first_question_number' => 61,
        ])->tap(fn (Exam $e) => $this->blueprint($e, $source,
            'Domaines et poids officiels. Le nombre de questions et le barème détaillé ne figurent pas dans le descriptif.',
            /* « يعتمد تنقيط سالب عن كل إجابة خاطئة أو ملغاة » — la pénalité est
             * imprimée. Son MONTANT ne l'est pas, et reste donc nul. */
            penalite: true));
    }

    private function epreuveDidactique(Track $track, Specialty $francais, Source $source): Exam
    {
        return Exam::create([
            'track_id' => $track->id, 'specialty_id' => $francais->id,
            'code' => 'CRMEF-FR-DID-2025',
            'name_fr' => 'Didactique de la langue française', 'name_ar' => 'ديداكتيك اللغة الفرنسية',
            'coefficient' => 12, 'duration_minutes' => 120, 'format' => 'qcm',
            'languages_allowed' => ['fr'],
            'position' => 2, 'provenance' => 'official',
            'status' => 'published', 'published_at' => now(),
        ])->tap(fn (Exam $e) => $this->blueprint($e, $source,
            'Domaines et poids officiels. Le nombre de questions et le barème détaillé ne figurent pas dans le descriptif.'));
    }

    private function epreuveSpecialite(Track $track, Specialty $francais, Source $source): Exam
    {
        return Exam::create([
            'track_id' => $track->id, 'specialty_id' => $francais->id,
            'code' => 'CRMEF-FR-SPEC-2025',
            'name_fr' => 'Spécialité — Langue française', 'name_ar' => 'التخصص — اللغة الفرنسية',
            'coefficient' => 20, 'duration_minutes' => 240, 'format' => 'qcm',
            'languages_allowed' => ['fr'],
            'position' => 3, 'provenance' => 'official',
            'status' => 'published', 'published_at' => now(),
        ])->tap(fn (Exam $e) => $this->blueprint($e, $source,
            'Domaines et poids officiels. Le nombre de questions et le barème détaillé ne figurent pas dans le descriptif.'));
    }

    /**
     * @param  bool|null  $penalite  true = pénalité imprimée sur le sujet ;
     *                               false = le sujet imprime zéro pour une erreur ;
     *                               null = aucun sujet ne le dit — le défaut.
     */
    private function blueprint(Exam $exam, Source $source, string $note, ?bool $penalite = null): BlueprintModel
    {
        return BlueprintModel::create([
            'exam_id' => $exam->id, 'source_id' => $source->id,
            'version' => '2025-11',
            'negative_marking' => $penalite,
            'official_question_count' => null,             // non établi par la source
            'official_scoring_note_fr' => 'Barème détaillé non précisé par le descriptif officiel.',
            'official_scoring_note_ar' => 'سلّم التنقيط المفصّل غير محدَّد في الوصف الرسمي.',
            'official_admission_threshold_note_fr' => 'Seuil d\'admission non précisé par le descriptif officiel.',
            'official_admission_threshold_note_ar' => 'عتبة القبول غير محدَّدة في الوصف الرسمي.',
            'coverage_note_fr' => $note,
            'status' => 'published', 'published_at' => now(),
        ]);
    }

    // --- Les trois matrices officielles --------------------------------------

    private function matriceSciencesEducation(Exam $exam, Source $source): void
    {
        $this->profil($exam, [
            ['name_fr' => 'Domaine',      'name_ar' => 'مجال'],
            ['name_fr' => 'Sous-domaine', 'name_ar' => 'مجال فرعي'],
        ]);

        $this->arbre($exam, $source, [
            ['SE-PSY', 'Psychologie de l\'éducation', 'سيكولوجيا التربية', 40, [
                ['SE-PSY-DEV',   'Psychologie du développement',  'سيكولوجيا النمو',   20],
                ['SE-PSY-LEARN', 'Psychologie de l\'apprentissage', 'سيكولوجيا التعلم', 20],
            ]],
            ['SE-PED', 'Approches pédagogiques et méthodes d\'enseignement', 'المقاربات البيداغوجية وطرائق التدريس', 30, [
                ['SE-PED-PPO-APC',  'De la pédagogie par objectifs à l\'approche par compétences', 'من بيداغوجيا الأهداف إلى المقاربة بالكفايات', 15],
                ['SE-PED-METHODS',  'Méthodes d\'enseignement et stratégies d\'enseignement-apprentissage', 'طرائق التدريس واستراتيجيات التعليم والتعلم', 15],
            ]],
            ['SE-SOC', 'Sociologie de l\'éducation', 'سوسيولوجيا التربية', 30, [
                ['SE-SOC-EDU',   'Sociologie de l\'éducation', 'سوسيولوجيا التربية', 15],
                ['SE-SOC-GROUP', 'Dynamique de groupe',        'دينامية الجماعة',   15],
            ]],
        ]);
    }

    private function matriceDidactique(Exam $exam, Source $source): void
    {
        $this->profil($exam, [
            ['name_fr' => 'Bloc',         'name_ar' => 'محور'],
            ['name_fr' => 'Sous-domaine', 'name_ar' => 'مجال فرعي'],
        ]);

        $this->arbre($exam, $source, [
            ['FR-DID-A', 'Didactique', 'الديداكتيك', 60, [
                ['FR-DID-CHAMP',      'Champ de la didactique', 'مجال الديداكتيك',       10],
                ['FR-DID-CONCEPTS',   'Concepts de base',       'المفاهيم الأساسية',      20],
                ['FR-DID-CURRICULUM', 'Curriculum',             'المنهاج',               10],
                ['FR-DID-RESOURCES',  'Ressources didactiques', 'الموارد الديداكتيكية',   20],
            ]],
            ['FR-DID-B', 'Approches et apprentissage actif', 'المقاربات والتعلم النشيط', 40, [
                ['FR-DID-PPO-CONCEPTS',    'Concepts clés de la pédagogie par objectifs', 'المفاهيم المفاتيح لبيداغوجيا الأهداف', 5],
                ['FR-DID-PPO-FOUNDATIONS', 'Fondements et mise en œuvre de la PPO',       'أسس وتنزيل بيداغوجيا الأهداف',        10],
                ['FR-DID-APC-CONCEPTS',    'Concepts clés de l\'approche par compétences', 'المفاهيم المفاتيح للمقاربة بالكفايات', 5],
                ['FR-DID-APC-FOUNDATIONS', 'Fondements et mise en œuvre de l\'APC',        'أسس وتنزيل المقاربة بالكفايات',       10],
                ['FR-DID-ACTIVE',          'Apprentissage actif',                          'التعلم النشيط',                       10],
            ]],
        ]);
    }

    private function matriceSpecialite(Exam $exam, Source $source): void
    {
        $this->profil($exam, [
            ['name_fr' => 'Domaine',      'name_ar' => 'مجال'],
            ['name_fr' => 'Sous-domaine', 'name_ar' => 'مجال فرعي'],
        ]);

        $this->arbre($exam, $source, [
            ['FR-SPEC-LANG', 'Langue', 'اللغة', 50, [
                ['FR-SPEC-LING',      'Linguistique, phonétique, lexicographie et lexicologie', 'اللسانيات والصوتيات والمعجميات', 15],
                ['FR-SPEC-GRAM',      'Grammaire',                          'القواعد',            15],
                ['FR-SPEC-STYL',      'Stylistique',                        'الأسلوبية',          10],
                ['FR-SPEC-DISCOURSE', 'Analyse du discours et énonciation', 'تحليل الخطاب والتلفظ', 10],
            ]],
            ['FR-SPEC-LIT', 'Littérature et culture françaises', 'الأدب والثقافة الفرنسية', 50, [
                ['FR-SPEC-HIST-MYTH', 'Histoire des idées, histoire littéraire et mythes', 'تاريخ الأفكار والأدب والأساطير', 5],
                ['FR-SPEC-NOVEL',     'Roman et genres du récit',       'الرواية وأجناس السرد',   10],
                ['FR-SPEC-NARRATIVE', 'Analyse du texte narratif',      'تحليل النص السردي',      10],
                ['FR-SPEC-THEATRE',   'Théâtre',                        'المسرح',                10],
                ['FR-SPEC-POETRY',    'Poésie et versification',        'الشعر والعروض',          10],
                ['FR-SPEC-MAGHREB',   'Littérature maghrébine d\'expression française', 'الأدب المغاربي المكتوب بالفرنسية', 5],
            ]],
        ]);
    }

    /** @param  list<array{name_fr: string, name_ar: string}>  $niveaux */
    private function profil(Exam $exam, array $niveaux): void
    {
        TaxonomyProfile::create([
            'exam_id' => $exam->id,
            'levels' => $niveaux,
            'min_depth_for_publication' => 1,   // rattachement au sous-domaine exigé
            'source_note_fr' => 'Niveaux repris du descriptif officiel de l\'épreuve.',
        ]);
    }

    /** @param  array<int, array{0:string,1:string,2:string,3:int,4:array}>  $domaines */
    /**
     * LE SEMIS DIT DÉSORMAIS CE QU'IL SAIT, ET RIEN DE PLUS — lot TAXO.
     *
     * Il écrivait `official`. La migration 000530 existait précisément pour
     * défaire cela après coup : « aucun cadre de référence n'est dans les
     * 33 fichiers », et un poids dont personne n'a lu la pièce n'est pas
     * officiel. Un semis qui affirme une chose qu'une migration s'empresse de
     * démentir est un semis qui ment deux minutes — le déclencheur du lot TAXO
     * le refuse maintenant à la source, ce qui est mieux.
     *
     * `reported` est le mot juste : une origine identifiée, datée, paginée,
     * jamais vue. La justification l'écrit en toutes lettres plutôt que de
     * laisser croire à une valeur d'architecte.
     *
     * CE SEMIS NE DÉCIDE TOUJOURS AUCUN ARBRE : la découpe et les poids sont
     * inchangés, seule leur ÉTIQUETTE cesse d'être fausse.
     */
    private const POIDS_RAPPORTE = 'Poids rapporté par le descriptif de l\'épreuve, dont la pièce '
        .'n\'a pas été vérifiée dans ce dépôt (DET-60). Repris tel quel : rien ne le contredit.';

    private function arbre(Exam $exam, Source $source, array $domaines): void
    {
        foreach ($domaines as $i => [$code, $fr, $ar, $poids, $enfants]) {
            $parent = CompetencyNode::create([
                'exam_id' => $exam->id, 'parent_id' => null,
                'code' => $code, 'name_fr' => $fr, 'name_ar' => $ar,
                'weight_percent' => $poids, 'weight_justification' => self::POIDS_RAPPORTE,
                'source_id' => $source->id,
                'provenance' => 'reported', 'position' => $i + 1,
            ]);

            foreach ($enfants as $j => [$codeEnfant, $frEnfant, $arEnfant, $poidsEnfant]) {
                CompetencyNode::create([
                    'exam_id' => $exam->id, 'parent_id' => $parent->id,
                    'code' => $codeEnfant, 'name_fr' => $frEnfant, 'name_ar' => $arEnfant,
                    'weight_percent' => $poidsEnfant, 'weight_justification' => self::POIDS_RAPPORTE,
                    'source_id' => $source->id,
                    'provenance' => 'reported', 'position' => $j + 1,
                ]);
            }
        }
    }

    // --- Carte de couverture du corpus officiel ------------------------------

    /** Les trois descriptifs réellement transposés sont marqués comme tels. */
    private function rattacherSourcesTransposees(array $sources, Track $secondaire, Specialty $francais): void
    {
        $sources['SE']->update([
            'component' => 'sciences_education', 'transposition_status' => 'transpose',
            'track_id' => $secondaire->id, 'discipline_label_fr' => 'Commune',
            'coverage_note_fr' => '3 domaines et 6 sous-domaines transposés avec leurs poids.',
        ]);

        $sources['DID']->update([
            'component' => 'didactique', 'transposition_status' => 'transpose',
            'track_id' => $secondaire->id, 'specialty_id' => $francais->id,
            'discipline_label_fr' => 'Langue française',
            'coverage_note_fr' => '2 blocs et 9 sous-domaines transposés avec leurs poids.',
        ]);

        $sources['SPEC']->update([
            'component' => 'discipline', 'transposition_status' => 'transpose',
            'track_id' => $secondaire->id, 'specialty_id' => $francais->id,
            'discipline_label_fr' => 'Langue française',
            'coverage_note_fr' => '2 domaines et 10 sous-domaines transposés avec leurs poids.',
        ]);
    }

    /**
     * Les 29 descriptifs restants de l'inventaire — identifiés, non transposés.
     *
     * Leur enregistrement rend le manque mesurable : on peut répondre à
     * « quelle part du corpus est intégrée ? » et « quel concours ouvrir
     * ensuite ? » sans supposition.
     *
     * Réserve consignée : l'inventaire mentionne un descriptif de sciences de
     * l'éducation pour chacun des trois parcours, sans indiquer s'il s'agit du
     * même document. Trois entrées distinctes sont donc créées, avec cette
     * incertitude notée. La fusion éventuelle relève d'une vérification
     * documentaire, pas d'une supposition de notre part.
     */
    private function carteDuCorpus(Track $bilingue, Track $amazigh, Track $secondaire): void
    {
        $entrees = [];

        // Primaire bilingue : SE commune, une didactique commune aux 4 disciplines, 4 spécialités.
        $entrees[] = [$bilingue, null, 'sciences_education', 'Commune',
            'Descriptif officiel des domaines de l\'épreuve « Sciences de l\'éducation »',
            'Identité avec le descriptif du secondaire non vérifiée.'];
        $entrees[] = [$bilingue, null, 'didactique', 'Arabe, français, mathématiques, SVT',
            'Descriptif officiel des domaines de l\'épreuve « Didactique »',
            'Un seul descriptif couvre les quatre disciplines du parcours.'];

        foreach ([
            ['langue-arabe', 'Langue arabe'], ['langue-francaise', 'Langue française'],
            ['mathematiques', 'Mathématiques'], ['svt', 'Sciences de la Vie et de la Terre'],
        ] as [$slug, $label]) {
            $entrees[] = [$bilingue, $slug, 'discipline', $label,
                "Descriptif officiel de la spécialité « {$label} »", null];
        }

        // Primaire amazigh.
        $entrees[] = [$amazigh, null, 'sciences_education', 'Commune',
            'Descriptif officiel des domaines de l\'épreuve « Sciences de l\'éducation »',
            'Identité avec le descriptif du secondaire non vérifiée.'];
        $entrees[] = [$amazigh, 'langue-amazighe', 'didactique', 'Langue amazighe',
            'Descriptif officiel des domaines de l\'épreuve « Didactique »', null];
        $entrees[] = [$amazigh, 'langue-amazighe', 'discipline', 'Langue amazighe',
            'Descriptif officiel de la spécialité « Langue amazighe »', null];

        // Secondaire : didactique + discipline pour chacune des onze spécialités,
        // sauf le français, déjà transposé.
        foreach ([
            ['langue-arabe', 'Langue arabe'], ['langue-anglaise', 'Langue anglaise'],
            ['mathematiques', 'Mathématiques'], ['physique-chimie', 'Physique-Chimie'],
            ['svt', 'Sciences de la Vie et de la Terre'], ['histoire-geographie', 'Histoire-Géographie'],
            ['philosophie', 'Philosophie'], ['informatique', 'Informatique'],
            ['education-islamique', 'Éducation islamique'], ['eps', 'Éducation physique et sportive'],
        ] as [$slug, $label]) {
            $entrees[] = [$secondaire, $slug, 'didactique', $label,
                "Descriptif officiel de didactique — {$label}", null];
            $entrees[] = [$secondaire, $slug, 'discipline', $label,
                "Descriptif officiel de la spécialité « {$label} »", null];
        }

        foreach ($entrees as $i => [$track, $slugSpecialite, $composante, $label, $titre, $note]) {
            $specialite = $slugSpecialite === null ? null : Specialty::where('track_id', $track->id)
                ->where('slug', $slugSpecialite)->first();

            Source::create([
                'code' => sprintf('SRC-CRMEF-2025-%s-%s-%02d',
                    strtoupper(substr($track->slug, 0, 4)),
                    strtoupper(substr($composante, 0, 3)),
                    $i + 1
                ),
                'kind' => 'descriptif_officiel',
                'title_fr' => $titre,
                'authority_fr' => 'Centre national des examens scolaires et de l\'évaluation des apprentissages',
                'session_label' => 'Novembre 2025',
                'component' => $composante,
                'transposition_status' => 'identifie_non_transpose',
                'track_id' => $track->id,
                'specialty_id' => $specialite?->id,
                'discipline_label_fr' => $label,
                'coverage_note_fr' => $note,
            ]);
        }
    }
}
