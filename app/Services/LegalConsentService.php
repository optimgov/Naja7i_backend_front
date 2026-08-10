<?php

namespace App\Services;

use App\Models\LegalDocument;
use App\Models\LegalEvent;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Actes juridiques.
 *
 * REVUE PAS-2 BLOC-1 — l'état courant était recherché par type et version,
 * sans comparer la LOCALE ni l'identifiant du document. Un candidat ayant
 * accepté les CGU françaises satisfaisait donc les CGU arabes de même version :
 * la plateforme affirmait qu'il avait accompli un acte sur un texte qu'il
 * n'avait jamais reçu.
 *
 * La comparaison porte désormais sur `legal_document_id` — le document exact,
 * pas une version homonyme dans une autre langue.
 */
final class LegalConsentService
{
    public function recordTermsAcceptance(User $user, string $locale, Request $request): LegalEvent
    {
        return $this->record($user, LegalDocument::KIND_TERMS, LegalEvent::TERMS_ACCEPTED, $locale, $request);
    }

    public function recordPrivacyAcknowledgement(User $user, string $locale, Request $request): LegalEvent
    {
        return $this->record($user, LegalDocument::KIND_PRIVACY, LegalEvent::PRIVACY_ACKED, $locale, $request);
    }

    public function setMarketing(User $user, bool $granted, string $locale, Request $request): LegalEvent
    {
        return $this->record(
            $user,
            LegalDocument::KIND_MARKETING,
            $granted ? LegalEvent::MARKETING_GRANTED : LegalEvent::MARKETING_WITHDRAWN,
            $locale,
            $request
        );
    }

    /**
     * L'acte a-t-il été accompli sur le DOCUMENT EXACT en vigueur — bon type,
     * bonne version, bonne langue ?
     */
    public function hasAcceptedCurrent(User $user, string $kind, string $locale = 'fr'): bool
    {
        $document = LegalDocument::current($kind, $locale);

        $dernier = LegalEvent::where('user_id', $user->id)
            ->where('legal_document_id', $document->id)   // le document, pas sa version
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')                            // départage deux actes du même instant
            ->first();

        if ($dernier === null) {
            return false;
        }

        return $dernier->action !== LegalEvent::MARKETING_WITHDRAWN;
    }

    /** @return list<string> */
    public function pendingActions(User $user, string $locale = 'fr'): array
    {
        $enAttente = [];

        foreach ([LegalDocument::KIND_TERMS, LegalDocument::KIND_PRIVACY] as $kind) {
            if (! $this->hasAcceptedCurrent($user, $kind, $locale)) {
                $enAttente[] = $kind;
            }
        }

        return $enAttente;
    }

    private function record(
        User $user,
        string $kind,
        string $action,
        string $locale,
        Request $request,
    ): LegalEvent {
        $document = LegalDocument::current($kind, $locale);

        return LegalEvent::create([
            'user_id' => $user->id,
            'legal_document_id' => $document->id,
            'action' => $action,
            'channel' => $request->header('X-Client-Channel', 'web'),
            'ip_prefix' => $this->truncateIp($request->ip()),
            'user_agent_hmac' => $this->hmacUserAgent($request->userAgent()),
            'request_id' => $request->header('X-Request-Id'),
            'occurred_at' => now(),
        ]);
    }

    private function truncateIp(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);

            return "{$parts[0]}.{$parts[1]}.{$parts[2]}.0";
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return implode(':', array_slice(explode(':', $ip), 0, 3)).'::';
        }

        return null;
    }

    private function hmacUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        return hash_hmac('sha256', $userAgent, (string) config('app.key'));
    }
}
