<?php
declare(strict_types=1);

namespace App\Command;

use App\Exception\TmsApiException;
use App\Service\TmsProjectSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;

#[AsCommand(name: 'app:tms:sync', description: 'Synchroniseer bruiklenen, tentoonstellingen, objecten en actoren via de TMS API.')]
final class SyncTmsProjectsCommand extends Command
{
    public function __construct(private readonly TmsProjectSync $sync)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $trace = $output->isVerbose() ? static function (string $message) use ($output): void {
            $output->writeln('[' . date('H:i:s') . '] ' . OutputFormatter::escape($message));
        } : null;
        try {
            $result = $this->sync->synchronize($trace);
        } catch (\Throwable $error) {
            // Default cron output stays compact; response previews require explicit verbosity.
            $reason = $error instanceof TmsApiException || $error instanceof \UnexpectedValueException ? $error->getMessage()
                : (is_int($error->getCode()) && $error->getCode() >= 400 && $error->getCode() <= 599 ? 'API HTTP ' . $error->getCode()
                    : 'Controleer API-configuratie, bereikbaarheid en de database-migratie (' . get_debug_type($error) . ').');
            $output->writeln('<error>TMS-import mislukt. Geen gedeeltelijke import opgeslagen. ' . OutputFormatter::escape($reason) . '</error>');
            return Command::FAILURE;
        }
        if ($result['status'] !== 'completed') {
            $output->writeln($result['status'] === 'disabled' ? 'TMS-import uitgeschakeld (EXTERNAL_LOANS_ENABLED=0).'
                : 'TMS-import overgeslagen: er draait al een import.');
            return Command::SUCCESS;
        }
        $output->writeln(sprintf('TMS-import voltooid. Projecten: %d (%d nieuw). Objecten: %d (%d nieuw). Koppelingen: %d. Ontbrekend in TMS: %d projecten, %d koppelingen.',
            $result['projects'], $result['projects_created'], $result['objects'], $result['objects_created'],
            $result['links'], $result['projects_missing'], $result['links_missing']));
        if ($result['optional_disabled'] !== []) {
            $output->writeln('Optionele datasets uitgeschakeld in de API: ' . implode(', ', $result['optional_disabled']) . '.');
        }
        $output->writeln(sprintf('Actoren: %d (%d nieuw). Actorkoppelingen: %d.',
            $result['actors'], $result['actors_created'], $result['actor_links']));
        if (in_array('parties', $result['optional_disabled'], true)) {
            $output->writeln('Actoren niet opgehaald: de API meldt parties als uitgeschakeld. Werk loan_projects.php bij. Eerdere TMS-actorkoppelingen zijn niet actief voor nieuwe rapporten.');
        }
        return Command::SUCCESS;
    }
}
