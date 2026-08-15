<?php

use App\Http\Controllers\Api\V1\AbonnementController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BanqueAdminController;
use App\Http\Controllers\Api\V1\CatalogueController;
use App\Http\Controllers\Api\V1\DemonstrationController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\LegalController;
use App\Http\Controllers\Api\V1\MemoireController;
use App\Http\Controllers\Api\V1\ParcoursController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ProgressionController;
use App\Http\Controllers\Api\V1\QuestionAdminController;
use App\Http\Controllers\Api\V1\SimulationController;
use App\Http\Controllers\Api\V1\SourceAdminController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — front-office candidat
|--------------------------------------------------------------------------
| Authentification par cookies de session (BFF). AssignRequestId, ResolveTenant
| et SetLocale sont appliqués globalement au groupe api (bootstrap/app.php).
*/

Route::prefix('v1')->group(function () {

    // --- Public -----------------------------------------------------------
    Route::get('legal/documents', [LegalController::class, 'documents']);

    /*
     * LES TARIFS SONT PUBLICS — décision du 11 août : la surface commerciale
     * vit dans la zone publique. Un visiteur lit les prix avant de créer un
     * compte, et la page est indexable. Exiger une session ici reviendrait à
     * cacher le prix derrière une inscription.
     */
    Route::get('plans', [AbonnementController::class, 'plans'])
        ->middleware('throttle:demonstration');

    /*
     * Catalogue public (PAS-4) : aucune authentification, aucun tenant requis
     * pour lire. Ce sont ces routes qui alimentent les pages indexables par
     * Google — le levier d'acquisition du plan à 90 jours.
     */
    Route::prefix('catalogue')->group(function () {
        Route::get('/', [CatalogueController::class, 'index']);
        Route::get('calendrier', [CatalogueController::class, 'calendar']);

        Route::get('filieres/{slug}', [CatalogueController::class, 'filiere']);

        Route::get('familles/{slug}', [CatalogueController::class, 'family']);
        Route::get('familles/{slug}/competences', [CatalogueController::class, 'competencies']);

        // PAS-4.1 — Entrée de référence du référentiel : une matrice par
        // épreuve, chacune avec ses coefficients et ses poids (ADR-0014).
        Route::get('epreuves/{code}/competences', [CatalogueController::class, 'examCompetencies']);
        Route::get('familles/{famille}/specialites/{specialite}', [CatalogueController::class, 'specialty']);
    });

    /*
     * PAS-8 — Démonstration publique de la fiche F03. Une question d'exemple
     * avec sa correction complète, pour un visiteur sans compte : c'est la
     * vitrine de ce que le produit sait faire. La réponse porte un avertissement
     * disant que rien n'a été répondu ni enregistré (ADR-0018 §5).
     */
    Route::get('demonstration/correction', [DemonstrationController::class, 'correction'])
        ->middleware('throttle:demonstration');

    // Vérification d'e-mail et mot de passe oublié : routes PUBLIQUES. Le
    // candidat clique le lien depuis son application de messagerie, souvent
    // dans un autre navigateur, donc sans session ouverte. Leur sécurité vient
    // du jeton opaque, pas de la session — et pas non plus d'une URL signée,
    // dont la signature ne survivrait pas au relais de Nitro (ADR-0008 §4).
    Route::post('auth/email/verify', [EmailVerificationController::class, 'verify'])
        ->middleware('throttle:email-verify');

    Route::post('auth/email/resend', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:email-resend');

    Route::post('auth/password/request', [PasswordResetController::class, 'request'])
        ->middleware('throttle:password-request');

    Route::post('auth/password/reset', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:password-reset');

    Route::middleware('guest')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:register');
        Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    });

    // --- Session ouverte, e-mail pas nécessairement vérifié ----------------
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::get('me/legal', [LegalController::class, 'myEvents']);
        Route::patch('me/legal/marketing', [LegalController::class, 'updateMarketing']);

        // Refus explicite plutôt qu'un 404 déroutant.
        Route::patch('me/legal/terms', [LegalController::class, 'rejectNonRevocable']);
        Route::patch('me/legal/privacy', [LegalController::class, 'rejectNonRevocable']);
    });

    // --- Session + e-mail vérifié ------------------------------------------
    // Toutes les routes métier des pas suivants (entraînement, simulateur,
    // achat) viendront dans ce groupe.
    Route::middleware(['auth:sanctum', 'verified.api'])->group(function () {

        /*
         * PAS-8 — Parcours de diagnostic, et tout ce qui rend une tentative.
         *
         * `no-store` PARTOUT OÙ UNE TENTATIVE EST RENDUE. Ces réponses portent
         * `seconds_remaining`, calculé par le serveur à l'instant de la réponse
         * (PAS-6 : l'horloge du client n'est jamais autoritative). Rejouée
         * depuis un cache, la réponse rendrait un chronomètre faux — et
         * d'autant plus faux qu'elle serait gardée longtemps.
         */
        Route::middleware('no-store')->group(function () {
            /* L'index AVANT la route à paramètre : `me/attempts` et
             * `me/attempts/{uuid}` ne se disputent aucun chemin, mais les lire
             * dans cet ordre évite qu'on croie le contraire. */
            Route::get('me/attempts', [ParcoursController::class, 'index']);

            Route::get('me/attempts/{uuid}', [ParcoursController::class, 'show']);

            Route::post('me/attempts/{uuid}/submit', [ParcoursController::class, 'submit']);

            Route::post('me/diagnostics/{examCode}', [ParcoursController::class, 'startDiagnostic'])
                ->middleware('throttle:ouverture-serie');

            // Entraînement ciblé : ce que l'ordonnance recommande devient cliquable.
            Route::post('me/training/{examCode}', [ParcoursController::class, 'startTraining'])
                ->middleware('throttle:ouverture-serie');

            /* F05 — la question miroir. À la demande, jamais servie d'office :
             * la correction n'en annonce que l'EXISTENCE. */
            Route::post('me/mirrors/{itemUuid}', [ParcoursController::class, 'startMirror'])
                ->middleware('throttle:miroir');

            /*
             * L'EXAMEN BLANC. Sous `no-store` comme tout ce qui rend une
             * tentative, et pour une raison plus aiguë qu'ailleurs : son
             * `seconds_remaining` décompte une échéance DURE. Une réponse
             * rejouée depuis un cache rendrait un chronomètre faux à un
             * candidat qui compose contre la montre.
             */
            Route::post('me/simulations/{examCode}', [SimulationController::class, 'start'])
                ->middleware('throttle:ouverture-serie');

            /* Le rapport peut CLORE la tentative quand l'échéance est passée :
             * il écrit, donc il ne se met pas en cache non plus. */
            Route::get('me/simulations/{uuid}/report', [SimulationController::class, 'show']);
        });

        Route::put('me/attempts/{uuid}/items/{itemUuid}', [ParcoursController::class, 'answer'])
            ->middleware('throttle:reponse');   // une réponse toutes les 30 s en rythme soutenu

        Route::get('me/attempts/{uuid}/correction', [ParcoursController::class, 'correction']);

        /* F07 — Rendez-vous Mémoire. La lecture n'est pas limitée comme
         * l'ouverture : consulter ce qui est dû est un geste quotidien, en
         * ouvrir la séance ne se fait qu'une fois. */
        Route::get('me/memory/{examCode}/due', [MemoireController::class, 'due']);

        Route::post('me/memory/{examCode}/session', [MemoireController::class, 'startSession'])
            ->middleware('throttle:ouverture-serie');

        // PAS-8 — Restitution : maîtrise par compétence et ordonnance.
        Route::get('me/mastery/{examCode}', [ProgressionController::class, 'mastery']);
        Route::get('me/plan/{examCode}', [ProgressionController::class, 'plan']);

        /*
         * Le profil candidat — DET-42. L'épreuve préparée se DÉCLARE.
         *
         * Ces deux routes remplacent une déduction, elles ne s'ajoutent pas à
         * côté : `GET me/attempts` dit quelle épreuve a été TOUCHÉE en dernier,
         * `GET me/profile` dit laquelle est PRÉPARÉE. La première ne sert plus
         * qu'à proposer une suggestion à confirmer quand la seconde est nulle.
         *
         * Pas de `no-store` : rien ici n'est calculé à l'instant de la réponse,
         * contrairement au chronomètre d'une tentative.
         */
        /*
         * ────────────────────────── ABONNEMENT ──────────────────────────
         *
         * Aucune de ces routes ne décide d'un droit : elles ouvrent des
         * commandes. Les droits sont posés par `AbonnementService` et lus par
         * `AccessGrant` — le mur payant ignore que ces routes existent.
         */
        Route::get('me/subscription', [AbonnementController::class, 'etat']);
        Route::get('me/orders', [AbonnementController::class, 'commandes']);

        Route::post('me/orders/coupon', [AbonnementController::class, 'coupon'])
            /* Un coupon se saisit à la main, et un code se devine par force
             * brute : c'est le geste le plus sensible de cette surface. */
            ->middleware('throttle:coupon');

        /*
         * LE PAIEMENT SIMULÉ N'EXISTE PAS EN PRODUCTION.
         *
         * La route n'est pas DÉCLARÉE là-bas : elle répond 404, ce qui est la
         * vérité — cette porte n'existe pas. `SimulatedGateway` refuse en
         * outre de s'instancier en production. Deux serrures, et c'est
         * délibéré : celle-ci protège la porte, l'autre protège la maison si
         * une porte est oubliée un jour.
         */
        if (! app()->environment('production')) {
            Route::post('me/orders/simulated', [AbonnementController::class, 'simule'])
                ->middleware('throttle:coupon');
        }

        Route::get('me/profile', [ProfileController::class, 'show']);

        Route::put('me/profile', [ProfileController::class, 'update'])
            ->middleware('throttle:profil');   // une déclaration n'est pas un geste répété
    });

    /*
     * PAS-11 — Première surface administrative.
     *
     * L'autorisation est portée par le middleware de permission, déclarée sur
     * la route : l'action est refusée avant que le contrôleur ne s'exécute, et
     * le contrôle reste lisible depuis `route:list`. Aucun `if` d'autorisation
     * dans les méthodes — c'est ce qui a manqué à l'ADR-0009 pendant neuf pas.
     */
    Route::middleware(['auth:sanctum', 'verified.api'])->prefix('admin')->group(function () {
        /* PAS-27 — la chaîne éditoriale. Chaque geste porte SA permission :
         * écrire, relire et publier ne sont pas le même métier, et le
         * référentiel du PAS-9 les distingue déjà. */
        Route::post('questions', [QuestionAdminController::class, 'store'])
            ->middleware('permission:questions.create');

        Route::patch('questions/{uuid}', [QuestionAdminController::class, 'update'])
            ->middleware('permission:questions.create');

        Route::get('questions', [QuestionAdminController::class, 'index'])
            ->middleware('permission:questions.view');

        /* AVANT la route à paramètre : « a-relire » serait sinon lu comme un
         * uuid, et le relecteur recevrait 404 sur sa propre file. */
        Route::get('questions/a-relire', [QuestionAdminController::class, 'aRelire'])
            ->middleware('permission:questions.review');

        Route::get('questions/{uuid}', [QuestionAdminController::class, 'show'])
            ->middleware('permission:questions.view');

        /*
         * PAS-33 — les trois étapes qui MANQUAIENT entre la rédaction et la
         * publication. Elles existaient dans le service depuis le PAS-5 et dans
         * Filament depuis A4a, mais pas en routes : un appelant programmatique
         * ne pouvait pas mener une question du brouillon au publié.
         *
         * CHACUNE SOUS SA PROPRE PERMISSION, et ce ne sont pas les mêmes
         * métiers : soumettre est un geste d'auteur, relire un geste de
         * réviseur, valider un geste que le référentiel du PAS-9 distingue
         * depuis toujours par `questions.validate`.
         */
        Route::post('questions/{uuid}/submit', [QuestionAdminController::class, 'submit'])
            ->middleware('permission:questions.create');

        Route::post('questions/{uuid}/review', [QuestionAdminController::class, 'review'])
            ->middleware('permission:questions.review');

        Route::post('questions/{uuid}/validate', [QuestionAdminController::class, 'validatePedagogy'])
            ->middleware('permission:questions.validate');

        Route::post('questions/{uuid}/publish', [QuestionAdminController::class, 'publish'])
            ->middleware('permission:questions.publish');

        /* DET-46 — le contrôle documentaire.  par commodité :
         * le relecteur a la source sous les yeux. Voir SourceAdminController. */
        Route::post('sources/{uuid}/verify', [SourceAdminController::class, 'verify'])
            ->middleware('permission:questions.review');

        Route::post('questions/{uuid}/retire', [QuestionAdminController::class, 'retire'])
            ->middleware('permission:questions.retire');

        /* PAS-22 — plan de rédaction : les couples (compétence, cause) que la
         * banque ne couvre pas, ordonnés par nombre de candidats en attente.
         * `questions.view` et non `questions.create` : c'est une LECTURE sur
         * l'état de la banque, et l'auteur comme le réviseur la détiennent. */
        Route::get('banque/couverture/{examCode}', [BanqueAdminController::class, 'couverture'])
            ->middleware('permission:questions.view');
    });
});
