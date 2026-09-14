<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Unit\Service\Provider;

use Codeception\Test\Unit;
use Tsf\GatekeeperAiBundle\Service\Provider\ProviderException;

final class ProviderExceptionTest extends Unit
{
    /**
     * @dataProvider statuses
     */
    public function testMapsStatusToTypeRetryabilityAndHint(int $status, string $type, bool $retryable, bool $fatal, string $hintPart): void
    {
        $e = ProviderException::fromHttp($status, ['error' => ['message' => 'vendor says no']], ['request-id' => ['req_1']]);

        self::assertSame($type, $e->getType());
        self::assertSame($status, $e->getHttpStatus());
        self::assertSame('req_1', $e->getRequestId());
        self::assertSame($retryable, $e->isRetryable(), 'retryable');
        self::assertSame($fatal, $e->isFatal(), 'fatal');
        self::assertStringContainsString('vendor says no', $e->getMessage());
        self::assertStringContainsString($hintPart, $e->getMessage());
        self::assertSame(sprintf('%s (HTTP %d) request req_1', $type, $status), $e->describe());
    }

    /**
     * @return iterable<string, array{0: int, 1: string, 2: bool, 3: bool, 4: string}>
     */
    public static function statuses(): iterable
    {
        yield '400' => [400, 'invalid_request_error', false, true, 'request was rejected'];
        yield '401' => [401, 'authentication_error', false, true, 'ANTHROPIC_API_KEY'];
        yield '402' => [402, 'billing_error', false, true, 'credit balance'];
        yield '403' => [403, 'permission_error', false, true, 'permissions'];
        yield '404' => [404, 'not_found_error', false, true, 'anthropic.model'];
        yield '413' => [413, 'request_too_large', false, false, 'context.max_tokens'];
        yield '429' => [429, 'rate_limit_error', true, false, 'Rate limited'];
        yield '500' => [500, 'api_error', true, false, 'service error'];
        yield '529' => [529, 'overloaded_error', true, false, 'overloaded'];
    }

    public function testPrefersTheVendorTypeAndReadsRetryAfter(): void
    {
        $e = ProviderException::fromHttp(429, ['error' => ['type' => 'rate_limit_error', 'message' => 'slow down'], 'request_id' => 'req_body'], ['retry-after' => ['7']]);

        self::assertSame('rate_limit_error', $e->getType());
        self::assertSame(7, $e->getRetryAfterSeconds());
        self::assertSame('req_body', $e->getRequestId(), 'the body id is the fallback when the header is missing');
    }

    public function testTransportErrorsAreRetryableAndNotFatal(): void
    {
        $e = ProviderException::fromTransport(new \RuntimeException('Could not resolve host'));

        self::assertSame(ProviderException::TYPE_TRANSPORT, $e->getType());
        self::assertTrue($e->isRetryable());
        self::assertFalse($e->isFatal());
        self::assertNull($e->getHttpStatus());
        self::assertStringContainsString('Could not resolve host', $e->getMessage());
        self::assertSame('transport_error', $e->describe());
    }

    public function testInvalidResponseIsNeitherRetryableNorFatal(): void
    {
        $e = ProviderException::invalidResponse('no JSON', 'req_2');

        self::assertSame(ProviderException::TYPE_INVALID_RESPONSE, $e->getType());
        self::assertFalse($e->isRetryable());
        self::assertFalse($e->isFatal());
        self::assertSame('invalid_response (HTTP 200) request req_2', $e->describe());
    }
}
