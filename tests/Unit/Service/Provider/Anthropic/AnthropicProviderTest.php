<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Unit\Service\Provider\Anthropic;

use Codeception\Test\Unit;
use Psr\Log\AbstractLogger;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tsf\GatekeeperAiBundle\DependencyInjection\Configuration;
use Tsf\GatekeeperAiBundle\Model\EnrichmentRequest;
use Tsf\GatekeeperAiBundle\Service\Config\Settings;
use Tsf\GatekeeperAiBundle\Service\Provider\Anthropic\AnthropicProvider;
use Tsf\GatekeeperAiBundle\Service\Provider\ProviderException;

final class AnthropicProviderTest extends Unit
{
    /** @var array<int, array{method: string, url: string, options: array<string, mixed>}> */
    private array $sent = [];

    /** @var string[] */
    private array $logs = [];

    /** @var int[] */
    private array $pauses = [];

    public function testPayloadCarriesSystemBlocksWithOneBreakpointAndTheSchema(): void
    {
        $provider = $this->provider([], []);
        $request = $this->request();

        $payload = $provider->payload($request, true);

        self::assertSame('claude-opus-5', $payload['model']);
        self::assertSame(2048, $payload['max_tokens']);
        self::assertSame([
            ['type' => 'text', 'text' => 'instructions'],
            ['type' => 'text', 'text' => 'knowledge'],
            ['type' => 'text', 'text' => 'fields', 'cache_control' => ['type' => 'ephemeral']],
        ], $payload['system']);
        self::assertSame([['role' => 'user', 'content' => 'the object']], $payload['messages']);
        self::assertSame(['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $request->getSchema()]], $payload['output_config']);

        self::assertArrayNotHasKey('max_tokens', $provider->payload($request, false), 'count_tokens takes no max_tokens');
    }

    public function testPayloadHonoursCachingSettings(): void
    {
        $oneHour = $this->provider(['anthropic' => ['cache_ttl' => '1h']], [])->payload($this->request(), true);
        self::assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $oneHour['system'][2]['cache_control']);

        $off = $this->provider(['anthropic' => ['prompt_caching' => false]], [])->payload($this->request(), true);
        self::assertArrayNotHasKey('cache_control', $off['system'][2]);
    }

    public function testGenerateParsesValuesUsageAndRequestId(): void
    {
        $provider = $this->provider([], [new MockResponse(json_encode([
            'model' => 'claude-opus-5-20260601',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => '{"title":"Lenovo LOQ","product_type":"simple"}']],
            'usage' => ['input_tokens' => 120, 'output_tokens' => 30, 'cache_read_input_tokens' => 2500, 'cache_creation_input_tokens' => 0],
        ]) ?: '', ['http_code' => 200, 'response_headers' => ['request-id' => 'req_abc']])]);

        $response = $provider->generate($this->request());

        self::assertTrue($response->isUsable());
        self::assertSame(['title' => 'Lenovo LOQ', 'product_type' => 'simple'], $response->getValues());
        self::assertSame('claude-opus-5-20260601', $response->getModel());
        self::assertSame('req_abc', $response->getRequestId());
        self::assertSame(2500, $response->getUsage()->getCacheReadTokens());
        self::assertSame(2620, $response->getUsage()->getTotalInputTokens());

