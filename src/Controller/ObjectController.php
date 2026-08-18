<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\IIIFManifest;
use App\Entity\ObjectManifest;
use App\Entity\ObjectRecord;
use App\Entity\Report;
use App\Entity\ReportSeries;
use App\Form\ObjectRecordType;
use App\Service\ObjectThumbnailProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ObjectController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ObjectThumbnailProvider $thumbnailProvider,
    ) {
    }

    #[Route('/{_locale<nl|en>}/objects', name: 'objects_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $inventoryNumber = trim((string) $request->query->get('inventory_number', ''));
        $match = $request->query->get('match') === 'starts_with' ? 'starts_with' : 'exact';

        $queryBuilder = $this->entityManager
            ->getRepository(ObjectRecord::class)
            ->createQueryBuilder('object')
            ->orderBy('object.updatedAt', 'DESC')
            ->setMaxResults(100);

        if ($inventoryNumber !== '') {
            $queryBuilder
                ->andWhere($match === 'exact' ? 'object.inventoryNumber = :inventoryNumber' : 'object.inventoryNumber LIKE :inventoryNumber')
                ->setParameter('inventoryNumber', $match === 'exact' ? $inventoryNumber : $inventoryNumber . '%');
        }

        $objects = $queryBuilder->getQuery()->getResult();

        return $this->render('objects/index.html.twig', [
            'objects' => $objects,
            'thumbnail_urls' => $this->thumbnailProvider->thumbnailsForObjects($objects),
            'inventory_number' => $inventoryNumber,
            'match' => $match,
        ]);
    }

    #[Route('/{_locale<nl|en>}/objects/new', name: 'objects_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $object = new ObjectRecord();
        $form = $this->createObjectForm($object);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->saveObject($object, $form)) {
            $this->addFlash('success', 'objects.saved');

            return $this->redirectToRoute('objects_edit', [
                '_locale' => $request->getLocale(),
                'id' => $object->getId(),
            ]);
        }

        return $this->render('objects/form.html.twig', [
            'form' => $form,
            'object' => $object,
            'is_new' => true,
            'source_data' => [],
            'thumbnail_url' => null,
        ]);
    }

    #[Route('/{_locale<nl|en>}/objects/{id}', name: 'objects_edit', methods: ['GET', 'POST'])]
    public function edit(ObjectRecord $object, Request $request): Response
    {
        $form = $this->createObjectForm($object, $this->manifestUrl($object), $this->thumbnailProvider->thumbnailForObject($object) ?? '');
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->saveObject($object, $form)) {
            $this->addFlash('success', 'objects.saved');

            return $this->redirectToRoute('objects_edit', [
                '_locale' => $request->getLocale(),
                'id' => $object->getId(),
            ]);
        }

        $reportSeries = $this->reportSeriesForObject($object);
        $reportsBySeries = $this->reportsBySeriesForObject($object);

        return $this->render('objects/form.html.twig', [
            'form' => $form,
            'object' => $object,
            'is_new' => false,
            'source_data' => $object->getSourceData(),
            'thumbnail_url' => $this->thumbnailProvider->thumbnailForObject($object),
            'report_series' => $reportSeries,
            'reports_by_series' => $reportsBySeries,
            'reports_count' => array_sum(array_map('count', $reportsBySeries)),
        ]);
    }

    private function createObjectForm(ObjectRecord $object, string $manifestUrl = '', string $thumbnailUrl = ''): FormInterface
    {
        return $this->createForm(ObjectRecordType::class, $object, [
            'manifest_url' => $manifestUrl,
            'thumbnail_url' => $thumbnailUrl,
        ]);
    }

    private function saveObject(ObjectRecord $object, FormInterface $form): bool
    {
        if ($this->inventoryNumberExists($object)) {
            $this->addFlash('error', 'objects.inventory_number_exists');

            return false;
        }

        $this->entityManager->persist($object);
        $this->syncManifestUrl(
            $object,
            (string) $form->get('iiifManifestUrl')->getData(),
            (string) $form->get('iiifThumbnailUrl')->getData(),
        );
        $this->entityManager->flush();

        return true;
    }

    private function inventoryNumberExists(ObjectRecord $object): bool
    {
        $existingObject = $this->entityManager
            ->getRepository(ObjectRecord::class)
            ->findOneBy(['inventoryNumber' => $object->getInventoryNumber()]);

        return $existingObject instanceof ObjectRecord && $existingObject->getId() !== $object->getId();
    }

    private function manifestUrl(ObjectRecord $object): string
    {
        $link = $this->entityManager
            ->getRepository(ObjectManifest::class)
            ->findOneBy([
                'objectRecord' => $object,
                'role' => ObjectManifest::ROLE_SOURCE,
            ]);

        return $link instanceof ObjectManifest ? $link->getManifest()->getManifestId() : '';
    }

    private function syncManifestUrl(ObjectRecord $object, string $manifestUrl, string $thumbnailUrl): void
    {
        $manifestUrl = trim($manifestUrl);
        $thumbnailUrl = trim($thumbnailUrl);
        $currentLink = $this->entityManager
            ->getRepository(ObjectManifest::class)
            ->findOneBy([
                'objectRecord' => $object,
                'role' => ObjectManifest::ROLE_SOURCE,
            ]);

        if ($manifestUrl === '') {
            if ($currentLink instanceof ObjectManifest) {
                $this->entityManager->remove($currentLink);
            }

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
            ->setSource($object->getSource() === ObjectRecord::SOURCE_DATAHUB ? IIIFManifest::SOURCE_DATAHUB : IIIFManifest::SOURCE_MANUAL)
            ->setSourceUrl($manifestUrl)
            ->setThumbnailUrl($thumbnailUrl)
            ->setTitle($object->getTitle())
            ->setData([]);

        $this->entityManager->persist($manifest);

        if ($currentLink instanceof ObjectManifest) {
            if ($currentLink->getManifest()->getManifestId() === $manifestUrl) {
                return;
            }

            $this->entityManager->remove($currentLink);
        }

        $this->entityManager->persist(new ObjectManifest($object, $manifest, ObjectManifest::ROLE_SOURCE));
    }

    /**
     * @return list<ReportSeries>
     */
    private function reportSeriesForObject(ObjectRecord $object): array
    {
        return $this->entityManager
            ->getRepository(ReportSeries::class)
            ->createQueryBuilder('series')
            ->addSelect('project')
            ->leftJoin('series.project', 'project')
            ->andWhere('series.objectRecord = :object')
            ->setParameter('object', $object)
            ->orderBy('series.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<int, list<Report>>
     */
    private function reportsBySeriesForObject(ObjectRecord $object): array
    {
        $reports = $this->entityManager
            ->getRepository(Report::class)
            ->createQueryBuilder('report')
            ->addSelect('series')
            ->join('report.series', 'series')
            ->andWhere('series.objectRecord = :object')
            ->setParameter('object', $object)
            ->orderBy('report.createdAt', 'ASC')
            ->addOrderBy('report.id', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];

        foreach ($reports as $report) {
            $seriesId = $report->getSeries()->getId();

            if ($seriesId !== null) {
                $grouped[$seriesId][] = $report;
            }
        }

        return $grouped;
    }
}
