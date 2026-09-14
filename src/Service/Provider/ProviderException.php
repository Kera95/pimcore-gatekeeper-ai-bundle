<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Service\Provider;

use function sprintf;

/**
 * A request that did not produce a usable response, with what the user needs to fix it:
 * the vendor's error type and message, the HTTP status, the request id for a support ticket,
 * whether a retry may help and whether the rest of the run would fail the same way.
 */
final class ProviderException extends \RuntimeException
{
    public const TYPE_TRANSPORT = 'transport_error';

    public const TYPE_INVALID_RESPONSE = 'invalid_response';

    public function __construct(
        private readonly string $type,
        string $message,
        private readonly ?int $httpStatus = null,
        private readonly ?string $requestId = null,
        private readonly bool $retryable = false,
        private readonly bool $fatal = false,
        private readonly ?int $retryAfterSeconds = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }

    /**
     * From a non-2xx Messages API response
     *
     * @param array<string, mixed>       $body    the decoded JSON body, if any
     * @param array<string, string[]>    $headers response headers, lower-cased names
     */
    public static function fromHttp(int $status, array $body, array $headers = []): self
    {
        $type = (string) ($body['error']['type'] ?? self::typeForStatus($status));
        $vendorMessage = (string) ($body['error']['message'] ?? 'no error message in the response');
        $requestId = $headers['request-id'][0] ?? ($body['request_id'] ?? null);
        $retryAfter = isset($headers['retry-after'][0]) && is_numeric($headers['retry-after'][0]) ? (int) $headers['retry-after'][0] : null;

        return new self(
            $type,
            self::hint($status, $type, $vendorMessage),
            $status,
            $requestId !== null ? (string) $requestId : null,
            self::statusIsRetryable($status),
            self::statusIsFatal($status),
            $retryAfter
        );
    }

    /**
     * From a network or timeout failure before any response
     */
    public static function fromTransport(\Throwable $previous): self
    {
        return new self(
            self::TYPE_TRANSPORT,
            sprintf('Anthropic API unreachable: %s. Check the network, a proxy, and anthropic.timeout.', $previous->getMessage()),
            null,
            null,
            true,
            false,
            null,
            $previous
        );
    }

    /**
     * From a 2xx response whose body is not what the bundle expects
     */
    public static function invalidResponse(string $problem, ?string $requestId = null): self
    {
        return new self(self::TYPE_INVALID_RESPONSE, sprintf('Unexpected response from the Anthropic API: %s.', $problem), 200, $requestId);
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * Whether the same request may succeed a moment later (rate limit, overload, network)
     */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /**
     * Whether every further request of the run would fail the same way (key, model, billing),
     * so the run should stop instead of failing object after object
     */
    public function isFatal(): bool
    {
        return $this->fatal;
    }

    public function getRetryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    /**
     * One line for the log: type, status, request id
     */
    public function describe(): string
    {
        return sprintf(
            '%s%s%s',
            $this->type,
            $this->httpStatus !== null ? ' (HTTP ' . $this->httpStatus . ')' : '',
            $this->requestId !== null ? ' request ' . $this->requestId : ''
        );
    }

    private static function typeForStatus(int $status): string
    {
        return match ($status) {
            400 => 'invalid_request_error',
            401 => 'authentication_error',
            402 => 'billing_error',
            403 => 'permission_error',
            404 => 'not_found_error',
            413 => 'request_too_large',
            429 => 'rate_limit_error',
            529 => 'overloaded_error',
            default => $status >= 500 ? 'api_error' : 'http_' . $status,
        };
    }

    private static function statusIsRetryable(int $status): bool
    {
        return $status === 429 || $status === 408 || $status >= 500;
    }

    private static function statusIsFatal(int $status): bool
    {
        return match ($status) {
            400, 401, 402, 403, 404 => true,
            default => false,
        };
    }

    /**
     * What to tell the user, per §9 of the design: the vendor message plus the thing to check
     */
    private static function hint(int $status, string $type, string $vendorMessage): string
    {
        $advice = match ($status) {
            401 => 'The API key is invalid, revoked or missing - check ANTHROPIC_API_KEY (tsf_gatekeeper_ai.anthropic.api_key).',
            402 => 'Billing problem on the Anthropic account - check the credit balance in the Anthropic console.',
            403 => 'The API key is not allowed to use this model or feature - check the key\'s permissions in the Anthropic console.',
            404 => 'The model id is unknown or not available to this account - check tsf_gatekeeper_ai.anthropic.model.',
            400 => 'The request was rejected - if this repeats, the field set or knowledge base may be producing an unsupported request; the request id helps with a bug report.',
            413 => 'The request is too large - lower tsf_gatekeeper_ai.context.max_tokens or shorten the knowledge base.',
            429 => 'Rate limited - the bundle backs off and retries; lower max_objects_per_run or run later if it persists.',
            529 => 'The API is overloaded - the bundle backs off and retries.',
            default => $status >= 500 ? 'Anthropic service error - the bundle backs off and retries.' : 'Unexpected HTTP status.',
        };

        return sprintf('Anthropic API error %s (HTTP %d): %s %s', $type, $status, $vendorMessage, $advice);
    }
}
