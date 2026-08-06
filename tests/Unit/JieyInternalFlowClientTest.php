<?php

namespace Tests\Unit;

use App\Services\GeoFlow\JieyInternalFlowClient;
use App\Services\Outbound\SafeOutboundHttpClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class JieyInternalFlowClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'geoflow.jiey.enabled' => true,
            'geoflow.jiey.api_base' => 'https://api.gongxingglobal.com',
            'geoflow.jiey.internal_secret' => 'test-jiey-secret',
            'geoflow.jiey.timeout_seconds' => 10,
            'geoflow.jiey.max_bytes' => 1024 * 1024,
        ]);
    }

    public function test_sign_matches_official_hmac_formula_without_query_string(): void
    {
        $client = app(JieyInternalFlowClient::class);
        $signed = $client->sign('GET', '/api/v1/internal/flow/projects/51/artifacts', '', 1_700_000_000);

        $bodyHash = hash('sha256', '');
        $payload = '1700000000.GET./api/v1/internal/flow/projects/51/artifacts.'.$bodyHash;
        $expected = hash_hmac('sha256', $payload, 'test-jiey-secret');

        $this->assertSame($payload, $signed['payload']);
        $this->assertSame('1700000000', $signed['timestamp']);
        $this->assertSame($expected, $signed['signature']);
    }

    public function test_sign_strips_query_string_from_path(): void
    {
        $client = app(JieyInternalFlowClient::class);
        $signed = $client->sign(
            'GET',
            '/api/v1/internal/flow/projects/51/artifacts?includeStages=true',
            '',
            1_700_000_001
        );

        $this->assertStringContainsString(
            './api/v1/internal/flow/projects/51/artifacts.',
            $signed['payload']
        );
        $this->assertStringNotContainsString('includeStages', $signed['payload']);
    }

    public function test_assert_configured_rejects_disabled_or_placeholder_secret(): void
    {
        $client = app(JieyInternalFlowClient::class);

        config(['geoflow.jiey.enabled' => false]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('未启用');
        $client->assertConfigured();
    }

    public function test_get_project_artifacts_sends_hmac_headers_and_parses_list(): void
    {
        Http::fake([
            'https://api.gongxingglobal.com/api/v1/internal/flow/projects/51/artifacts' => Http::response([
                'code' => 200,
                'message' => 'success',
                'data' => [
                    ['id' => '22', 'title' => '产品定义', 'published' => 1],
                ],
            ]),
        ]);

        $artifacts = app(JieyInternalFlowClient::class)->getProjectArtifacts(51);

        $this->assertCount(1, $artifacts);
        $this->assertSame('22', $artifacts[0]['id']);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://api.gongxingglobal.com/api/v1/internal/flow/projects/51/artifacts') {
                return false;
            }

            $ts = $request->header('X-Jiey-Internal-Timestamp')[0] ?? '';
            $sig = $request->header('X-Jiey-Internal-Signature')[0] ?? '';
            if ($ts === '' || $sig === '') {
                return false;
            }

            $payload = $ts.'.GET./api/v1/internal/flow/projects/51/artifacts.'.hash('sha256', '');
            $expected = hash_hmac('sha256', $payload, 'test-jiey-secret');

            return hash_equals($expected, $sig);
        });
    }

    public function test_get_project_artifacts_rejects_unauthorized(): void
    {
        Http::fake([
            'https://api.gongxingglobal.com/*' => Http::response([
                'error' => 'signature_invalid',
            ], 401),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('鉴权失败');

        app(JieyInternalFlowClient::class)->getProjectArtifacts(51);
    }

    public function test_client_resolves_from_container(): void
    {
        $client = app(JieyInternalFlowClient::class);

        $this->assertInstanceOf(JieyInternalFlowClient::class, $client);
        $this->assertInstanceOf(SafeOutboundHttpClient::class, app(SafeOutboundHttpClient::class));
        $this->assertInstanceOf(Factory::class, app(Factory::class));
    }
}
