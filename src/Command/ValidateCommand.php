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
use Tsf\GatekeeperAiBundle\Service\Config\SettingsValidator;
use Tsf\GatekeeperAiBundle\Service\Prompt\SampleRequestFactory;
use Tsf\GatekeeperAiBundle\Service\Provider\Anthropic\AnthropicModels;
use Tsf\GatekeeperAiBundle\Service\Provider\EnrichmentProviderInterface;
use Tsf\GatekeeperAiBundle\Service\Provider\ProviderException;

use function count;
use function sprintf;

#[AsCommand(
    name: 'tsf:gatekeeper:ai:validate',
    description: 'Checks the tsf_gatekeeper_ai configuration: API key, model pricing, knowledge base folder, classes and fields against the Gatekeeper rules; --live also asks the API'
)]
final class ValidateCommand extends Command
{
    public function __construct(
        private readonly Settings $settings,
        private readonly SettingsValidator $validator,
        private readonly SampleRequestFactory $sampleRequests,
        private readonly EnrichmentProviderInterface $provider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('live', 'l', InputOption::VALUE_NONE, 'Send the assembled prompt prefix to the token counting endpoint: proves the key and the model work and reports the exact prefix size. Free, generates nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $failed = 0;

        $label = sprintf(
            'provider %s, model %s, knowledge base %s',
            $this->settings->getProvider(),
            $this->settings->getModel(),
            $this->settings->getContextFolder()
        );
        $failed += $this->report($io, $label, $this->validator->validateGlobal());

        $classes = $this->settings->getClasses();
        if (count($classes) === 0) {
            $io->warning('No classes configured under tsf_gatekeeper_ai.classes.');
        }

        foreach ($classes as $class) {
            $label = sprintf(
                '%s (enrich: %s%s)',
                $class->getClassName(),
                implode(', ', $class->getEnrich()),
                count($class->getLanguages()) > 0 ? '; languages: ' . implode(', ', $class->getLanguages()) : ''
            );
            $failed += $this->report($io, $label, $this->validator->validateClass($class));
        }

        if ($failed > 0) {
            $io->error(sprintf('%d check(s) failed.', $failed));

            return Command::FAILURE;
        }

        if ($input->getOption('live') && !$this->live($io)) {
            return Command::FAILURE;
        }

        $io->success($input->getOption('live') ? 'The configuration is valid and the API accepts it.' : 'The configuration is valid.');

        return Command::SUCCESS;
    }

    /**
     * Counts the tokens of a request shaped like the first one a run would send
     */
    private function live(SymfonyStyle $io): bool
    {
        $request = $this->sampleRequests->create();

        try {
            $tokens = $this->provider->countTokens($request);
        } catch (ProviderException $e) {
            $io->writeln(sprintf('<error>ERROR</error> live check via %s (%s)', $this->provider->getName(), $this->provider->getModel()));
            $io->listing([$e->getMessage() . ($e->getRequestId() !== null ? ' Request id: ' . $e->getRequestId() . '.' : '')]);

            return false;
        }

        $model = $this->provider->getModel();
        $floor = AnthropicModels::cacheFloor($model);
        $caching = $this->settings->getAnthropic()['prompt_caching'];
        $io->writeln(sprintf('<info>OK</info>    live check via %s: key valid, model %s reachable, sample request %d tokens', $this->provider->getName(), $model, $tokens));
        $io->listing([
            sprintf('prefix hash %s', $request->getPrefixHash()),
            $caching
                ? sprintf('prompt caching %s (floor %d tokens for %s)', $tokens >= $floor ? 'active' : 'inactive: the prefix is below the floor', $floor, $model)
                : 'prompt caching disabled (tsf_gatekeeper_ai.anthropic.prompt_caching)',
        ]);

        return true;
    }

    /**
     * @param array{problems: string[], warnings: string[]} $result
     *
     * @return int 1 when the check failed
     */
    private function report(SymfonyStyle $io, string $label, array $result): int
    {
        $lines = array_merge(
            $result['problems'],
            array_map(static fn (string $warning): string => 'warning: ' . $warning, $result['warnings'])
        );

        if (count($result['problems']) > 0) {
            $io->writeln(sprintf('<error>ERROR</error> %s', $label));
        } elseif (count($result['warnings']) > 0) {
            $io->writeln(sprintf('<comment>WARN</comment>  %s', $label));
        } else {
            $io->writeln(sprintf('<info>OK</info>    %s', $label));
        }

        if (count($lines) > 0) {
            $io->listing($lines);
        }

        return count($result['problems']) > 0 ? 1 : 0;
    }
}
