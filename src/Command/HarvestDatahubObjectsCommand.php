<?php

declare(strict_types=1);

namespace App\Command;

use App\Datahub\StreamHttpAdapter;
use App\Entity\IIIFManifest;
use App\Entity\ObjectManifest;
use App\Entity\ObjectRecord;
use Doctrine\ORM\EntityManagerInterface;
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

#[AsCommand(name: 'app:datahub:harvest')]
final class HarvestDatahubObjectsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ParameterBagInterface $parameters,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('url', InputArgument::OPTIONAL, 'Datahub basis-URL. Standaard uit config.')
            ->addOption('username', null, InputOption::VALUE_REQUIRED, 'Basic auth gebruikersnaam.')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Basic auth wachtwoord.')
            ->addOption('record-id', null, InputOption::VALUE_REQUIRED, 'Sync een enkel OAI-PMH record.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop na dit aantal records.')
            ->addOption('flush-size', null, InputOption::VALUE_REQUIRED, 'Flush batchgrootte.', 100)
            ->addOption('ca-file', null, InputOption::VALUE_REQUIRED, 'Optioneel CA-bestand voor cURL.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->config();
        $endpoint = $this->endpoint($input, $config);
        $recordId = $this->textOption($input, 'record-id');
        $limit = $this->positiveIntOption($input, 'limit');
        $flushSize = $this->positiveIntOption($input, 'flush-size') ?? 100;

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $seen = 0;

        try {
            if ($recordId !== null) {
                $record = $this->recordNode($endpoint->getRecord($recordId, $config['metadata_prefix']));
                $result = $record === null ? 'skipped' : $this->syncRecord($record, $config);
                $this->countResult($result, $created, $updated, $skipped);
            } else {
                foreach ($endpoint->listRecords($config['metadata_prefix']) as $record) {
                    $seen++;

                    if ($limit !== null && $seen > $limit) {
                        break;
                    }

                    $result = $this->syncRecord($record, $config);
                    $this->countResult($result, $created, $updated, $skipped);

                    if ($seen % $flushSize === 0) {
                        $this->entityManager->flush();
                        $this->entityManager->clear();
                        $output->writeln(sprintf('Verwerkt: %d', $seen));
                    }
                }
            }

            $this->entityManager->flush();
        } catch (OaipmhException|HttpException $exception) {
            $output->writeln('<error>OAI-PMH fout: ' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Datahub sync klaar. Nieuw: %d, bijgewerkt: %d, overgeslagen: %d.</info>',
            $created,
            $updated,
            $skipped,
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array{
     *     url: string,
     *     language: string,
     *     namespace: string,
     *     metadata_prefix: string,
     *     placeholder_images: list<string>,
     *     data_definition: array<string, array<string, mixed>>
     * }
     */
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

        $username = $this->textOption($input, 'username');
        $password = $this->textOption($input, 'password');
        $caFile = $this->textOption($input, 'ca-file');

        return new Endpoint(new Client($url, new StreamHttpAdapter($username, $password, $caFile)));
    }

    private function syncRecord(SimpleXMLElement $record, array $config): string
    {
        if ((string) $record->header['status'] === 'deleted' || !isset($record->metadata)) {
            return 'skipped';
        }

        $data = $record->metadata->children($config['namespace'], true);
        $sourceData = $this->extractData($data, $config);
        $inventoryNumber = $sourceData['id'] ?? null;

        if ($inventoryNumber === null || trim($inventoryNumber) === '') {
            return 'skipped';
        }

        unset($sourceData['id']);

        $this->normalizeDates($sourceData);
        $this->normalizeImageData($sourceData, $config['placeholder_images']);

        $recordIdentifier = trim((string) $record->header->identifier);
        $syncedAt = new \DateTimeImmutable();
        $sourceData['_datahub_record_id'] = $recordIdentifier;
        $sourceData['_datahub_synced_at'] = $syncedAt->format(\DateTimeInterface::ATOM);

        $repository = $this->entityManager->getRepository(ObjectRecord::class);
        $object = $repository->findOneBy(['inventoryNumber' => $inventoryNumber]);
        $result = $object instanceof ObjectRecord ? 'updated' : 'created';

        if (!$object instanceof ObjectRecord) {
            $object = new ObjectRecord($inventoryNumber, $this->firstText($sourceData, ['title_nl', 'title_en'], $inventoryNumber));
        }

        if ($object->getSyncStatus() === ObjectRecord::SYNC_LOCAL_CHANGES) {
            $object->setSourceData($sourceData);
            $this->entityManager->persist($object);
            $this->syncObjectManifest($object, $sourceData);

            return $result;
        }

        $object->applyDatahubSync(
            inventoryNumber: $inventoryNumber,
            externalId: $recordIdentifier !== '' ? $recordIdentifier : $inventoryNumber,
            sourceData: $sourceData,
            title: $this->firstText($sourceData, ['title_nl', 'title_en'], $inventoryNumber),
            creator: $this->firstText($sourceData, ['creator_nl', 'creator_en']),
            publisher: $this->firstText($sourceData, ['publisher']),
            objectType: ObjectRecord::guessObjectTypeFromDatahub(
                $this->firstText($sourceData, ['object_type_nl']),
                $this->firstText($sourceData, ['object_type_en']),
            ),
            description: $this->firstText($sourceData, ['description_nl', 'description_en']),
            currentLocation: $this->firstText($sourceData, ['current_location']),
            syncedAt: $syncedAt,
        );

        $this->entityManager->persist($object);
        $this->syncObjectManifest($object, $sourceData);

        return $result;
    }

    private function syncObjectManifest(ObjectRecord $object, array $sourceData): void
    {
        $manifestUrl = trim((string) ($sourceData['iiif_manifest_url'] ?? ''));

        if ($manifestUrl === '') {
            return;
        }

        $manifest = $this->entityManager
            ->getRepository(IIIFManifest::class)
            ->findOneBy(['manifestId' => $manifestUrl]);

        if (!$manifest instanceof IIIFManifest) {
            $manifest = new IIIFManifest();
        }

        $manifest
            ->setManifestId($manifestUrl)
            ->setSource(IIIFManifest::SOURCE_DATAHUB)
            ->setSourceUrl($manifestUrl)
            ->setThumbnailUrl($sourceData['thumbnail'] ?? null)
            ->setTitle($object->getTitle())
            ->setData([]);

        $this->entityManager->persist($manifest);

        $link = $this->entityManager
            ->getRepository(ObjectManifest::class)
            ->findOneBy([
                'objectRecord' => $object,
                'manifest' => $manifest,
            ]);

        if (!$link instanceof ObjectManifest) {
            $this->entityManager->persist(new ObjectManifest($object, $manifest, ObjectManifest::ROLE_SOURCE));
        }
    }

    private function extractData(SimpleXMLElement $data, array $config): array
    {
        $sourceData = [];

        foreach ($config['data_definition'] as $key => $definition) {
            $value = array_key_exists('parent_xpath', $definition)
                ? $this->extractParentValue($data, $definition, $config)
                : $this->extractValue($data, $definition, $config, $key);

            if ($value !== null) {
                $sourceData[$key] = $value;
            }
        }

        return $sourceData;
    }

    private function extractParentValue(SimpleXMLElement $data, array $definition, array $config): ?string
    {
        $value = null;
        $parents = $data->xpath($this->buildXpath($definition['parent_xpath'], $config));

        if (!$parents) {
            return null;
        }

        foreach ($parents as $parent) {
            $mainValues = $parent->xpath($this->buildXpath($definition['xpath_main'], $config)) ?: [];

            foreach ($mainValues as $mainValue) {
                $text = trim((string) $mainValue);

                if ($text !== '' && $text !== 'n/a') {
                    $value .= ($value === null ? '' : PHP_EOL) . $text;
                }
            }

            $subValues = $parent->xpath($this->buildXpath($definition['xpath_sub'], $config)) ?: [];

            foreach ($subValues as $subValue) {
                $text = trim((string) $subValue);

                if ($text !== '' && $text !== 'n/a') {
                    $value .= ' (' . $text . ')';
                }
            }
        }

        return $this->cleanValue($value);
    }

    private function extractValue(SimpleXMLElement $data, array $definition, array $config, string $key): ?string
    {
        $value = null;
        $xpaths = $definition['xpaths'] ?? [$definition['xpath'] ?? null];

        foreach ($xpaths as $xpath) {
            if ($xpath === null) {
                continue;
            }

            $matches = $data->xpath($this->buildXpath($xpath, $config));

            if (!$matches) {
                continue;
            }

            foreach ($matches as $match) {
                $text = trim((string) $match);

                if ($text === '' || $text === 'n/a') {
                    continue;
                }

                if ($value === null) {
                    $value = $text;
                } elseif ($key !== 'keywords' || !in_array($text, explode(', ', $value), true)) {
                    $value .= ', ' . $text;
                }
            }
        }

        return $this->cleanValue($value);
    }

    private function buildXpath(string $xpath, array $config): string
    {
        $namespace = $config['namespace'];
        $prepend = '';

        if (str_starts_with($xpath, '(')) {
            $prepend = '(';
            $xpath = substr($xpath, 1);
        }

        $xpath = str_replace('{language}', $config['language'], $xpath);
        $xpath = preg_replace('/\[@(?!xml|text|contains|last|starts-with)/', '[@' . $namespace . ':', $xpath);
        $xpath = preg_replace('/\(@(?!xml|text|contains|last|starts-with)/', '(@' . $namespace . ':', $xpath);
        $xpath = preg_replace('/\[(?![@0-9]|not\(|text|contains|last|starts-with)/', '[' . $namespace . ':', $xpath);
        $xpath = preg_replace('/\/@/', '/@' . $namespace . ':', $xpath);
        $xpath = preg_replace('/\/([^@\/])/', '/' . $namespace . ':$1', $xpath);
        $xpath = preg_replace('/ and @(?!xml)/', ' and @' . $namespace . ':', $xpath);
        $xpath = preg_replace('/ and not\(([^@])/', ' and not(' . $namespace . ':$1', $xpath);

        if (!str_starts_with($xpath, '/')) {
            $xpath = $namespace . ':' . $xpath;
        }

        return $prepend . 'descendant::' . $xpath;
    }

    private function normalizeDates(array &$sourceData): void
    {
        if (isset($sourceData['earliest_date'], $sourceData['latest_date'])) {
            if ($sourceData['earliest_date'] === $sourceData['latest_date']) {
                $sourceData['creation_date'] = $sourceData['earliest_date'];
            }

            unset($sourceData['earliest_date'], $sourceData['latest_date']);

            return;
        }

        if (isset($sourceData['earliest_date'])) {
            $sourceData['creation_date'] = $sourceData['earliest_date'];
            unset($sourceData['earliest_date']);
        }

        if (isset($sourceData['latest_date'])) {
            $sourceData['creation_date'] = $sourceData['latest_date'];
            unset($sourceData['latest_date']);
        }
    }

    /**
     * @param list<string> $placeholderImages
     */
    private function normalizeImageData(array &$sourceData, array $placeholderImages): void
    {
        $imageUrl = trim((string) ($sourceData['iiif_image_url'] ?? ''));

        if ($imageUrl === '') {
            return;
        }

        unset($sourceData['iiif_image_url']);

        if (in_array($imageUrl, $placeholderImages, true)) {
            return;
        }

        $sourceData['iiif_image_info_url'] = $imageUrl . '/info.json';

        if (str_contains($imageUrl, '/public@') || str_contains($imageUrl, 'public%2F')) {
            $sourceData['thumbnail'] = $imageUrl . '/full/150,/0/default.jpg';
        }
    }

    private function recordNode(SimpleXMLElement $response): ?SimpleXMLElement
    {
        if (isset($response->GetRecord->record)) {
            return $response->GetRecord->record;
        }

        if (isset($response->metadata)) {
            return $response;
        }

        return null;
    }

    private function countResult(string $result, int &$created, int &$updated, int &$skipped): void
    {
        if ($result === 'created') {
            $created++;
        } elseif ($result === 'updated') {
            $updated++;
        } else {
            $skipped++;
        }
    }

    private function firstText(array $data, array $keys, ?string $fallback = null): ?string
    {
        foreach ($keys as $key) {
            $value = $this->cleanValue($data[$key] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return $fallback;
    }

    private function cleanValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function textOption(InputInterface $input, string $name): ?string
    {
        return $this->cleanValue($input->getOption($name));
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
