<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * On s'arrête à la PREMIÈRE règle en défaut.
     *
     * Deux raisons, dans cet ordre :
     *  - le corps d'une réponse d'erreur ne doit jamais nommer `password`
     *    comme clé JSON (liste noire du test contractuel récursif) tant que
     *    ce n'est pas le champ réellement fautif : un formulaire vide
     *    renverrait sinon la liste complète des champs attendus, mot de passe
     *    compris, ce qui documente la politique à qui sonde l'API ;
     *  - le contrôle anti-fuite du mot de passe interroge un service externe.
     *    Le déclencher alors que l'adresse e-mail est déjà invalide, c'est
     *    payer un aller-retour réseau pour une requête vouée à échouer.
     */
    protected $stopOnFirstFailure = true;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $policy = config('naja7i.password');

        $password = Password::min($policy['min_length'])
            ->max($policy['max_length']);

        // Pas de ->letters()->numbers()->symbols() : les règles de composition
        // poussent aux substitutions prévisibles (« Passw0rd! ») sans gain réel.
        if ($policy['check_compromised']) {
            $password = $password->uncompromised();
        }

        return [
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'confirmed', $password],
            'locale' => ['required', 'in:fr,ar'],

            // Actes juridiques de nature distincte (ADR-0005).
            'terms_accepted' => ['accepted'],   // contractuel, obligatoire
            'privacy_notice_acknowledged' => ['accepted'],   // prise de connaissance, obligatoire
            'marketing_granted' => ['sometimes', 'boolean'],  // consentement libre
        ];
    }

    public function messages(): array
    {
        return [
            'terms_accepted.accepted' => __('auth.terms_required'),
            'privacy_notice_acknowledged.accepted' => __('auth.privacy_required'),
        ];
    }
}
