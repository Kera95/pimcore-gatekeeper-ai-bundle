<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Unit\Service;

use Codeception\Test\Unit;
use Tsf\GatekeeperAiBundle\Service\TokenEstimator;

final class TokenEstimatorTest extends Unit
{
    public function testFourCharactersPerTokenRoundedUp(): void
    {
        $estimator = new TokenEstimator();

        self::assertSame(0, $estimator->estimate(''));
        self::assertSame(1, $estimator->estimate('abc'));
        self::assertSame(1, $estimator->estimate('abcd'));
        self::assertSame(2, $estimator->estimate('abcde'));
        self::assertSame(3, $estimator->estimate('Kopfhörer'), '9 characters, not 10 bytes');
    }
}
