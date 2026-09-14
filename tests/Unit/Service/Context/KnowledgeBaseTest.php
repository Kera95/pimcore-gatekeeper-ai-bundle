<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Unit\Service\Context;

use Codeception\Test\Unit;
use Symfony\Component\Config\Definition\Processor;
use Tsf\GatekeeperAiBundle\DependencyInjection\Configuration;
use Tsf\GatekeeperAiBundle\Service\Config\Settings;
use Tsf\GatekeeperAiBundle\Service\Context\KnowledgeBase;
use Tsf\GatekeeperAiBundle\Service\TokenEstimator;

final class KnowledgeBaseTest extends Unit
{
    public function testConcatenatesFilesInPathOrderUnderHeadings(): void
    {
        $kb = $this->knowledgeBase([
            'tone.md' => "Friendly.\r\nShort.\r\n",
            '_global/brand.md' => "\n# Brand\n\nRedBit.\n\n",
            'Product/rules.md' => 'Never invent specs.',
        ]);

        $context = $kb->assemble('Product');

        self::assertSame(
            "## Product/rules.md\n\nNever invent specs.\n\n## _global/brand.md\n\n# Brand\n\nRedBit.\n\n## tone.md\n\nFriendly.\nShort.\n",
            $context->getText()
        );
        self::assertSame(['Product/rules.md', '_global/brand.md', 'tone.md'], $context->getFiles());
        self::assertSame(3, $context->getFileCount());
        self::assertSame(hash('sha256', $context->getText()), $context->getHash());
        self::assertSame(mb_strlen($context->getText()), $context->getChars());
        self::assertSame((int) ceil(mb_strlen($context->getText()) / 4), $context->getEstimatedTokens());
        self::assertFalse($context->isEmpty());
    }

    public function testTheSameFilesGiveTheSameHash(): void
    {
        $files = ['a.md' => 'A', 'b.md' => 'B'];

        self::assertSame($this->knowledgeBase($files)->assemble()->getHash(), $this->knowledgeBase(array_reverse($files, true))->assemble()->getHash());
        self::assertNotSame($this->knowledgeBase($files)->assemble()->getHash(), $this->knowledgeBase(['a.md' => 'A', 'b.md' => 'B!'])->assemble()->getHash());
    }

    public function testRequestsTheRootGlobalAndClassFolders(): void
    {
        $kb = $this->knowledgeBase([]);

        $kb->assemble();
        self::assertSame([['/gatekeeper/context/', '/gatekeeper/context/_global/']], $kb->requested);

        $kb->assemble('Product');
        self::assertSame(['/gatekeeper/context/', '/gatekeeper/context/_global/', '/gatekeeper/context/Product/'], $kb->requested[1]);
    }

    public function testNoFilesIsAnEmptyContext(): void
    {
        $context = $this->knowledgeBase([])->assemble();

        self::assertTrue($context->isEmpty());
        self::assertSame('', $context->getText());
        self::assertSame([], $context->getFiles());
        self::assertSame(0, $context->getEstimatedTokens());
        self::assertSame(hash('sha256', ''), $context->getHash());
    }

    /**
     * @param array<string, string> $files
     */
    private function knowledgeBase(array $files): KnowledgeBase
    {
        $settings = new Settings((new Processor())->processConfiguration(new Configuration(), [[]]));

        return new class ($settings, new TokenEstimator(), $files) extends KnowledgeBase {
            /** @var array<int, string[]> */
            public array $requested = [];

            /**
             * @param array<string, string> $files
             */
            public function __construct(Settings $settings, TokenEstimator $estimator, private readonly array $files)
            {
                parent::__construct($settings, $estimator);
            }

            protected function readFiles(array $folders): array
            {
                $this->requested[] = $folders;

                return $this->files;
            }
        };
    }
}
