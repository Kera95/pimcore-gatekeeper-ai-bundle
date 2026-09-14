<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Unit\Service\Propose;

use Codeception\Test\Unit;
use Tsf\GatekeeperAiBundle\Service\Propose\ObjectSnapshot;
use Tsf\GatekeeperBundle\Service\Config\RuleSet;
use Tsf\GatekeeperBundle\Service\Emptiness\EmptinessChecker;
use Tsf\GatekeeperBundle\Service\FieldReader;
use Tsf\GatekeeperBundle\Service\LanguageProvider;

final class ObjectSnapshotHashTest extends Unit
{
    public function testTheSourceHashCoversSharedFieldsAndTheTargetLanguageOnly(): void
    {
        $snapshot = new ObjectSnapshot(new FieldReader(), new EmptinessChecker([]), new LanguageProvider(), new RuleSet([]));
        $filled = ['sku' => 'A', 'name' => 'Cable', 'title [en]' => 'Cable', 'title [de]' => 'Kabel'];

        $de = $snapshot->sourceHash($filled, 'de');
        $en = $snapshot->sourceHash($filled, 'en');
        $none = $snapshot->sourceHash($filled, '');

        self::assertNotSame($de, $en);
        self::assertSame($de, $snapshot->sourceHash($filled + ['title [fr]' => 'Câble'], 'de'), 'another language does not matter');
        self::assertSame($de, $snapshot->sourceHash(['sku' => 'A', 'name' => 'Cable', 'title [de]' => 'Kabel', 'title [en]' => 'changed'], 'de'), 'nor a change in another language');
        self::assertNotSame($de, $snapshot->sourceHash(['sku' => 'A', 'name' => 'Cable', 'title [en]' => 'Cable', 'title [de]' => 'Kabel!'], 'de'), 'a change in the target language matters');
        self::assertNotSame($de, $snapshot->sourceHash(['sku' => 'B', 'name' => 'Cable', 'title [en]' => 'Cable', 'title [de]' => 'Kabel'], 'de'), 'a change in a shared field matters');
        self::assertSame($none, $snapshot->sourceHash(['sku' => 'A', 'name' => 'Cable'], ''), 'the non-localized group ignores every language');
        self::assertSame(64, strlen($de));
    }

    public function testEnrichedFieldsAreLeftOutSoApplyingOneDoesNotStaleTheOthers(): void
    {
        $snapshot = new ObjectSnapshot(new FieldReader(), new EmptinessChecker([]), new LanguageProvider(), new RuleSet([]));
        $enrich = ['title', 'description'];

        $before = $snapshot->sourceHash(['sku' => 'A', 'name' => 'Cable'], 'de', $enrich);
        $afterTitle = $snapshot->sourceHash(['sku' => 'A', 'name' => 'Cable', 'title [de]' => 'Kabel'], 'de', $enrich);
        $afterEdit = $snapshot->sourceHash(['sku' => 'A', 'name' => 'Kabel', 'title [de]' => 'Kabel'], 'de', $enrich);

        self::assertSame($before, $afterTitle, 'an applied enriched field changes nothing');
        self::assertNotSame($before, $afterEdit, 'an edited input field does');
        self::assertNotSame($before, $snapshot->sourceHash(['sku' => 'A', 'name' => 'Cable', 'title [de]' => 'Kabel'], 'de'), 'without the exclusion the title counts');
    }
}
