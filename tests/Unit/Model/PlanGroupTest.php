<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Unit\Model;

use Codeception\Test\Unit;
use Tsf\GatekeeperAiBundle\Model\FieldSpec;
use Tsf\GatekeeperAiBundle\Model\PlanGroup;
use Tsf\GatekeeperAiBundle\Model\RunPlan;

final class PlanGroupTest extends Unit
{
    public function testCollectsTargetsPerObjectWithoutDuplicatesAndOnlyKnownFields(): void
    {
        $group = $this->group();

        $group->addTarget(12, 'title', 'default');
        $group->addTarget(12, 'title', 'print');
        $group->addTarget(12, 'description', 'print');
        $group->addTarget(12, 'unknown', 'default');
        $group->addTarget(13, 'description', 'default');

        self::assertSame([12 => ['title', 'description'], 13 => ['description']], $group->getTargets());
        self::assertSame('default', $group->profileOf(12, 'title'), 'the first reporting profile wins');
        self::assertSame('print', $group->profileOf(12, 'description'));
        self::assertSame(['title', 'description'], array_map(static fn (FieldSpec $s): string => $s->getPath(), $group->specsFor(12)));
        self::assertSame(['description'], array_map(static fn (FieldSpec $s): string => $s->getPath(), $group->specsFor(13)));
        self::assertSame(2, $group->getObjectCount());
        self::assertSame(3, $group->getFieldCount());
        self::assertSame('Product [de]', $group->getLabel());
    }

    public function testRestrictToKeepsOnlyTheGivenObjects(): void
    {
        $group = $this->group();
        $group->addTarget(12, 'title', 'default');
        $group->addTarget(13, 'title', 'default');
        $group->addTarget(14, 'title', 'default');

        $group->restrictTo([14, 12, 99]);

        self::assertSame([12, 14], array_keys($group->getTargets()));
    }

    public function testRunPlanTotalsCountDistinctObjectsAcrossGroups(): void
    {
        $de = $this->group();
        $de->addTarget(12, 'title', 'default');
        $de->addTarget(13, 'title', 'default');
        $en = new PlanGroup('Product', 'en', $de->getSpecs());
        $en->addTarget(12, 'description', 'default');

        $plan = new RunPlan([$de, $en], ['field not listed under enrich' => 4]);

        self::assertSame(2, $plan->getObjectCount());
        self::assertSame(3, $plan->getRequestCount());
        self::assertSame(3, $plan->getFieldCount());
        self::assertFalse($plan->isEmpty());
        self::assertTrue((new RunPlan([], []))->isEmpty());
    }

    private function group(): PlanGroup
    {
        return new PlanGroup('Product', 'de', [
            'title' => new FieldSpec('title', FieldSpec::TYPE_INPUT, 'Title', null, true, 80, [], null),
            'description' => new FieldSpec('description', FieldSpec::TYPE_TEXTAREA, 'Description', null, true, null, [], null),
        ]);
    }
}
