<?php

namespace App\Services;

use App\Models\LegalDocument;
use App\Models\LegalEvent;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Enregistrement et lecture des actes juridiques.
 *
 * Le point central (BLOC-4) : l'état courant se calcule TOUJOURS par rapport
 * au document actuellement publié. Une acceptation de la v1 ne satisfait pas
 * la v2 — c'est précisément le défaut de la conception initiale, qui lisait
 * « la dernière ligne par type ».
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
     * L'utilisateur a-t-il accompli l'acte requis sur la version ACTUELLEMENT
     * publiée ? Une nouvelle version fait automatiquement repasser à false.
     */
    public function hasAcceptedCurrent(User $user, string $kind, string $locale = 'fr'): bool
    {
        $current = LegalDocument::current($kind, $locale);

        $last = LegalEvent::where('user_id', $user->id)
            ->whereHas('document', fn ($q) => $q->where('kind', $kind)->where('version', $current->version))
            ->orderByDesc('occurred_at')
            ->first();

        if ($last === null) {
            return false;
        }

        // Pour le marketing, le dernier acte peut être un retrait.
        return $last->action !== LegalEvent::MARKETING_WITHDRAWN;
    }

    /**
     * Actes juridiques que l'utilisateur doit encore accomplir sur les versions
     * publiées. Alimente le blocage applicatif lorsqu'une nouvelle version des
     * CGU ou de la politique est mise en ligne.
     *
     * @return list<string> kinds en attente
     */
    public function pendingActions(User $user, string $locale = 'fr'): array
    {
        $pending = [];

        foreach ([LegalDocument::KIND_TERMS, LegalDocument::KIND_PRIVACY] as $kind) {
            if (! $this->hasAcceptedCurrent($user, $kind, $locale)) {
                $pending[] = $kind;
            }
        }

        return $pending;
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

    /**
     * IP tronquée : /24 en IPv4, /48 en IPv6.
     *
     * L'IP est une donnée personnelle. La preuve principale est le couple
     * utilisateur + document + version + empreinte + horodatage ; l'IP n'est
     * qu'un élément complémentaire. Conserver l'adresse complète sans nécessité
     * démontrée irait contre le principe de minimisation.
     */
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
            $blocks = explode(':', $ip);

            return implode(':', array_slice($blocks, 0, 3)).'::';
        }

        return null;
    }

    /**
     * HMAC et non hash nu : un user-agent a une diversité faible, un simple
     * SHA-256 se recalculerait par dictionnaire en quelques secondes.
     */
    private function hmacUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        return hash_hmac('sha256', $userAgent, (string) config('app.key'));
    }
}
