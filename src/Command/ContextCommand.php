<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tsf\GatekeeperAiBundle\Service\Config\Settings;
use Tsf\GatekeeperAiBundle\Service\Context\KnowledgeBase;
use Tsf\GatekeeperAiBundle\Service\Provider\Anthropic\AnthropicModels;

use function sprintf;

#[AsCommand(
    name: 'tsf:gatekeeper:ai:context',
    description: 'Prints the assembled knowledge base with its size, estimated tokens and hash, and warns when it is too small to cache or too large to send'
)]
final class ContextCommand extends Command
{
    public function __construct(
        private readonly Settings $settings,
        private readonly KnowledgeBase $knowledgeBase,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('class', 'c', InputOption::VALUE_REQUIRED, 'Include the <Class>/ subfolder of the context folder, as a propose run for that class would')
            ->addOption('summary', 's', InputOption::VALUE_NONE, 'Only the file list and the numbers, not the text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $className = $input->getOption('class');
        $folder = $this->settings->getContextFolder();

        if (!$this->knowledgeBase->folderExists()) {
            $io->warning(sprintf('Knowledge base folder "%s" does not exist in the asset tree. Create it and upload .md files; the model gets no knowledge base until then.', $folder));

            return Command::FAILURE;
        }

        $context = $this->knowledgeBase->assemble($className);

        if ($context->isEmpty()) {
            $io->warning(sprintf(
                'No .md files in "%s"%s. Files directly in the folder and in %s/ are always read%s.',
                $folder,
                $className !== null ? ' for class ' . $className : '',
                KnowledgeBase::GLOBAL_FOLDER,
                $className !== null ? ', plus ' . $className . '/' : '; pass --class to include a <Class>/ subfolder'
            ));

            return Command::FAILURE;
        }

        if (!$input->getOption('summary')) {
            $output->write($context->getText());
            $io->newLine();
        }

        $io->section(sprintf('Knowledge base %s%s', $folder, $className !== null ? ' (class ' . $className . ')' : ''));
        $io->listing($context->getFiles());
        $io->definitionList(
            ['Files' => $context->getFileCount()],
            ['Characters' => number_format($context->getChars())],
            ['Estimated tokens' => number_format($context->getEstimatedTokens()) . ' (chars / 4; the API reports exact counts per request)'],
            ['Ceiling' => number_format($this->settings->getContextMaxTokens()) . ' tokens (tsf_gatekeeper_ai.context.max_tokens)'],
            ['Hash' => $context->getHash()]
        );

        $model = $this->settings->getModel();
        $floor = AnthropicModels::cacheFloor($model);
        if ($context->getEstimatedTokens() > $this->settings->getContextMaxTokens()) {
            $io->error(sprintf('The knowledge base (~%d tokens) is above the ceiling of %d; a propose run aborts. Trim the files or raise context.max_tokens.', $context->getEstimatedTokens(), $this->settings->getContextMaxTokens()));

            return Command::FAILURE;
        }
        if ($context->getEstimatedTokens() < $floor) {
            $io->warning(sprintf(
                'The knowledge base alone (~%d tokens) is below the %d-token cache floor of %s%s. Caching applies to the whole prefix (instructions + knowledge base + field descriptions), so it may still be cached; check the cache_read figures of a propose run.',
                $context->getEstimatedTokens(),
                $floor,
                $model,
                AnthropicModels::isKnown($model) ? '' : ' (unknown model, assuming the largest floor)'
            ));
        } else {
            $io->success(sprintf('Above the %d-token cache floor of %s; the prefix is cached across a run.', $floor, $model));
        }

        return Command::SUCCESS;
    }
}
