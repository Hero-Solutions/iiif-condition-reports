<?php

namespace App\Command;

use App\Entity\DatahubData;
use App\Entity\Image;
use App\Entity\InventoryNumber;
use App\Entity\Report;
use App\Utils\CurlUtil;
use App\Utils\IIIFUtil;
use App\Utils\StringUtil;
use Doctrine\ORM\EntityManagerInterface;
use DOMDocument;
use DOMXPath;
use Exception;
use Phpoaipmh\Client;
use Phpoaipmh\Endpoint;
use Phpoaipmh\Exception\HttpException;
use Phpoaipmh\Exception\OaipmhException;
use Phpoaipmh\HttpAdapter\CurlAdapter;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class SingleRecordToMySQLCommand extends Command implements LoggerAwareInterface
{
    private $parameterBag;
    private $entityManager;

    private $datahubUrl;
    private $datahubLanguage;
    private $namespace;
    private $metadataPrefix;
    private $dataDefinition;
    private $placeholderImages;
    private $imageHashes = array();

    protected function configure()
    {
        $this
            ->setName('app:record-to-mysql')
            ->addArgument('recordid', InputArgument::REQUIRED, 'The Datahub record OAI ID')
            ->addArgument('url', InputArgument::OPTIONAL, 'The URL of the Datahub')
            ->addArgument('username', InputArgument::OPTIONAL, 'The username to authenticate with (basic HTTP auth')
            ->addArgument('password', InputArgument::OPTIONAL, 'The password to authenticate with (basic HTTP auth)')
            ->setDescription('')
            ->setHelp('');
    }

    public function __construct(ParameterBagInterface $parameterBag, EntityManagerInterface $entityManager)
    {
        $this->parameterBag = $parameterBag;
        $this->entityManager = $entityManager;
        parent::__construct();
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->verbose = $input->getOption('verbose');

        $recordId = $input->getArgument('recordid');

        $this->datahubUrl = $input->getArgument('url');
        if (!$this->datahubUrl) {
            $this->datahubUrl = $this->parameterBag->get('datahub_url');
        }
        $username = $input->getArgument('username');
        $password = $input->getArgument('password');

        $overrideCA = $this->parameterBag->get('override_certificate_authority');
        $sslCAFile = $this->parameterBag->get('ssl_certificate_authority_file');

        $this->datahubLanguage = $this->parameterBag->get('datahub_language');
        $this->namespace = $this->parameterBag->get('datahub_namespace');
        $this->metadataPrefix = $this->parameterBag->get('datahub_metadataprefix');
        $this->dataDefinition = $this->parameterBag->get('datahub_data_definition');
        $this->placeholderImages = $this->parameterBag->get('placeholder_images');

        $this->storeDatahubData($recordId, $username, $password, $overrideCA, $sslCAFile);

        return 0;
    }

    function storeDatahubData($recordId, $username, $password, $overrideCA, $sslCAFile)
    {
        $qb = $this->entityManager->createQueryBuilder();

        $existingImages = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Image::class, 'i')
            ->getQuery()
            ->getResult();
        foreach($existingImages as $img) {
            $this->imageHashes[] = $img->getHash();
        }

        try {
            $curlAdapter = new CurlAdapter();
            if($username !== null && $password !== null) {
                $curlOpts = array(
                    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                    CURLOPT_USERPWD => $username . ':' . $password
                );
            } else {
                $curlOpts = [];
            }
            if ($overrideCA) {
                $curlOpts[CURLOPT_CAINFO] = $sslCAFile;
                $curlOpts[CURLOPT_CAPATH] = $sslCAFile;
            }
            $curlAdapter->setCurlOpts($curlOpts);
            $oaiPmhClient = new Client($this->datahubUrl . '/oai', $curlAdapter);
            $datahubEndpoint = new Endpoint($oaiPmhClient);

            $record = $datahubEndpoint->getRecord($recordId, $this->metadataPrefix);
            $inventoryNumber = null;
            $datahubData = array();

            $data = $record->GetRecord->record->metadata->children($this->namespace, true);

            foreach ($this->dataDefinition as $key => $dataDef) {
                $value = null;
                if(array_key_exists('parent_xpath', $dataDef)) {
                    $query = $this->buildXpath($dataDef['parent_xpath'], $this->datahubLanguage, $this->namespace);
                    $extracted = $data->xpath($query);
                    if ($extracted) {
                        if (count($extracted) > 0) {
                            foreach ($extracted as $extr) {
                                $query = $this->buildXpath($dataDef['xpath_main'], $this->datahubLanguage, $this->namespace);
                                $extract = $extr->xpath($query);
                                if ($extract) {
                                    if (count($extract) > 0) {
                                        foreach ($extract as $ext) {
                                            $ex = (string)$ext;
                                            if ($ex !== 'n/a') {
                                                $value .= (empty($value) ? '' : PHP_EOL) . $ex;
                                            }
                                        }
                                    }
                                }
                                $query = $this->buildXpath($dataDef['xpath_sub'], $this->datahubLanguage, $this->namespace);
                                $extract = $extr->xpath($query);
                                if ($extract) {
                                    if (count($extract) > 0) {
                                        foreach ($extract as $ext) {
                                            $ex = (string)$ext;
                                            if ($ex !== 'n/a') {
                                                $value .= ' (' . $ex . ')';
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                } else {
                    $xpaths = array();
                    if (array_key_exists('xpaths', $dataDef)) {
                        $xpaths = $dataDef['xpaths'];
                    } else if (array_key_exists('xpath', $dataDef)) {
                        $xpaths[] = $dataDef['xpath'];
                    }
                    foreach ($xpaths as $xpath_) {
                        $query = $this->buildXpath($xpath_, $this->datahubLanguage, $this->namespace);
                        $extracted = $data->xpath($query);
                        if ($extracted) {
                            if (count($extracted) > 0) {
                                foreach ($extracted as $extr) {
                                    $ext = (string)$extr;
                                    if ($ext !== 'n/a') {
                                        if ($value == null) {
                                            $value = $ext;
                                        } else if ($key != 'keywords' || !in_array($ext, explode(",", $value))) {
                                            $value .= ', ' . $ext;
                                        }
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
                if(array_key_exists('iiif_image_url', $datahubData)) {
                    if(!in_array($datahubData['iiif_image_url'], $this->placeholderImages)) {
                        $image = new Image();
                        $image->setImage($datahubData['iiif_image_url'] . '/info.json');
                        $image->setThumbnail(IIIFUtil::generateIIIFThumbnail($datahubData['iiif_image_url']));
                        if(!in_array($image->getHash(), $this->imageHashes)) {
                            $this->imageHashes[] = $image->getHash();
                            $this->entityManager->persist($image);
                        }
                        $datahubData['images'] = $image->getHash();
                        $datahubData['thumbnail'] = $image->getThumbnail();
                    }
                    unset($datahubData['iiif_image_url']);
                }

                $invNr = null;
                $inventoryNumbers = $this->entityManager->createQueryBuilder()
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
                    $this->entityManager->persist($invNr);
                    $this->entityManager->flush();
                }

                // Delete any data that might already exist for this inventory number
                $query = $qb->delete(DatahubData::class, 'data')
                    ->where('data.id = :id')
                    ->setParameter('id', $invNr->getId())
                    ->getQuery();
                $query->execute();
                $this->entityManager->flush();

                //Store all relevant Datahub data in mysql
                foreach($datahubData as $key => $value) {
                    $data = new DatahubData();
                    $data->setId($invNr->getId());
                    $data->setName($key);
                    $data->setValue($value);
                    $this->entityManager->persist($data);
                }
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        }
        catch(OaipmhException $e) {
//            echo 'OAI-PMH error: ' . $e . PHP_EOL;
            $this->logger->error('OAI-PMH error: ' . $e);
        }
        catch(HttpException $e) {
//            echo 'OAI-PMH error: ' . $e . PHP_EOL;
            $this->logger->error('OAI-PMH error: ' . $e);
        } catch (Exception $e) {
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
        $xpath = preg_replace('/\[(?![@0-9]|not\(|contains\()/', '[' . $namespace . ':${1}', $xpath);
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
