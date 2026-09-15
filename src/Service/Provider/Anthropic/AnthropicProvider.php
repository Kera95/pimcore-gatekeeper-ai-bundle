<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Service\Provider\Anthropic;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Tsf\GatekeeperAiBundle\Model\EnrichmentRequest;
use Tsf\GatekeeperAiBundle\Model\EnrichmentResponse;
use Tsf\GatekeeperAiBundle\Model\Usage;
use Tsf\GatekeeperAiBundle\Service\Config\Settings;
use Tsf\GatekeeperAiBundle\Service\Provider\EnrichmentProviderInterface;
use Tsf\GatekeeperAiBundle\Service\Provider\ProviderException;

use function count;
use function is_array;
use function is_string;
use function sprintf;

/**
 * The Messages API over symfony/http-client: system blocks with one cache breakpoint on the
 * last static block, structured output through output_config.format, the usage fields read
 * back, retries with backoff for rate limits and outages, and typed exceptions for everything
 * else. Two endpoints, no SDK.
 */
class AnthropicProvider implements EnrichmentProviderInterface
{
    public const NAME = 'anthropic';

    public const BASE_URL = 'https://api.anthropic.com';

    public const API_VERSION = '2023-06-01';

    public const MAX_BACKOFF_SECONDS = 30;

    /**
     * Longest pause a retry-after header is honoured for; beyond that the run should fail
     * instead of blocking the process
     */
    public const MAX_RETRY_AFTER_SECONDS = 120;

    /**
     * The request-id response header, merged into the decoded body under this key
     */
    public const REQUEST_ID_KEY = '_request_id';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getModel(): string
    {
        return $this->settings->getModel();
    }

    public function generate(EnrichmentRequest $request): EnrichmentResponse
    {
        $data = $this->send('/v1/messages', $this->payload($request, true));

        $stopReason = (string) ($data['stop_reason'] ?? '');
        $usage = Usage::fromApi(is_array($data['usage'] ?? null) ? $data['usage'] : []);
        $model = (string) ($data['model'] ?? $this->getModel());
        $requestId = isset($data[self::REQUEST_ID_KEY]) ? (string) $data[self::REQUEST_ID_KEY] : null;

        if ($stopReason === EnrichmentResponse::STOP_REFUSAL) {
            $category = $data['stop_details']['category'] ?? null;

            return new EnrichmentResponse([], $usage, $model, $stopReason, $requestId, is_string($category) ? $category : null);
        }

        $text = '';
        foreach (is_array($data['content'] ?? null) ? $data['content'] : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        if ($stopReason !== EnrichmentResponse::STOP_END_TURN) {
            return new EnrichmentResponse([], $usage, $model, $stopReason === '' ? 'none' : $stopReason, $requestId);
        }

        $values = json_decode($text, true);
        if (!is_array($values)) {
            throw ProviderException::invalidResponse('the text block is not a JSON object', $requestId);
        }

        return new EnrichmentResponse($values, $usage, $model, $stopReason, $requestId);
    }

    public function countTokens(EnrichmentRequest $request): int
    {
        $data = $this->send('/v1/messages/count_tokens', $this->payload($request, false));

        if (!isset($data['input_tokens'])) {
            throw ProviderException::invalidResponse('count_tokens returned no input_tokens');
        }

        return (int) $data['input_tokens'];
    }

    /**
     * The request body. The prefix blocks go into "system" in order with the cache breakpoint on
     * the last one, the object into the single user message, the schema into output_config.
     *
     * @return array<string, mixed>
     */
    public function payload(EnrichmentRequest $request, bool $forGeneration): array
    {
        $anthropic = $this->settings->getAnthropic();
        $blocks = array_values($request->getPrefixBlocks());
        $system = [];
        foreach ($blocks as $index => $text) {
            $block = ['type' => 'text', 'text' => $text];
            if ($anthropic['prompt_caching'] && $index === count($blocks) - 1) {
                $block['cache_control'] = $anthropic['cache_ttl'] === '1h'
                    ? ['type' => 'ephemeral', 'ttl' => '1h']
                    : ['type' => 'ephemeral'];
            }
            $system[] = $block;
        }

        $payload = [
            'model' => $anthropic['model'],
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $request->getUserMessage()]],
            'output_config' => [
                'effort' => $anthropic['effort'],
                'format' => ['type' => 'json_schema', 'schema' => $request->getSchema()],
            ],
        ];
        if ($forGeneration) {
            $payload['max_tokens'] = $anthropic['max_tokens'];
        }

        return $payload;
    }

    /**
     * POSTs, retries what may pass on a second attempt, maps everything else to a ProviderException
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed> the decoded 2xx body
     */
    private function send(string $path, array $payload): array
    {
        $apiKey = $this->settings->getApiKey();
        if ($apiKey === null) {
            throw new ProviderException('authentication_error', 'No API key: set ANTHROPIC_API_KEY in the environment (or tsf_gatekeeper_ai.anthropic.api_key).', null, null, false, true);
        }

        $anthropic = $this->settings->getAnthropic();
        $attempts = $anthropic['max_retries'] + 1;
        $this->logger->debug('Gatekeeper AI: request to ' . $path, ['payload' => $payload]);

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->post($path, $payload, $apiKey, $anthropic['timeout']);
            } catch (ProviderException $e) {
                if (!$e->isRetryable() || $attempt >= $attempts) {
                    $this->logger->error('Gatekeeper AI: ' . $e->describe() . ' - ' . $e->getMessage());

                    throw $e;
                }

                $seconds = $e->getRetryAfterSeconds() !== null
                    ? min(self::MAX_RETRY_AFTER_SECONDS, $e->getRetryAfterSeconds())
                    : min(self::MAX_BACKOFF_SECONDS, 2 ** $attempt);
                $this->logger->warning(sprintf('Gatekeeper AI: %s, retrying in %ds (attempt %d of %d).', $e->describe(), $seconds, $attempt, $attempts));
                $this->pause($seconds);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload, string $apiKey, int $timeout): array
    {
        try {
            $response = $this->httpClient->request('POST', self::BASE_URL . $path, [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => self::API_VERSION,
                    'content-type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => $timeout,
                'max_duration' => $timeout * 2,
            ]);
            $status = $response->getStatusCode();
            $body = $this->decode($response);
        } catch (HttpClientException $e) {
            throw ProviderException::fromTransport($e);
        }

        $headers = $response->getHeaders(false);
        if ($status < 200 || $status >= 300) {
            throw ProviderException::fromHttp($status, $body, $headers);
        }
        if (isset($headers['request-id'][0])) {
            $body[self::REQUEST_ID_KEY] = $headers['request-id'][0];
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $content = $response->getContent(false);
        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function pause(int $seconds): void
    {
        sleep($seconds);
    }
}