        self::assertCount(1, $this->sent);
        self::assertSame('https://api.anthropic.com/v1/messages', $this->sent[0]['url']);
        $headers = $this->sent[0]['options']['normalized_headers'];
        self::assertSame(['x-api-key: sk-test'], $headers['x-api-key']);
        self::assertSame(['anthropic-version: 2023-06-01'], $headers['anthropic-version']);
        self::assertStringNotContainsString('sk-test', implode("\n", $this->logs), 'the key is never logged');
    }

    public function testRefusalAndTruncationAreResponsesNotExceptions(): void
    {
        $provider = $this->provider([], [
            new MockResponse(json_encode(['stop_reason' => 'refusal', 'stop_details' => ['category' => 'cyber'], 'content' => [], 'usage' => ['input_tokens' => 5, 'output_tokens' => 0]]) ?: ''),
            new MockResponse(json_encode(['stop_reason' => 'max_tokens', 'content' => [['type' => 'text', 'text' => '{"title":"Len']], 'usage' => ['input_tokens' => 5, 'output_tokens' => 2048]]) ?: ''),
        ]);

        $refused = $provider->generate($this->request());
        self::assertTrue($refused->isRefused());
        self::assertFalse($refused->isUsable());
        self::assertSame([], $refused->getValues());
        self::assertSame('the model refused (cyber)', $refused->describeProblem());
        self::assertSame(5, $refused->getUsage()->getInputTokens(), 'a refusal is still billed');

        $truncated = $provider->generate($this->request());
        self::assertTrue($truncated->isTruncated());
        self::assertSame([], $truncated->getValues());
        self::assertStringContainsString('max_tokens', (string) $truncated->describeProblem());
    }

    public function testANonJsonAnswerIsAnInvalidResponse(): void
    {
        $provider = $this->provider([], [new MockResponse(json_encode(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Sure! Here']], 'usage' => []]) ?: '')]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('not a JSON object');
        $provider->generate($this->request());
    }

    public function testAuthenticationErrorsAreFatalAndNotRetried(): void
    {
        $provider = $this->provider([], [new MockResponse(json_encode(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']]) ?: '', ['http_code' => 401])]);

        try {
            $provider->generate($this->request());
            self::fail('expected a ProviderException');
        } catch (ProviderException $e) {
            self::assertSame('authentication_error', $e->getType());
            self::assertTrue($e->isFatal());
            self::assertSame([], $this->pauses);
            self::assertCount(1, $this->sent);
            self::assertStringContainsString('ANTHROPIC_API_KEY', $e->getMessage());
        }

        self::assertStringContainsString('Gatekeeper AI: authentication_error (HTTP 401)', implode("\n", $this->logs));
    }

    public function testAHugeRetryAfterIsCapped(): void
    {
        $provider = $this->provider(['anthropic' => ['max_retries' => 1]], [
            new MockResponse('{"error":{"type":"rate_limit_error","message":"slow"}}', ['http_code' => 429, 'response_headers' => ['retry-after' => '86400']]),
            new MockResponse(json_encode(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => '{"title":"ok"}']], 'usage' => []]) ?: ''),
        ]);

        $provider->generate($this->request());

        self::assertSame([AnthropicProvider::MAX_RETRY_AFTER_SECONDS], $this->pauses);
    }

    public function testRateLimitsAreRetriedWithRetryAfterThenBackoff(): void
    {
        $provider = $this->provider(['anthropic' => ['max_retries' => 3]], [
            new MockResponse('{"error":{"type":"rate_limit_error","message":"slow"}}', ['http_code' => 429, 'response_headers' => ['retry-after' => '3']]),
            new MockResponse('{"error":{"type":"overloaded_error","message":"busy"}}', ['http_code' => 529]),
            new MockResponse(json_encode(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => '{"title":"ok"}']], 'usage' => []]) ?: ''),
        ]);

        $response = $provider->generate($this->request());

        self::assertSame(['title' => 'ok'], $response->getValues());
        self::assertSame([3, 4], $this->pauses, 'retry-after first, then 2^attempt');
        self::assertCount(3, $this->sent);
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $provider = $this->provider(['anthropic' => ['max_retries' => 1]], [
            new MockResponse('{"error":{"type":"overloaded_error","message":"busy"}}', ['http_code' => 529]),
            new MockResponse('{"error":{"type":"overloaded_error","message":"busy"}}', ['http_code' => 529]),
        ]);

        try {
            $provider->generate($this->request());
            self::fail('expected a ProviderException');
        } catch (ProviderException $e) {
            self::assertSame('overloaded_error', $e->getType());
            self::assertTrue($e->isRetryable());
            self::assertSame([2], $this->pauses);
            self::assertCount(2, $this->sent);
        }
    }

    public function testTransportFailuresAreRetried(): void
    {
        $provider = $this->provider(['anthropic' => ['max_retries' => 1]], [
            new MockResponse('', ['error' => 'Could not resolve host']),
            new MockResponse(json_encode(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => '{"title":"ok"}']], 'usage' => []]) ?: ''),
        ]);

        self::assertSame(['title' => 'ok'], $provider->generate($this->request())->getValues());
        self::assertSame([2], $this->pauses);
    }

    public function testCountTokens(): void
    {
        $provider = $this->provider([], [new MockResponse('{"input_tokens": 2731}')]);

        self::assertSame(2731, $provider->countTokens($this->request()));
        self::assertSame('https://api.anthropic.com/v1/messages/count_tokens', $this->sent[0]['url']);
        self::assertArrayNotHasKey('max_tokens', json_decode((string) $this->sent[0]['options']['body'], true));
    }

    public function testWithoutAKeyNothingIsSent(): void
    {
        $provider = $this->provider(['anthropic' => ['api_key' => '']], []);

        try {
            $provider->countTokens($this->request());
            self::fail('expected a ProviderException');
        } catch (ProviderException $e) {
            self::assertSame('authentication_error', $e->getType());
            self::assertTrue($e->isFatal());
            self::assertSame([], $this->sent);
        }
    }

    private function request(): EnrichmentRequest
    {
        return new EnrichmentRequest(['instructions', 'knowledge', 'fields'], 'the object', [
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string'], 'product_type' => ['type' => 'string', 'enum' => ['simple']]],
            'required' => ['title', 'product_type'],
            'additionalProperties' => false,
        ]);
    }

    /**
     * @param array<string, mixed> $config
     * @param MockResponse[]       $responses
     */
    private function provider(array $config, array $responses): AnthropicProvider
    {
        $config = array_replace_recursive(['anthropic' => ['api_key' => 'sk-test']], $config);
        $settings = new Settings((new Processor())->processConfiguration(new Configuration(), [$config]));

        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->sent[] = ['method' => $method, 'url' => $url, 'options' => $options];
            $next = array_shift($responses);
            self::assertNotNull($next, 'more requests than mocked responses');

            return $next;
        });

        $logger = new class (function (string $line): void { $this->logs[] = $line; }) extends AbstractLogger {
            public function __construct(private readonly \Closure $record)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                ($this->record)($level . ': ' . $message);
            }
        };

        return new class ($client, $settings, $logger, function (int $seconds): void { $this->pauses[] = $seconds; }) extends AnthropicProvider {
            public function __construct(MockHttpClient $client, Settings $settings, AbstractLogger $logger, private readonly \Closure $onPause)
            {
                parent::__construct($client, $settings, $logger);
            }

            protected function pause(int $seconds): void
            {
                ($this->onPause)($seconds);
            }
        };
    }
}
