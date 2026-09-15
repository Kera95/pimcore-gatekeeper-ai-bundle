<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Service\Propose;

use Pimcore\Model\DataObject\ClassDefinition;
use Tsf\GatekeeperAiBundle\Model\ClassSettings;
use Tsf\GatekeeperAiBundle\Model\FieldSpec;
use Tsf\GatekeeperAiBundle\Model\PlanGroup;
use Tsf\GatekeeperAiBundle\Model\RunPlan;
use Tsf\GatekeeperAiBundle\Service\Config\Settings;
use Tsf\GatekeeperAiBundle\Service\Field\FieldPolicy;
use Tsf\GatekeeperAiBundle\Service\Field\FieldSpecFactory;
use Tsf\GatekeeperBundle\Model\ResultRow;
use Tsf\GatekeeperBundle\Service\ResultStore;

use function count;
use function in_array;

/**
 * Turns the Gatekeeper's failing rows into groups of (class, language) with the objects and
 * fields to ask for: only fields listed under "enrich", only ones the FieldPolicy allows, only
 * the languages the class is configured for. Never scans objects itself.
 */
final class RunPlanner
{
    public const SKIP_NOT_CONFIGURED = 'class not configured under tsf_gatekeeper_ai.classes';

    public const SKIP_NOT_ENRICHED = 'field not listed under enrich';

    public const SKIP_NOT_ALLOWED = 'field type or name not allowed';

    public const SKIP_LANGUAGE = 'language not configured for the class';

    public const SKIP_NOT_REQUESTED = 'field not in --fields';

    public function __construct(
        private readonly Settings $settings,
        private readonly ResultStore $results,
        private readonly FieldSpecFactory $specs,
        private readonly FieldPolicy $policy,
    ) {
    }

    /**
     * @param string[]|null $onlyFields narrow to these field paths (--fields)
     * @param int|null      $limit      at most this many distinct objects, in reported order
     */
    public function plan(?string $className = null, ?string $profile = null, ?string $language = null, ?array $onlyFields = null, ?int $limit = null): RunPlan
    {
        $rows = $this->results->findFailing($className, $profile, $language);

        /** @var array<string, array<string, FieldSpec>> $specsByClass class => path => spec (enrichable only) */
        $specsByClass = [];
        /** @var array<string, PlanGroup> $groups "class|language" => group */
        $groups = [];
        $skipped = [];
        $objectOrder = [];

        foreach ($rows as $row) {
            $class = $this->settings->getClass($row->getClassName());
            if ($class === null) {
                $skipped[self::SKIP_NOT_CONFIGURED] = ($skipped[self::SKIP_NOT_CONFIGURED] ?? 0) + count($row->getMissing());

                continue;
            }
            $specsByClass[$class->getClassName()] ??= $this->enrichableSpecs($class);

            foreach ($row->getMissing() as $field) {
                $reason = $this->reasonToSkip($class, $specsByClass[$class->getClassName()], $row, $field, $onlyFields);
                if ($reason !== null) {
                    $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;

                    continue;
                }

                $spec = $specsByClass[$class->getClassName()][$field];
                $groupLanguage = $spec->isLocalized() ? $row->getLanguage() : '';
                $key = $class->getClassName() . '|' . $groupLanguage;
                $groups[$key] ??= new PlanGroup(
                    $class->getClassName(),
                    $groupLanguage,
                    array_filter($specsByClass[$class->getClassName()], static fn (FieldSpec $s): bool => $s->isLocalized() === ($groupLanguage !== ''))
                );
                $groups[$key]->addTarget($row->getObjectId(), $field, $row->getProfile());
                $objectOrder[$row->getObjectId()] = true;
            }
        }

        if ($limit !== null) {
            $keep = array_slice(array_keys($objectOrder), 0, max(0, $limit));
            foreach ($groups as $group) {
                $group->restrictTo($keep);
            }
        }

        ksort($groups, SORT_STRING);
        $groups = array_values(array_filter($groups, static fn (PlanGroup $group): bool => $group->getObjectCount() > 0));

        return new RunPlan($groups, $skipped);
    }

    /**
     * @param array<string, FieldSpec> $specs
     * @param string[]|null            $onlyFields
     */
    private function reasonToSkip(ClassSettings $class, array $specs, ResultRow $row, string $field, ?array $onlyFields): ?string
    {
        if (!in_array($field, $class->getEnrich(), true)) {
            return self::SKIP_NOT_ENRICHED;
        }
        if (!isset($specs[$field])) {
            return self::SKIP_NOT_ALLOWED;
        }
        if ($onlyFields !== null && !in_array($field, $onlyFields, true)) {
            return self::SKIP_NOT_REQUESTED;
        }
        if ($specs[$field]->isLocalized()) {
            if ($row->getLanguage() === '') {
                return self::SKIP_LANGUAGE;
            }
            $languages = $class->getLanguages();
            if (count($languages) > 0 && !in_array($row->getLanguage(), $languages, true)) {
                return self::SKIP_LANGUAGE;
            }
        }

        return null;
    }

    /**
     * The enrich fields of the class that exist and pass the policy, keyed by path
     *
     * @return array<string, FieldSpec>
     */
    public function enrichableSpecs(ClassSettings $class): array
    {
        try {
            $definition = ClassDefinition::getByName($class->getClassName());
        } catch (\Throwable) {
            $definition = null;
        }
        if ($definition === null) {
            return [];
        }

        $specs = [];
        foreach ($class->getEnrich() as $path) {
            $data = $this->specs->definition($definition, $path);
            if ($data === null || $this->policy->describeProblem($path, $data) !== null) {
                continue;
            }
            $spec = $this->specs->create($definition, $path);
            if ($spec !== null) {
                $specs[$path] = $spec;
            }
        }

        return $specs;
    }
}
