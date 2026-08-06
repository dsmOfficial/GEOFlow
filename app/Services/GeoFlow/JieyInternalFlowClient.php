<?php

namespace App\Services\GeoFlow;

use App\Services\Outbound\SafeOutboundHttpClient;
use Illuminate\Http\Client\Factory;
use RuntimeException;

/**
 * Jiey Internal Flow / GeoFlow 官网同步 HTTP 客户端。
 *
 * 鉴权：
 *   X-Jiey-Internal-Timestamp: {unix seconds}
 *   X-Jiey-Internal-Signature: HMAC-SHA256(secret, ts + "." + METHOD + "." + path + "." + sha256(body))
 * GET 时 body 为空字符串；签名 path 不含 query string。
 * POST 时 body 必须与签名使用的原始 JSON 字符串完全一致。
 */
final class JieyInternalFlowClient
{
    public function __construct(
        private readonly SafeOutboundHttpClient $safeHttp,
        private readonly Factory $http,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function getProjectArtifacts(int $projectId): array
    {
        if ($projectId <= 0) {
            throw new RuntimeException('Jiey project_id 无效');
        }

        $this->assertConfigured();

        $path = '/api/v1/internal/flow/projects/'.$projectId.'/artifacts';
        $response = $this->request('GET', $path);

        $code = (int) ($response['code'] ?? 0);
        $data = $response['data'] ?? null;
        if ($code !== 200 || ! is_array($data) || ! array_is_list($data)) {
            $message = is_string($response['message'] ?? null) ? (string) $response['message'] : 'invalid_artifacts_response';

            throw new RuntimeException('Jiey artifacts 响应无效: '.$message);
        }

        /** @var list<array<string, mixed>> $artifacts */
        $artifacts = [];
        foreach ($data as $row) {
            if (is_array($row)) {
                $artifacts[] = $row;
            }
        }

        return $artifacts;
    }

    /**
     * 同步文章到 Jiey 官网 CMS。
     *
     * @param  array<string, mixed>  $payload
     * @return array{code?:int,message?:mixed,data?:mixed,error?:mixed}
     */
    public function syncGeoflowArticle(array $payload): array
    {
        $this->assertConfigured();

        $path = '/api/v1/internal/geoflow/articles';
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($body) || $body === '') {
            throw new RuntimeException('Jiey 文章同步请求体编码失败');
        }

        return $this->request('POST', $path, '', $body);
    }

    public function isEnabled(): bool
    {
        return (bool) config('geoflow.jiey.enabled', false);
    }

    public function assertConfigured(): void
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException('Jiey Internal Flow 未启用（GEOFLOW_JIEY_ENABLED）');
        }

        $secret = $this->secret();
        if ($secret === '' || str_contains($secret, 'dev-shared-not-for-prod')) {
            throw new RuntimeException('Jiey Internal Flow secret 未配置或仍是占位值（GEOFLOW_JIEY_INTERNAL_SECRET）');
        }

        if ($this->apiBase() === '') {
            throw new RuntimeException('Jiey Internal Flow API Base 未配置（GEOFLOW_JIEY_API_BASE）');
        }
    }

    /**
     * 生成鉴权头；timestamp 可注入以便单测固定签名。
     *
     * @return array{timestamp:string,signature:string,payload:string}
     */
    public function sign(string $method, string $path, string $body = '', ?int $timestamp = null): array
    {
        $method = strtoupper(trim($method));
        $path = '/'.ltrim($path, '/');
        // 签名用 path 不含 query string
        $pathOnly = (string) (parse_url($path, PHP_URL_PATH) ?: $path);
        $ts = (string) ($timestamp ?? time());
        $bodyHash = hash('sha256', $body);
        $payload = $ts.'.'.$method.'.'.$pathOnly.'.'.$bodyHash;
        $signature = hash_hmac('sha256', $payload, $this->secret());

        return [
            'timestamp' => $ts,
            'signature' => $signature,
            'payload' => $payload,
        ];
    }

    /**
     * @return array{code?:int,message?:mixed,data?:mixed,error?:mixed}
     */
    private function request(string $method, string $path, string $query = '', string $body = ''): array
    {
        $method = strtoupper($method);
        $signed = $this->sign($method, $path, $body);
        $url = $this->apiBase().$path.$query;
        $timeout = max(1, (int) config('geoflow.jiey.timeout_seconds', 30));
        $maxBytes = max(1, (int) config('geoflow.jiey.max_bytes', 8 * 1024 * 1024));

        $request = $this->http->acceptJson()
            ->connectTimeout(min(8, $timeout))
            ->timeout($timeout)
            ->withHeaders([
                'X-Jiey-Internal-Timestamp' => $signed['timestamp'],
                'X-Jiey-Internal-Signature' => $signed['signature'],
                'Content-Type' => 'application/json',
            ]);

        if ($method === 'GET') {
            $response = $this->safeHttp->get($request, $url, $maxBytes);
        } elseif ($method === 'POST') {
            // 使用 withBody 保证签名 body 与实际发送字节一致
            $pending = (clone $request)->withBody($body, 'application/json');
            $response = $this->safeHttp->send($pending, 'POST', $url, [], $maxBytes);
        } else {
            throw new RuntimeException('Jiey Internal Flow 不支持的 HTTP 方法: '.$method);
        }

        $raw = $response->body();
        $decoded = $response->json();
        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf(
                'Jiey Internal Flow 返回非 JSON（http=%d body=%s）',
                $response->status(),
                mb_substr((string) $raw, 0, 200, 'UTF-8')
            ));
        }

        if ($response->status() === 401) {
            $error = is_string($decoded['error'] ?? null)
                ? (string) $decoded['error']
                : (is_string($decoded['message'] ?? null) ? (string) $decoded['message'] : 'unauthorized');

            throw new RuntimeException('Jiey Internal Flow 鉴权失败: '.$error);
        }

        if (! $response->successful()) {
            $message = is_string($decoded['message'] ?? null)
                ? (string) $decoded['message']
                : (is_string($decoded['error'] ?? null) ? (string) $decoded['error'] : mb_substr((string) $raw, 0, 300, 'UTF-8'));

            throw new RuntimeException(sprintf(
                'Jiey Internal Flow 请求失败 http=%d: %s',
                $response->status(),
                $message
            ));
        }

        return $decoded;
    }

    private function apiBase(): string
    {
        return rtrim((string) config('geoflow.jiey.api_base', ''), '/');
    }

    private function secret(): string
    {
        return (string) config('geoflow.jiey.internal_secret', '');
    }
}
