<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Services\CouvertureBanque;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Surface de rédaction : ce que la banque ne couvre pas encore.
 *
 * Cette route existe parce qu'un couple (compétence, cause) sans question sœur
 * est un TROU ÉDITORIAL, pas un cas limite à gérer dans le code. La révision
 * encaisse déjà ce manque — l'énoncé est resservi, le fait est annoncé, et le
 * palier est plafonné — mais l'encaisser indéfiniment revient à faire tourner
 * un candidat sur un seul énoncé pour une lacune qu'on sait nommer.
 *
 * Ce que la liste vaut : elle est ordonnée par NOMBRE DE CANDIDATS EN ATTENTE.
 * C'est un plan de rédaction établi par l'usage, pas par une intuition.
 *
 * L'autorisation est portée par le middleware sur la route (`questions.view`),
 * jamais par un `if` ici — c'est la règle du dépôt depuis le PAS-11.
 *
 * Le back-office Filament n'est pas ouvert (lot A4) : cette route est donc la
 * seule surface où un rédacteur peut lire ce plan aujourd'hui. Quand le
 * back-office arrivera, il consomme ce service plutôt que d'en refaire un.
 */
class BanqueAdminController extends Controller
{
    public function __construct(private readonly CouvertureBanque $couverture) {}

    public function couverture(Request $request, string $examCode): JsonResponse
    {
        $exam = Exam::where('code', $examCode)->first();

        if ($exam === null) {
            return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
        }

        $trous = $this->couverture->trous($exam);

        return response()->json([
            'data' => $trous,
            'meta' => [
                'exam_code' => $exam->code,
                'gaps' => $trous->count(),
                /* Comptés par COUPLE ET PAR LANGUE : une question est
                 * monolingue, « une sœur en français, aucune en arabe » est
                 * deux travaux et non un. Aucune question du tout empêche de
                 * composer la séance ; une seule la rend répétitive. */
                'to_write' => $this->parSeverite($trous, 'none'),
                'to_complete' => $this->parSeverite($trous, 'no_sibling'),
                'sibling_minimum' => CouvertureBanque::SOEURS_MINIMUM,
                /* Établie par la DEMANDE : un couple qui n'a jamais fait
                 * échouer personne n'est pas un trou, et n'est pas listé. */
                'scope' => 'couples attendus par au moins un candidat',
            ],
        ]);
    }

    /**
     * Combien de couples, par langue, sont à ce niveau de manque.
     *
     * @param  Collection<int, array<string, mixed>>  $trous
     * @return array<string, int>
     */
    private function parSeverite($trous, string $severite): array
    {
        return $trous
            ->flatMap(fn (array $ligne) => collect($ligne['coverage'])
                ->filter(fn (array $c) => $c['severity'] === $severite)
                ->keys()
                ->all())
            ->countBy()
            ->all();
    }
}
