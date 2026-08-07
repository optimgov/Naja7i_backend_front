<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LegalDocument;
use App\Models\LegalEvent;
use App\Services\LegalConsentService;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LegalController extends Controller
{
    public function __construct(private readonly LegalConsentService $legal) {}

    /** PUBLIC : le frontend doit afficher les textes avant la création du compte. */
    public function documents(Request $request): JsonResponse
    {
        $locale = in_array($request->query('locale'), ['fr', 'ar'], true)
            ? $request->query('locale')
            : 'fr';

        $documents = collect(LegalDocument::KINDS)->map(function (string $kind) use ($locale) {
            $doc = LegalDocument::current($kind, $locale);

            return [
                'kind' => $doc->kind,
                'version' => $doc->version,
                'locale' => $doc->locale,
                'title' => $doc->title,
                'summary' => $doc->summary,
                'document_url' => $doc->document_url,
                'checksum' => $doc->checksum,
                'required' => $kind !== LegalDocument::KIND_MARKETING,
                'provisional' => $doc->isProvisional(),
                'published_at' => $doc->published_at?->toIso8601String(),
            ];
        });

        return response()->json(['data' => $documents->values()]);
    }

    /** Historique complet des actes de l'utilisateur — la preuve, en clair. */
    public function myEvents(Request $request): JsonResponse
    {
        $events = LegalEvent::where('user_id', $request->user()->id)
            ->with('document')
            ->orderByDesc('occurred_at')
            ->get()
            ->map(fn (LegalEvent $e) => [
                'action' => $e->action,
                'document_kind' => $e->document->kind,
                'document_version' => $e->document->version,
                'document_locale' => $e->document->locale,
                'checksum' => $e->document->checksum,
                'channel' => $e->channel,
                'occurred_at' => $e->occurred_at->toIso8601String(),
            ]);

        return response()->json([
            'data' => $events,
            'meta' => [
                'pending_actions' => $this->legal->pendingActions($request->user(), $request->user()->locale),
                'marketing_granted' => $this->legal->hasAcceptedCurrent(
                    $request->user(), LegalDocument::KIND_MARKETING, $request->user()->locale
                ),
            ],
        ]);
    }

    /**
     * Seul le consentement marketing se modifie ici — c'est le seul des trois
     * qui soit un consentement au sens juridique, donc librement révocable.
     *
     * Les CGU et la politique de confidentialité ne se « retirent » pas : leur
     * refus équivaut à cesser d'utiliser le service, ce qui relève du parcours
     * de suppression de compte (export préalable, délai de rétractation).
     */
    public function updateMarketing(Request $request): JsonResponse
    {
        $validated = $request->validate(['granted' => ['required', 'boolean']]);

        $user = $request->user();

        $this->legal->setMarketing($user, (bool) $validated['granted'], $user->locale, $request);

        return $this->myEvents($request);
    }

    /** Refus explicite et lisible si le frontend tente de retirer les CGU. */
    public function rejectNonRevocable(): JsonResponse
    {
        return ApiError::make(
            'LEGAL_ACT_NOT_REVOCABLE',
            __('auth.legal_not_revocable'),
            422
        );
    }
}
