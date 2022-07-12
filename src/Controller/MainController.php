<?php

namespace App\Controller;

use App\Entity\DatahubData;
use App\Entity\Image;
use App\Entity\InventoryNumber;
use App\Entity\Report;
use App\Entity\ReportData;
use App\Entity\ReportHistory;
use App\Entity\Search;
use App\Utils\CurlUtil;
use App\Utils\IIIFUtil;
use App\Utils\StringUtil;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use JsonPath\InvalidJsonException;
use JsonPath\JsonObject;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class MainController extends AbstractController
{
    private $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    /**
     * @Route("/")
     * @Route("/{_locale}", name="main")
     */
    public function reports(Request $request)
    {
        $t = $this->translator;
        $locale = $request->get('_locale');
        $locales = $this->getParameter('locales');
        //Set default locale if locale is missing
        if($locale === null || !in_array($locale, $locales)) {
            return $this->redirectToRoute('main', array('_locale' => $locales[0]));
        }
        if(!$this->getUser()) {
            return $this->redirectToRoute('main');
        } else if(!$this->getUser()->getRoles()) {
            return $this->redirectToRoute('main');
        } else if (!in_array('ROLE_USER', $this->getUser()->getRoles(), true)) {
            return $this->redirectToRoute('main');
        }

        $lookupSource = $this->getParameter('lookup_source');
        if($lookupSource === null) {
            $lookupSource = 'mysql';
        }

        $searchTypes = [ $t->trans('Exact') => 0, $t->trans('Partly') => 1, $t->trans('Starts with') => 2 ];
        if($lookupSource === 'omeka') {
            $searchTypes = [ $t->trans('Exact') => 0, $t->trans('Partly') => 1 ];
        }

        $search = new Search();
        $form = $this->createFormBuilder($search)
            ->add('match_type', ChoiceType::class, [ 'label' => $t->trans('Search type'), 'choices' => $searchTypes ])
            ->add('inventory_number', TextType::class, [ 'label' => $t->trans('Inventory number'), 'required' => false, 'empty_data' => '', 'attr' => ['placeholder' => $t->trans('Search by inventory number ...')] ])
            ->add('submit', SubmitType::class, [ 'label' => $t->trans('Search') ])
            ->getForm();
        $form->handleRequest($request);
        $searchResults = array();

        $reportReasons = $this->getParameter('report_reasons');

        /* @var $em EntityManager */
        $em = $this->container->get('doctrine')->getManager();
        /* @var $qb QueryBuilder */
        $qb = $em->createQueryBuilder();

        $searchParameter = null;
        $matchType = '0';

        if($form->isSubmitted() && $form->isValid()) {
            $formData = $form->getData();
            $inventoryNumber = $formData->getInventoryNumber();
            $matchType = $formData->getMatchType();

            $searchParameter = $inventoryNumber;
            if ($matchType == '1') {
                $searchParameter = '%' . $searchParameter . '%';
            } else if ($matchType == '2') {
                $searchParameter .= '%';
            }

            if ($lookupSource === 'mysql') {

                $datahubData = $em->createQueryBuilder()
                    ->select('i.id, i.inventoryNumber, d.name, d.value')
                    ->from(InventoryNumber::class, 'i')
                    ->leftJoin(DatahubData::class, 'd', 'WITH', 'd.id = i.id')
                    ->where('i.inventoryNumber ' . ($matchType == '0' ? '=' : 'LIKE') . ' :inventory_number')
                    ->setParameter('inventory_number', $searchParameter)
                    ->orderBy('d.id')
                    ->setMaxResults(1000)
                    ->getQuery()
                    ->getResult();
                $datahubData = array_reverse($datahubData);
                foreach ($datahubData as $data) {
                    $inventoryNumber = $data['id'] . '_0';
                    if (!array_key_exists($inventoryNumber, $searchResults)) {
                        $searchResults[$inventoryNumber] = [
                            'id' => '',
                            'base_id' => '',
                            'inventory_id' => $data['id'],
                            'inventory_number' => $data['inventoryNumber'],
                            'timestamp' => '',
                            'thumbnail' => '',
                            'title_nl' => '',
                            'creator_nl' => ''
                        ];
                    }
                    $searchResults[$inventoryNumber][$data['name']] = $data['value'];
                }
            } else if ($lookupSource === 'omeka') {
                $matchParameter = 'eq';
                if ($matchType == '1' || $matchType == '2') {
                    $matchParameter = 'in';
                }
                $omekaApi = $this->getParameter('omeka_api');
                $url = $omekaApi['url'];
                if (!StringUtil::endsWith($url, '/')) {
                    $url .= '/';
                }
                $inventoryNumberPropertyId = $omekaApi['inventory_number_property_id'];
                $keyIdentity = $omekaApi['key_identity'];
                $keyCredential = $omekaApi['key_credential'];
                $url .= 'items?property[0][property]=' . $inventoryNumberPropertyId . '&property[0][type]=' . $matchParameter . '&property[0][text]=' . urlencode($inventoryNumber) . '&per_page=200&key_identity=' . $keyIdentity . '&key_credential=' . $keyCredential;
                $jsonData = CurlUtil::get($url);
                $items = json_decode($jsonData);
                $omekaDataDefinition = $this->getParameter('omeka_data_definition');
                foreach($items as $item) {
                    $data = [
                        'id' => '',
                        'base_id' => '',
                        'inventory_id' => '',
                        'inventory_number' => '',
                        'timestamp' => '',
                        'thumbnail' => '',
                        'media' => '',
                        'title_nl' => '',
                        'creator_nl' => ''
                    ];
                    try {
                        $jsonObject = new JsonObject($item);
                        $inventoryNumber = null;
                        foreach($omekaDataDefinition as $key => $path) {
                            $res = $jsonObject->get($path);
                            if(is_array($res)) {
                                $res = $res[0];
                            }
                            if(is_string($res)) {
                                if ($key === 'id') {
                                    $inventoryNumber = $res;
                                } else {
                                    $data[$key] = $res;
                                }
                            }
                        }
                        if($inventoryNumber !== null) {
                            $inventoryId = null;
                            $inventoryNumbers = $em->createQueryBuilder()
                                ->select('i')
                                ->from(InventoryNumber::class, 'i')
                                ->where('i.inventoryNumber = :inventory_number')
                                ->setParameter('inventory_number', $inventoryNumber)
                                ->getQuery()
                                ->getResult();
                            foreach ($inventoryNumbers as $invNr) {
                                $inventoryId = $invNr->getId();
                            }
                            if($inventoryId === null) {
                                $invNr = new InventoryNumber();
                                $invNr->setInventoryNumber($inventoryNumber);
                                $em->persist($invNr);
                                $em->flush();
                                $inventoryId = $invNr->getId();
                            }
                            $id = $inventoryId . '_0';
                            if (!array_key_exists($id, $searchResults)) {
                                $data['inventory_id'] = $inventoryId;
                                $data['inventory_number'] = $inventoryNumber;
                                $searchResults[$inventoryNumber] = $data;
                            }

                            // Delete any data that might already exist for this inventory number
                            $query = $qb->delete(DatahubData::class, 'data')
                                ->where('data.id = :id')
                                ->setParameter('id', $inventoryId)
                                ->getQuery();
                            $query->execute();
                            $em->flush();

                            //Store all relevant data in mysql
                            foreach($data as $key => $value) {
                                if($value !== null && !empty($value)) {
                                    $data = new DatahubData();
                                    $data->setId($invNr->getId());
                                    $data->setName($key);
                                    $data->setValue($value);
                                    $em->persist($data);
                                }
                            }
                            $em->flush();
                            $em->clear();
                        }
                    } catch (InvalidJsonException $e) {
                        echo 'JSONPath error: ' . $e->getMessage() . PHP_EOL;
                    }
                }
            }
        }

        $queryBuilder = $em->createQueryBuilder()
            ->select('r.id, r.baseId, r.inventoryId, r.timestamp, r.reason, i.inventoryNumber, d.name, d.value')
            ->from(Report::class, 'r')
            ->leftJoin(InventoryNumber::class, 'i', 'WITH', 'i.id = r.inventoryId')
            ->leftJoin(DatahubData::class, 'd', 'WITH', 'd.id = r.inventoryId');
        if($searchParameter !== null) {
            $queryBuilder = $queryBuilder->where('i.inventoryNumber ' . ($matchType == '0' ? '=' : 'LIKE') . ' :inventory_number')
                ->setParameter('inventory_number', $searchParameter);
        }
        $reportData = $queryBuilder->orderBy('r.timestamp', 'DESC')
            ->orderBy('r.id', 'DESC')
            ->setMaxResults(1000)
            ->getQuery()
            ->getResult();
        $reportData = array_reverse($reportData);
        foreach ($reportData as $data) {
            $inventoryNumber = $data['inventoryId'] . '_' . $data['baseId'];
            if(!array_key_exists($inventoryNumber, $searchResults)) {
                $searchResults[$inventoryNumber] = array();
                // Remove any results of Datahub data when a report exists for this inventory number
                if(array_key_exists($data['inventoryId'] . '_0', $searchResults)) {
                    unset($searchResults[$data['inventoryId'] . '_0']);
                }
            }
            $searchResults[$inventoryNumber]['id'] = $data['id'];
            $searchResults[$inventoryNumber]['base_id'] = $data['baseId'];
            $searchResults[$inventoryNumber]['inventory_id'] = $data['inventoryId'];
            $searchResults[$inventoryNumber]['inventory_number'] = $data['inventoryNumber'];
            $searchResults[$inventoryNumber]['timestamp'] = $data['timestamp']->format('Y-m-d H:i:s');
            $reason = null;
            if($data['reason'] !== null) {
                foreach($reportReasons as $key => $reasons) {
                    if(array_key_exists($data['reason'], $reasons['options'])) {
                        $reason = $this->translator->trans($reasons['name']) . ' - ' . $this->translator->trans($reasons['options'][$data['reason']]);
                    }
                }
            }
            $searchResults[$inventoryNumber]['reason'] = $reason;
            $searchResults[$inventoryNumber][$data['name']] = $data['value'];
        }
        foreach($searchResults as $inventoryNumber => $data) {
            if(!array_key_exists('thumbnail', $data)) {
                $searchResults[$inventoryNumber]['thumbnail'] = '';
            }
            if(!array_key_exists('title_nl', $data)) {
                $searchResults[$inventoryNumber]['title_nl'] = '';
            }
            if(!array_key_exists('creator_nl', $data)) {
                $searchResults[$inventoryNumber]['creator_nl'] = '';
            }
        }
        usort($searchResults, array('App\Controller\MainController', 'cmp'));

        $translatedRoutes = array();
        foreach($locales as $l) {
            $translatedRoutes[] = array(
                'lang' => $l,
                'url' => $this->generateUrl('main', array('_locale' => $l)),
                'active' => $l === $locale
            );
        }

        return $this->render('reports.html.twig', [
            'current_page' => 'reports',
            'form' => $form->createView(),
            'search_results' => $searchResults,
            'translated_routes' => $translatedRoutes
        ]);
    }

    function cmp($a, $b)
    {
        if ($a['timestamp'] == $b['timestamp']) {
            return $a['id'] > $b['id'] ? -1 : 1;
        }
        return $a['timestamp'] > $b['timestamp'] ? -1 : 1;
    }
}
