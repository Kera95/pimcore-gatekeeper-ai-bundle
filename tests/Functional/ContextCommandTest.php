<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Functional;

use Pimcore\Model\Asset;
use Tsf\GatekeeperAiBundle\Service\Context\KnowledgeBase;
use Tsf\GatekeeperAiBundle\Tests\Support\FunctionalTestCase;

final class ContextCommandTest extends FunctionalTestCase
{
    private const FOLDER = '/gatekeeper/context';

    protected function tearDown(): void
    {
        Asset::getByPath('/gatekeeper')?->delete();

        parent::tearDown();
    }

    public function testFailsWithoutTheFolder(): void
    {
        $tester = $this->runCommand('tsf:gatekeeper:ai:context');

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('does not exist in the asset tree', $tester->getDisplay());
    }

    public function testFailsWithAnEmptyFolder(): void
    {
        Asset\Service::createFolderByPath(self::FOLDER);
        $this->upload('/other/ignored.md', 'not in the context folder');

        $tester = $this->runCommand('tsf:gatekeeper:ai:context');

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('No .md files in "/gatekeeper/context"', $tester->getDisplay());
    }

    public function testAssemblesRootGlobalAndClassFilesFromRealAssets(): void
    {
        $this->upload(self::FOLDER . '/tone.md', "Friendly.\n");
        $this->upload(self::FOLDER . '/_global/brand.md', '# Brand');
        $this->upload(self::FOLDER . '/GkProduct/rules.md', 'Never invent specs.');
        $this->upload(self::FOLDER . '/GkCategory/rules.md', 'Category only.');
        $this->upload(self::FOLDER . '/notes.txt', 'not markdown');
        $this->upload(self::FOLDER . '/_global/deep/nested.md', 'one level only');

        /** @var KnowledgeBase $kb */
        $kb = $this->service(KnowledgeBase::class);

        self::assertTrue($kb->folderExists());
        self::assertSame(['_global/brand.md', 'tone.md'], $kb->assemble()->getFiles());
        self::assertSame(['GkProduct/rules.md', '_global/brand.md', 'tone.md'], $kb->assemble('GkProduct')->getFiles());
        self::assertSame("## GkProduct/rules.md\n\nNever invent specs.\n\n## _global/brand.md\n\n# Brand\n\n## tone.md\n\nFriendly.\n", $kb->assemble('GkProduct')->getText());

        $tester = $this->runCommand('tsf:gatekeeper:ai:context', ['--class' => 'GkProduct']);
        $output = $tester->getDisplay();
        self::assertSame(0, $tester->getStatusCode(), $output);
        self::assertStringContainsString('## GkProduct/rules.md', $output, 'the text is printed by default');
        self::assertStringContainsString('Files', $output);
        self::assertStringContainsString($kb->assemble('GkProduct')->getHash(), $output);
        self::assertStringContainsString('below the 512-token cache floor of claude-opus-5', $output);

        $summary = $this->runCommand('tsf:gatekeeper:ai:context', ['--summary' => true])->getDisplay();
        self::assertStringNotContainsString('Never invent specs.', $summary);
        self::assertStringContainsString('_global/brand.md', $summary);
    }

    public function testValidateWarnsAboutAnEmptyFolder(): void
    {
        Asset\Service::createFolderByPath(self::FOLDER);

        $output = $this->runCommand('tsf:gatekeeper:ai:validate')->getDisplay();

        self::assertStringContainsString('has no .md files', $output);
    }

    private function upload(string $path, string $content): void
    {
        $folder = Asset\Service::createFolderByPath(dirname($path));
        $asset = new Asset();
        $asset->setParent($folder);
        $asset->setFilename(basename($path));
        $asset->setData($content);
        $asset->save();
    }
}
