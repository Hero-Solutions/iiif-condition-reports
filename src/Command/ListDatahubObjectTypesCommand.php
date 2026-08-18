<?php

declare(strict_types=1);

namespace App\Command;

use App\Datahub\StreamHttpAdapter;
use Phpoaipmh\Client;
use Phpoaipmh\Endpoint;
use Phpoaipmh\Exception\HttpException;
use Phpoaipmh\Exception\OaipmhException;
use SimpleXMLElement;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(name: 'app:datahub:object-types')]
final class ListDatahubObjectTypesCommand extends Command
{
    public function __construct(
        private readonly ParameterBagInterface $parameters,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('url', InputArgument::OPTIONAL, 'Datahub basis-URL. Standaard uit config.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop na dit aantal records.')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'Taal voor objecttype-termen.', 'nl');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->config();
        $endpoint = $this->endpoint($input, $config);
        $limit = $this->positiveIntOption($input, 'limit');
        $language = $this->textOption($input, 'language') ?? 'nl';
        $objectTypes = [];
        $seen = 0;

        try {
            foreach ($endpoint->listRecords($config['metadata_prefix']) as $record) {
                if ($limit !== null && $seen >= $limit) {
                    break;
                }

                $seen++;

                if ((string) $record->header['status'] === 'deleted' || !isset($record->metadata)) {
                    continue;
                }

                foreach ($this->objectTypes($record, $config['namespace'], $language) as $objectType) {
                    $objectTypes[$objectType] = true;
                }
            }
        } catch (OaipmhException|HttpException $exception) {
            $output->writeln('<error>OAI-PMH fout: ' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $objectTypes = array_keys($objectTypes);
        natcasesort($objectTypes);

        foreach ($objectTypes as $objectType) {
            $output->writeln($objectType);
        }

        return Command::SUCCESS;
    }

    private function config(): array
    {
        $config = $this->parameters->get('datahub');

        if (!is_array($config)) {
            throw new \RuntimeException('Datahub configuratie ontbreekt.');
        }

        return $config;
    }

    private function endpoint(InputInterface $input, array $config): Endpoint
    {
        $url = trim((string) ($input->getArgument('url') ?: $config['url']));
        $url = rtrim($url, '/');
        $url = str_ends_with($url, '/oai') ? $url : $url . '/oai';

        return new Endpoint(new Client($url, new StreamHttpAdapter()));
    }

    /**
     * @return list<string>
     */
    private function objectTypes(SimpleXMLElement $record, string $namespace, string $language): array
    {
        $data = $record->metadata->children($namespace, true);
        $query = sprintf(
            'descendant::%1$s:descriptiveMetadata[@xml:lang="%2$s"]/%1$s:objectClassificationWrap/%1$s:objectWorkTypeWrap/%1$s:objectWorkType/%1$s:term[not(@xml:lang) or @xml:lang="%2$s"]',
            $namespace,
            $language,
        );

        $matches = $data->xpath($query) ?: [];
        $objectTypes = [];

        foreach ($matches as $match) {
            $objectType = trim((string) $match);

            if ($objectType !== '' && $objectType !== 'n/a') {
                $objectTypes[] = $objectType;
            }
        }

        return array_values(array_unique($objectTypes));
    }

    private function textOption(InputInterface $input, string $name): ?string
    {
        $value = trim((string) $input->getOption($name));

        return $value === '' ? null : $value;
    }

    private function positiveIntOption(InputInterface $input, string $name): ?int
    {
        $value = $this->textOption($input, $name);

        if ($value === null) {
            return null;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }
}
