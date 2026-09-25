<?php

declare(strict_types=1);

namespace App\Command;

use App\Datahub\CurlHttpAdapter;
use App\Entity\IIIFManifest;
use App\Entity\ObjectManifest;
use App\Entity\ObjectRecord;
use Doctrine\ORM\EntityManagerInterface;
use Phpoaipmh\Client;
use Phpoaipmh\Endpoint;
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
    /** @var array<string, IIIFManifest> */
    private array $manifestCache = [];

    /** @var array<string, ObjectRecord> */
    private array $objectCache = [];

    /** @var array<string, ObjectManifest> */
    private array $objectManifestCache = [];

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
            ->addOption('set', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter op een DataHub-set; herhaal de optie voor meerdere sets.')
            ->addOption('all-sets', null, InputOption::VALUE_NONE, 'Negeer de standaardset en verwerk alle sets.')
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
        try {
            if ($recordId !== null) {
                $record = $this->recordNode($endpoint->getRecord($recordId, $config['metadata_prefix']));
                $prepared = $record === null ? null : $this->prepareRecord($record, $config);
                $this->preloadBatch($prepared === null ? [] : [$prepared]);
                $result = $prepared === null ? 'skipped' : $this->syncPreparedRecord($prepared);
                $this->countResult($result, $created, $updated, $skipped);
            } else {
                $this->harvestRest(
                    $input,
                    $output,
                    $config,
                    $limit,
                    $flushSize,
                    $created,
                    $updated,
                    $skipped,
                );
            }

            $this->entityManager->flush();
        } catch (\JsonException|\RuntimeException $exception) {
            $output->writeln('<error>Datahub fout: ' . $exception->getMessage() . '</error>');

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
     *     sets: list<string>,
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
        $url = $this->baseUrl($input, $config) . '/oai/';

        return new Endpoint(new Client($url, $this->httpAdapter($input)));
    }

    private function baseUrl(InputInterface $input, array $config): string
    {
        $url = rtrim(trim((string) ($input->getArgument('url') ?: $config['url'])), '/');

        return str_ends_with($url, '/oai') ? substr($url, 0, -4) : $url;
    }

    private function httpAdapter(InputInterface $input): CurlHttpAdapter
    {
        return new CurlHttpAdapter(
            $this->textOption($input, 'username'),
            $this->textOption($input, 'password'),
            $this->textOption($input, 'ca-file'),
        );
    }

    private function harvestRest(
        InputInterface $input,
        OutputInterface $output,
        array $config,
        ?int $limit,
        int $flushSize,
        int &$created,
        int &$updated,
        int &$skipped,
    ): void {
        $adapter = $this->httpAdapter($input);
        $endpoint = $this->baseUrl($input, $config) . '/api/v1/data.json';
        $pageSize = 25;
        $offset = 0;
        $matched = 0;
        $batch = [];
        $lastReported = 0;
        $sets = $this->selectedSets($input, $config);

        if ($sets !== []) {
            $output->writeln('Set: ' . implode(', ', $sets));
        }

        while ($limit === null || $matched < $limit) {
            $url = sprintf('%s?offset=%d&limit=%d&sort=id,asc', $endpoint, $offset, $pageSize);
            $payload = json_decode($adapter->request($url), true, 512, JSON_THROW_ON_ERROR);
            $records = $payload['_embedded']['records'] ?? null;

            if (!is_array($records)) {
                throw new \RuntimeException('De REST-response bevat geen records.');
            }

            if ($records === []) {
                break;
            }

            $recordCount = count($records);
            $total = isset($payload['total']) ? (int) $payload['total'] : null;
            unset($payload);

            foreach ($records as $record) {
                if (!is_array($record)) {
                    continue;
                }

                if (!$this->recordMatchesSets($record, $sets)) {
                    continue;
                }

                $matched++;
                $prepared = $this->prepareRestRecord($record, $config);

                if ($prepared === null) {
                    $skipped++;
                } else {
                    $batch[] = $prepared;
                }

                if (count($batch) >= $flushSize) {
                    $this->syncPreparedBatch($batch, $created, $updated, $skipped);
                    $batch = [];
                    $lastReported = $matched;
                    $output->writeln(sprintf('Verwerkt: %d', $matched));
                }

                if ($limit !== null && $matched >= $limit) {
                    break;
                }
            }

            $offset += $recordCount;
            unset($records, $record, $prepared);

            if (($total !== null && $offset >= $total) || $recordCount < $pageSize) {
                break;
            }
        }

        if ($batch !== []) {
            $this->syncPreparedBatch($batch, $created, $updated, $skipped);
        }

        if ($matched !== $lastReported) {
            $output->writeln(sprintf('Verwerkt: %d', $matched));
        }
    }

    /**
     * @return list<string>
     */
    private function selectedSets(InputInterface $input, array $config): array
    {
        if ((bool) $input->getOption('all-sets')) {
            return [];
        }

        $sets = $input->getOption('set');

        if (!is_array($sets) || $sets === []) {
            $sets = $config['sets'] ?? [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $set): string => trim((string) $set), $sets),
            static fn (string $set): bool => $set !== '',
        )));
    }

    /**
     * @param array<string, mixed> $record
     * @param list<string>         $sets
     */
    private function recordMatchesSets(array $record, array $sets): bool
    {
        if ($sets === []) {
            return true;
        }

        $recordSets = $record['sets'] ?? [];

        return is_array($recordSets) && array_intersect($sets, $recordSets) !== [];
    }

    /**
     * @param list<array{inventory_number: string, source_data: array<string, mixed>, record_identifier: string, synced_at: \DateTimeImmutable}> $preparedRecords
     */
    private function syncPreparedBatch(
        array $preparedRecords,
        int &$created,
        int &$updated,
        int &$skipped,
    ): void {
        $this->preloadBatch($preparedRecords);

        foreach ($preparedRecords as $prepared) {
            $result = $this->syncPreparedRecord($prepared);
            $this->countResult($result, $created, $updated, $skipped);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->objectCache = [];
        $this->objectManifestCache = [];
        $this->manifestCache = [];
    }

    /**
     * @return array{inventory_number: string, source_data: array<string, mixed>, record_identifier: string, synced_at: \DateTimeImmutable}|null
     */
    private function prepareRecord(SimpleXMLElement $record, array $config): ?array
    {
        if ((string) $record->header['status'] === 'deleted' || !isset($record->metadata)) {
            return null;
        }

        $data = $record->metadata->children($config['namespace'], true);
        $recordIdentifier = trim((string) $record->header->identifier);

        return $this->prepareData($data, $recordIdentifier, $config);
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array{inventory_number: string, source_data: array<string, mixed>, record_identifier: string, synced_at: \DateTimeImmutable}|null
     */
    private function prepareRestRecord(array $record, array $config): ?array
    {
        $nodes = $record['json'] ?? null;

        if (!is_array($nodes)) {
            return null;
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $root = $document->createElementNS('http://www.lido-schema.org', 'lido:lido');
        $document->appendChild($root);

        foreach ($nodes as $node) {
            if (is_array($node)) {
                $this->appendRestNode($document, $root, $node);
            }
        }

        $data = simplexml_import_dom($root);

        if (!$data instanceof SimpleXMLElement) {
            return null;
        }

        $recordIds = $record['record_ids'] ?? [];
        $recordIdentifier = is_array($recordIds) && isset($recordIds[0])
            ? trim((string) $recordIds[0])
            : trim((string) ($record['id'] ?? ''));

        return $this->prepareData($data, $recordIdentifier, $config);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function appendRestNode(\DOMDocument $document, \DOMElement $parent, array $node): void
    {
        $name = $node['name'] ?? null;

        if (!is_string($name) || $name === '') {
            return;
        }

        [$namespace, $localName] = $this->splitExpandedName($name);
        $qualifiedName = match ($namespace) {
            'http://www.lido-schema.org' => 'lido:' . $localName,
            'http://www.w3.org/XML/1998/namespace' => 'xml:' . $localName,
            default => $localName,
        };
        $element = $namespace === null
            ? $document->createElement($qualifiedName)
            : $document->createElementNS($namespace, $qualifiedName);
        $parent->appendChild($element);

        foreach (($node['attributes'] ?? []) as $attributeName => $attributeValue) {
            if (!is_string($attributeName) || !is_scalar($attributeValue)) {
                continue;
            }

            [$attributeNamespace, $attributeLocalName] = $this->splitExpandedName($attributeName);
            $attributeQualifiedName = match ($attributeNamespace) {
                'http://www.lido-schema.org' => 'lido:' . $attributeLocalName,
                'http://www.w3.org/XML/1998/namespace' => 'xml:' . $attributeLocalName,
                default => $attributeLocalName,
            };

            if ($attributeNamespace === null) {
                $element->setAttribute($attributeQualifiedName, (string) $attributeValue);
            } else {
                $element->setAttributeNS($attributeNamespace, $attributeQualifiedName, (string) $attributeValue);
            }
        }

        $value = $node['value'] ?? null;

        if (is_array($value)) {
            foreach ($value as $childNode) {
                if (is_array($childNode)) {
                    $this->appendRestNode($document, $element, $childNode);
                }
            }
        } elseif (is_scalar($value)) {
            $element->appendChild($document->createTextNode((string) $value));
        }
    }

    /**
     * @return array{?string, string}
     */
    private function splitExpandedName(string $name): array
    {
        if (preg_match('/^\{([^}]+)\}(.+)$/', $name, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        return [null, $name];
    }

    /**
     * @return array{inventory_number: string, source_data: array<string, mixed>, record_identifier: string, synced_at: \DateTimeImmutable}|null
     */
    private function prepareData(SimpleXMLElement $data, string $recordIdentifier, array $config): ?array
    {
        $sourceData = $this->extractData($data, $config);
        $inventoryNumber = $sourceData['id'] ?? $sourceData['fallback_id'] ?? null;

        if ($inventoryNumber === null || trim($inventoryNumber) === '') {
            return null;
        }

        unset($sourceData['id'], $sourceData['fallback_id']);

        $this->normalizeDates($sourceData);
        $this->normalizeImageData($sourceData, $config['placeholder_images']);

        $syncedAt = new \DateTimeImmutable();
        $sourceData['_datahub_record_id'] = $recordIdentifier;
        $sourceData['_datahub_synced_at'] = $syncedAt->format(\DateTimeInterface::ATOM);

        return [
            'inventory_number' => $inventoryNumber,
            'source_data' => $sourceData,
            'record_identifier' => $recordIdentifier,
            'synced_at' => $syncedAt,
        ];
    }

    /**
     * @param array{inventory_number: string, source_data: array<string, mixed>, record_identifier: string, synced_at: \DateTimeImmutable} $prepared
     */
    private function syncPreparedRecord(array $prepared): string
    {
        $inventoryNumber = $prepared['inventory_number'];
        $sourceData = $prepared['source_data'];
        $recordIdentifier = $prepared['record_identifier'];
        $syncedAt = $prepared['synced_at'];

        $object = $this->objectCache[$inventoryNumber] ?? null;
        $result = $object instanceof ObjectRecord ? 'updated' : 'created';

        if (!$object instanceof ObjectRecord) {
            $object = new ObjectRecord($inventoryNumber, $this->firstText($sourceData, ['title_nl', 'title_en'], $inventoryNumber));
            $this->objectCache[$inventoryNumber] = $object;
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

    /**
     * @param list<array{inventory_number: string, source_data: array<string, mixed>, record_identifier: string, synced_at: \DateTimeImmutable}> $preparedRecords
     */
    private function preloadBatch(array $preparedRecords): void
    {
        $inventoryNumbers = [];
        $manifestUrls = [];

        foreach ($preparedRecords as $prepared) {
            $inventoryNumbers[$prepared['inventory_number']] = true;
            $manifestUrl = trim((string) ($prepared['source_data']['iiif_manifest_url'] ?? ''));

            if ($manifestUrl !== '') {
                $manifestUrls[$manifestUrl] = true;
            }
        }

        if ($inventoryNumbers !== []) {
            $objects = $this->entityManager
                ->getRepository(ObjectRecord::class)
                ->findBy(['inventoryNumber' => array_keys($inventoryNumbers)]);

            foreach ($objects as $object) {
                $this->objectCache[$object->getInventoryNumber()] = $object;
            }

            if ($objects !== []) {
                $links = $this->entityManager
                    ->getRepository(ObjectManifest::class)
                    ->findBy([
                        'objectRecord' => $objects,
                        'role' => ObjectManifest::ROLE_SOURCE,
                    ]);

                foreach ($links as $link) {
                    $this->objectManifestCache[$link->getObjectRecord()->getInventoryNumber()] = $link;
                }
            }
        }

        if ($manifestUrls !== []) {
            $manifests = $this->entityManager
                ->getRepository(IIIFManifest::class)
                ->findBy(['manifestId' => array_keys($manifestUrls)]);

            foreach ($manifests as $manifest) {
                $this->manifestCache[$manifest->getManifestId()] = $manifest;
            }
        }
    }

    private function syncObjectManifest(ObjectRecord $object, array $sourceData): void
    {
        $manifestUrl = trim((string) ($sourceData['iiif_manifest_url'] ?? ''));
        $inventoryNumber = $object->getInventoryNumber();
        $currentLink = $this->objectManifestCache[$inventoryNumber] ?? null;

        if ($manifestUrl === '') {
            if ($currentLink instanceof ObjectManifest && $currentLink->getManifest()->getSource() === IIIFManifest::SOURCE_DATAHUB) {
                $this->entityManager->remove($currentLink);
                unset($this->objectManifestCache[$inventoryNumber]);
            }

            return;
        }

        $manifest = $this->manifestCache[$manifestUrl] ?? $this->entityManager
            ->getRepository(IIIFManifest::class)
            ->findOneBy(['manifestId' => $manifestUrl]);

        if (!$manifest instanceof IIIFManifest) {
            $manifest = new IIIFManifest();
        }

        $this->manifestCache[$manifestUrl] = $manifest;

        $manifest
            ->setManifestId($manifestUrl)
            ->setSource(IIIFManifest::SOURCE_DATAHUB)
            ->setSourceUrl($manifestUrl)
            ->setThumbnailUrl($sourceData['thumbnail'] ?? null)
            ->setTitle($object->getTitle())
            ->setData([]);

        $this->entityManager->persist($manifest);

        if ($currentLink instanceof ObjectManifest) {
            if ($currentLink->getManifest()->getManifestId() === $manifestUrl) {
                return;
            }

            $this->entityManager->remove($currentLink);
        }

        $newLink = new ObjectManifest($object, $manifest, ObjectManifest::ROLE_SOURCE);
        $this->entityManager->persist($newLink);
        $this->objectManifestCache[$inventoryNumber] = $newLink;
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
            unset($sourceData['iiif_manifest_url']);

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
