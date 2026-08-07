<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Support\RequestId;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Tests CONTRACTUELS — demandés par la revue (points 7 et 8 de sa liste).
 *
 * Deux garanties que les tests unitaires ne donnent pas :
 *  1. Aucune clé interne n'apparaît nulle part dans une réponse JSON, y
 *     compris à l'intérieur d'objets imbriqués. La revue a montré que
 *     makeHidden('id') ne protège qu'une sérialisation standard.
 *  2. `request_id` est présent sur TOUTES les erreurs, quel que soit le code.
 */
class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    /** Clés qui ne doivent jamais franchir la frontière HTTP. */
    private const FORBIDDEN_KEYS = [
        'id', 'tenant_id', 'user_id', 'role_id', 'legal_document_id',
        'consent_policy_id', 'password', 'remember_token', 'provider_user_id',
        'user_agent_hmac',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'candidat@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide',
            'password_confirmation' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
            'terms_accepted' => true,
            'privacy_notice_acknowledged' => true,
            'marketing_granted' => false,
        ], $overrides);
    }

    // --- 1. Aucune clé interne exposée -----------------------------------

    public function test_aucune_cle_interne_dans_les_reponses_de_succes(): void
    {
        $this->assertNoInternalKeys($this->postJson('/api/v1/auth/register', $this->payload()));
        $this->assertNoInternalKeys($this->getJson('/api/v1/me'));
        $this->assertNoInternalKeys($this->getJson('/api/v1/me/legal'));
        $this->assertNoInternalKeys($this->getJson('/api/v1/legal/documents'));
    }

    public function test_aucune_cle_interne_dans_les_reponses_d_erreur(): void
    {
        $this->assertNoInternalKeys($this->getJson('/api/v1/me'));                       // 401
        $this->assertNoInternalKeys($this->postJson('/api/v1/auth/register', []));       // 422
        $this->assertNoInternalKeys($this->getJson('/api/v1/route-inexistante'));        // 404
    }

    // --- 2. request_id sur toutes les erreurs ----------------------------

    public function test_request_id_present_sur_401(): void
    {
        $this->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
    }

    public function test_request_id_present_sur_422(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['email' => 'pas-un-email']))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
    }

    public function test_request_id_present_sur_404(): void
    {
        $this->getJson('/api/v1/route-inexistante')
            ->assertStatus(404)
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
    }

    public function test_request_id_present_sur_409(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload());
        $this->post('/api/v1/auth/logout');

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertStatus(409)
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
    }

    public function test_le_request_id_fourni_par_le_bff_est_repris(): void
    {
        $fourni = '11111111-2222-3333-4444-555555555555';

        $response = $this->withHeaders([RequestId::HEADER => $fourni])
            ->getJson('/api/v1/me');

        $response->assertStatus(401)
            ->assertJsonPath('error.request_id', $fourni)
            ->assertHeader(RequestId::HEADER, $fourni);
    }

    public function test_un_request_id_arbitraire_du_client_est_rejete(): void
    {
        $response = $this->withHeaders([RequestId::HEADER => '<script>alert(1)</script>'])
            ->getJson('/api/v1/me');

        $this->assertNotSame(
            '<script>alert(1)</script>',
            $response->json('error.request_id')
        );
    }

    // --- Utilitaire -------------------------------------------------------

    private function assertNoInternalKeys(TestResponse $response): void
    {
        $found = [];
        $this->walk($response->json() ?? [], '', $found);

        $this->assertSame([], $found, sprintf(
            "Clé(s) interne(s) exposée(s) dans la réponse %s :\n%s",
            $response->getStatusCode(),
            implode(PHP_EOL, $found)
        ));
    }

    private function walk(mixed $node, string $path, array &$found): void
    {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            $here = $path === '' ? (string) $key : "{$path}.{$key}";

            if (is_string($key) && in_array($key, self::FORBIDDEN_KEYS, true)) {
                $found[] = $here;
            }

            $this->walk($value, $here, $found);
        }
    }
}
