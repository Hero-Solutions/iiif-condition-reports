<?php

namespace App\Command;

use App\Entity\DatahubData;
use App\Entity\InventoryNumber;
use App\Entity\Report;
use App\Utils\IIIFUtil;
use App\Utils\StringUtil;
use DOMDocument;
use DOMXPath;
use Phpoaipmh\Endpoint;
use Phpoaipmh\Exception\HttpException;
use Phpoaipmh\Exception\OaipmhException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DatahubToMySQLCommand extends Command implements ContainerAwareInterface, LoggerAwareInterface
{
    private $datahubUrl;
    private $datahubLanguage;
    private $namespace;
    private $metadataPrefix;
    private $dataDefinition;

    private $verbose;

    protected function configure()
    {
        $this
            ->setName('app:datahub-to-mysql')
            ->addArgument('url', InputArgument::OPTIONAL, 'The URL of the Datahub')
            ->setDescription('')
            ->setHelp('');
    }

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->verbose = $input->getOption('verbose');

        $this->datahubUrl = $input->getArgument('url');
        if (!$this->datahubUrl) {
            $this->datahubUrl = $this->container->getParameter('datahub_url');
        }

        $this->datahubLanguage = $this->container->getParameter('datahub_language');
        $this->namespace = $this->container->getParameter('datahub_namespace');
        $this->metadataPrefix = $this->container->getParameter('datahub_metadataprefix');
        $this->dataDefinition = $this->container->getParameter('datahub_data_definition');

        $em = $this->container->get('doctrine')->getManager();
        //Disable SQL logging to improve performance
        $em->getConnection()->getConfiguration()->setSQLLogger(null);

        $this->storeAllDatahubData($em);

        return 0;
    }

    function storeAllDatahubData($em)
    {
        $qb = $em->createQueryBuilder();
        $qb->delete(DatahubData::class, 'data')->getQuery()->execute();
        $em->flush();

        try {
            $datahubEndpoint = Endpoint::build($this->datahubUrl . '/oai');
            $records = $datahubEndpoint->listRecords($this->metadataPrefix);
            $n = 0;
            foreach($records as $record) {
                $inventoryNumber = null;
                $datahubData = array();

                $data = $record->metadata->children($this->namespace, true);
                $recordId = trim($record->header->identifier);

                if($this->verbose) {
                    $n++;
                    if($n % 1000 == 0) {
//                        echo 'At ' . $n . ' datahub records.' . PHP_EOL;
                        $this->logger->info('At ' . $n . ' datahub records.');
                    }
                }

                $domDoc = new DOMDocument;
                $domDoc->loadXML($data->asXML());
                $xpath = new DOMXPath($domDoc);

                foreach ($this->dataDefinition as $key => $dataDef) {
                    $xpaths = array();
                    if(array_key_exists('xpaths', $dataDef)) {
                        $xpaths = $dataDef['xpaths'];
                    } else if(array_key_exists('xpath', $dataDef)) {
                        $xpaths[] = $dataDef['xpath'];
                    }
                    $value = null;
                    foreach($xpaths as $xpath_) {
                        $query = $this->buildXpath($xpath_, $this->datahubLanguage, $this->namespace);
                        $extracted = $xpath->query($query);
                        if ($extracted) {
                            if (count($extracted) > 0) {
                                foreach ($extracted as $extr) {
                                    if ($extr->nodeValue !== 'n/a') {
                                        if($value == null) {
                                            $value = $extr->nodeValue;
                                        }
                                        else if($key != 'keywords' || !in_array($extr->nodeValue, explode(",", $value))) {
                                            $value .= ', ' . $extr->nodeValue;
                                        }
                                    }
                                }
                            }
                        }
                    }
                    if ($value != null) {
                        $value = trim($value);
                        if($key == 'id') {
                            $inventoryNumber = $value;
                        } else {
                            $datahubData[$key] = $value;
                        }
                    }
                }

                if($inventoryNumber != null) {
                    // Combine earliest and latest date into one
                    if(array_key_exists('earliest_date', $datahubData)) {
                        if(array_key_exists('latest_date', $datahubData)) {
                            if($datahubData['earliest_date'] === $datahubData['latest_date']) {
                                $datahubData['creation_date'] = $datahubData['earliest_date'];
                            }
                            unset($datahubData['latest_date']);
                        } else {
                            $datahubData['creation_date'] = $datahubData['earliest_date'];
                        }
                        unset($datahubData['earliest_date']);
                    } else if(array_key_exists('latest_date', $datahubData)) {
                        $datahubData['creation_date'] = $datahubData['latest_date'];
                        unset($datahubData['latest_date']);
                    }
                    // Combine role and creator name
                    if(array_key_exists('creator_role', $datahubData)) {
                        if(array_key_exists('creator_role', $datahubData)) {
                            $datahubData['creator'] = ucfirst($datahubData['creator_role']) . ': ' . $datahubData['creator'];
                        }
                        unset($datahubData['creator_role']);
                    }
                    if(!array_key_exists('creator', $datahubData)) {
                        $datahubData['creator'] = '';
                    }
                    if(array_key_exists('iiif_image_url', $datahubData)) {
                        $datahubData['thumbnail'] = IIIFUtil::generateThumbnail($datahubData['iiif_image_url']);
                    }

                    $invNr = null;
                    $inventoryNumbers = $em->createQueryBuilder()
                        ->select('i')
                        ->from(InventoryNumber::class, 'i')
                        ->where('i.inventoryNumber = :inventory_number')
                        ->setParameter('inventory_number',  $inventoryNumber)
                        ->getQuery()
                        ->getResult();
                    foreach ($inventoryNumbers as $nr) {
                        $invNr = $nr;
                    }
                    if($invNr == null) {
                        $invNr = new InventoryNumber();
                        $invNr->setInventoryNumber($inventoryNumber);
                        $em->persist($invNr);
                        $em->flush();
                    }

                    // Delete any data that might already exist for this inventory number
                    $query = $qb->delete(DatahubData::class, 'data')
                        ->where('data.id = :id')
                        ->setParameter('id', $invNr->getId())
                        ->getQuery();
                    $query->execute();
                    $em->flush();

                    //Store all relevant Datahub data in mysql
                    foreach($datahubData as $key => $value) {
                        $data = new DatahubData();
                        $data->setId($invNr->getId());
                        $data->setName($key);
                        $data->setValue($value);
                        $em->persist($data);
                    }
                    $em->flush();
                    $em->clear();
                }
            }
        }
        catch(OaipmhException $e) {
//            echo 'OAI-PMH error: ' . $e . PHP_EOL;
            $this->logger->error('OAI-PMH error: ' . $e);
        }
        catch(HttpException $e) {
//            echo 'OAI-PMH error: ' . $e . PHP_EOL;
            $this->logger->error('OAI-PMH error: ' . $e);
        }
    }

    // Build the xpath based on the provided namespace
    private function buildXPath($xpath, $language, $namespace)
    {
        $prepend = '';
        if(strpos($xpath, '(') === 0) {
            $prepend = '(';
            $xpath = substr($xpath, 1);
        }
        $xpath = str_replace('{language}', $language, $xpath);
        $xpath = preg_replace('/\[@(?!xml)/', '[@' . $namespace . ':${1}', $xpath);
        $xpath = preg_replace('/\(@(?!xml)/', '(@' . $namespace . ':${1}', $xpath);
        $xpath = preg_replace('/\[(?![@0-9]|not\()/', '[' . $namespace . ':${1}', $xpath);
        $xpath = preg_replace('/\/([^\/])/', '/' . $namespace . ':${1}', $xpath);
        $xpath = preg_replace('/ and (?!@xml)/', ' and ' . $namespace . ':${1}', $xpath);
        if(strpos($xpath, '/') !== 0) {
            $xpath = $namespace . ':' . $xpath;
        }
        $xpath = 'descendant::' . $xpath;
        $xpath = $prepend . $xpath;
        return $xpath;
    }
}
