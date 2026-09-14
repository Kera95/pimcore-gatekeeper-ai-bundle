<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tsf\GatekeeperAiBundle\Service\Config\Settings;
use Tsf\GatekeeperAiBundle\Service\Config\SettingsValidator;

use function count;
use function sprintf;

#[AsCommand(
    name: 'tsf:gatekeeper:ai:validate',
    description: 'Checks the tsf_gatekeeper_ai configuration: API key, model pricing, knowledge base folder, classes and fields against the Gatekeeper rules'
)]
final class ValidateCommand extends Command
{
    public function __construct(
        private readonly Settings $settings,
        private readonly SettingsValidator $validator,
    ) {
        parent::__construct();
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

        $io->success('The configuration is valid.');

        return Command::SUCCESS;
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
