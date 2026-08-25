<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * PAS-3.1 — Aucune clé de traduction ne doit atteindre le candidat.
 *
 * `lang/fr/validation.php` et `lang/ar/validation.php` étaient absents : Laravel
 * retombait sur la clé elle-même et l'écran d'inscription affichait
 * « validation.min.string » sous le champ mot de passe. La recette FRONT-1 l'a
 * relevé en point 10.
 *
 * Le défaut est traître parce qu'il est invisible côté serveur : la réponse est
 * un 422 parfaitement formé, avec le bon champ et le bon code. Seul le TEXTE
 * est faux. Aucun test d'API classique ne l'aurait vu — ils vérifient les
 * statuts, les codes et les champs, jamais la chaîne rendue.
 *
 * D'où la forme de ces tests : on inspecte le corps brut de la réponse, et on
 * vérifie l'intégralité du catalogue de messages, pas seulement les règles
 * employées aujourd'hui.
 */
class ValidationMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());
        Notification::fake();

        // La politique impose le contrôle anti-fuite (ADR-0007), qui interroge
        // un service externe. On le neutralise : ces tests portent sur le TEXTE
        // des messages, et un appel réseau les rendrait lents et intermittents.
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Amal',
            'last_name' => 'El Mansouri',
            'academic_level' => 'Licence',
            'address' => 'Rabat',
            'email' => 'candidat@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide',
            'password_confirmation' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
            'terms_accepted' => true,
            'privacy_notice_acknowledged' => true,
            'marketing_granted' => false,
        ], $overrides);
    }

    private function register(array $overrides = [], string $langue = 'fr')
    {
        return $this->withHeader('Accept-Language', $langue)
            ->postJson('/api/v1/auth/register', $this->payload($overrides));
    }

    /** Les clés de `lang/en/validation.php`, hors blocs propres à chaque langue. */
    private function clesDuCatalogue(): array
    {
        $aplatir = function (array $table, string $prefixe = '') use (&$aplatir): array {
            $cles = [];
            foreach ($table as $nom => $valeur) {
                $cle = $prefixe === '' ? (string) $nom : "$prefixe.$nom";
                if (in_array($cle, ['custom', 'attributes'], true)) {
                    continue;   // réécritures et libellés : propres à chaque langue
                }
                $cles = is_array($valeur)
                    ? array_merge($cles, $aplatir($valeur, $cle))
                    : array_merge($cles, [$cle]);
            }

            return $cles;
        };

        return $aplatir(require base_path('lang/en/validation.php'));
    }

    // --- Le cas qui a motivé le correctif ---------------------------------

    public function test_un_mot_de_passe_trop_court_ne_renvoie_aucune_cle_de_traduction(): void
    {
        $reponse = $this->register([
            'password' => '12345678',
            'password_confirmation' => '12345678',
        ]);

        $reponse->assertStatus(422);

        // Le corps ENTIER, pas seulement le champ fautif : une clé brute peut
        // aussi bien se loger dans `message` que dans `details`.
        $this->assertStringNotContainsString(
            'validation.',
            $reponse->getContent(),
            "Une clé de traduction brute a franchi la frontière HTTP. C'est exactement "
            .'le défaut du point 10 de la recette FRONT-1 : le candidat lit '
            .'« validation.min.string » sous le champ mot de passe.'
        );

        // Et le message est bien celui qu'on attend, pas une chaîne vide.
        $reponse->assertJsonPath('error.details.0.field', 'password');
        $this->assertSame(
            'Le mot de passe doit contenir au moins 12 caractères.',
            $reponse->json('error.details.0.messages.0')
        );
    }

    public function test_le_meme_refus_est_lisible_en_arabe(): void
    {
        $reponse = $this->register([
            'password' => '12345678',
            'password_confirmation' => '12345678',
        ], langue: 'ar');

        $reponse->assertStatus(422);
        $this->assertStringNotContainsString('validation.', $reponse->getContent());

        $message = $reponse->json('error.details.0.messages.0');
        $this->assertSame('يجب أن تحتوي كلمة المرور على 12 أحرف على الأقل.', $message);

        // Repli silencieux : `fallback_locale` vaut `fr`. Une clé arabe absente
        // n'afficherait pas la clé mais la phrase FRANÇAISE, au milieu d'une
        // interface arabe — un défaut bien plus discret, donc plus durable.
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $message);
    }

    // --- Toutes les règles de l'inscription -------------------------------

    /**
     * Le point 10 ne portait que sur `min.string`. Une seule règle traduite ne
     * prouve rien des autres : chaque règle a sa propre clé, et chacune peut
     * manquer indépendamment.
     */
    public function test_aucune_regle_de_l_inscription_ne_renvoie_de_cle_de_traduction(): void
    {
        // `auth/register` est plafonné à 6 requêtes par minute (throttle:6,1).
        // Ce balayage en émet dix-huit : sans cette mise à l'écart, le test
        // mesurerait le plafond au lieu des messages. Le plafond lui-même reste
        // couvert par les tests de limitation de tentatives.
        $this->withoutMiddleware(ThrottleRequests::class);

        $cas = [
            'adresse manquante' => ['email' => ''],
            'adresse invalide' => ['email' => 'pas-une-adresse'],
            'mot de passe manquant' => ['password' => '', 'password_confirmation' => ''],
            'confirmation différente' => ['password_confirmation' => 'autre-chose-entierement'],
            'mot de passe trop long' => [
                'password' => str_repeat('a', 200),
                'password_confirmation' => str_repeat('a', 200),
            ],
            'langue non supportée' => ['locale' => 'es'],
            'conditions refusées' => ['terms_accepted' => false],
            'confidentialité refusée' => ['privacy_notice_acknowledged' => false],
            'consentement non booléen' => ['marketing_granted' => 'peut-être'],
        ];

        foreach ($cas as $intitule => $charge) {
            foreach (['fr', 'ar'] as $langue) {
                $reponse = $this->register($charge, $langue);

                $reponse->assertStatus(422, "Cas « $intitule » ($langue) : refus attendu.");

                $this->assertStringNotContainsString(
                    'validation.',
                    $reponse->getContent(),
                    "Cas « $intitule » en $langue : clé de traduction brute dans la réponse."
                );
            }
        }
    }

    // --- Le catalogue entier ----------------------------------------------

    /**
     * Garde structurelle. Les tests ci-dessus ne couvrent que les règles
     * employées aujourd'hui ; une règle ajoutée demain dans un FormRequest
     * réintroduirait le défaut sans que rien ne le signale. Ici, toute clé du
     * catalogue Laravel non traduite fait échouer la CI.
     */
    public function test_le_catalogue_de_validation_est_integralement_traduit(): void
    {
        $cles = $this->clesDuCatalogue();

        $this->assertGreaterThan(100, count($cles), 'Catalogue de référence introuvable ou vide.');

        foreach (['fr', 'ar'] as $langue) {
            App::setLocale($langue);
            $manquantes = [];

            foreach ($cles as $cle) {
                $ligne = __("validation.$cle");

                // Laravel renvoie la clé elle-même quand la ligne n'existe pas.
                if ($ligne === "validation.$cle" || trim((string) $ligne) === '') {
                    $manquantes[] = $cle;
                }
            }

            $this->assertSame(
                [],
                $manquantes,
                "Clés non traduites en $langue : ".implode(', ', $manquantes)
            );
        }
    }

    /**
     * Les champs doivent être nommés en clair. Sans `attributes`, le message
     * dit « Le champ terms_accepted doit être accepté. » — le candidat n'a
     * aucune raison de connaître nos noms de colonnes.
     */
    public function test_les_champs_sont_nommes_en_clair_dans_les_deux_langues(): void
    {
        $champs = ['email', 'password', 'terms_accepted', 'privacy_notice_acknowledged'];

        foreach (['fr', 'ar'] as $langue) {
            App::setLocale($langue);

            foreach ($champs as $champ) {
                $libelle = __("validation.attributes.$champ");

                $this->assertNotSame(
                    "validation.attributes.$champ",
                    $libelle,
                    "Le champ « $champ » n'a pas de libellé lisible en $langue."
                );
                $this->assertStringNotContainsString(
                    '_',
                    $libelle,
                    "Le libellé de « $champ » en $langue laisse voir un nom technique : $libelle"
                );
            }
        }
    }
}
