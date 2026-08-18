<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ObjectRecord;
use App\Entity\Project;
use App\Entity\ProjectObject;
use App\Entity\Report;
use App\Entity\ReportSeries;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReportSeriesController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/{_locale<nl|en>}/projects/{projectId}/objects/{objectId}/report-series/new', name: 'report_series_new_project_object', methods: ['GET', 'POST'])]
    public function newForProjectObject(int $projectId, int $objectId, Request $request): Response
    {
        $project = $this->entityManager->getRepository(Project::class)->find($projectId);
        $object = $this->entityManager->getRepository(ObjectRecord::class)->find($objectId);

        if (!$project instanceof Project || !$object instanceof ObjectRecord) {
            throw $this->createNotFoundException();
        }

        $link = $this->entityManager
            ->getRepository(ProjectObject::class)
            ->findOneBy([
                'project' => $project,
                'objectRecord' => $object,
            ]);

        if (!$link instanceof ProjectObject) {
            throw $this->createNotFoundException();
        }

        $series = $this->seriesFor($object, $project);

        return $this->redirectToRoute('reports_new', [
            '_locale' => $request->getLocale(),
            'seriesId' => $series->getId(),
        ]);
    }

    #[Route('/{_locale<nl|en>}/objects/{objectId}/report-series/new', name: 'report_series_new_object', methods: ['GET', 'POST'])]
    public function newForObject(int $objectId, Request $request): Response
    {
        $object = $this->entityManager->getRepository(ObjectRecord::class)->find($objectId);

        if (!$object instanceof ObjectRecord) {
            throw $this->createNotFoundException();
        }

        $series = $this->seriesFor($object, null);

        return $this->redirectToRoute('reports_new', [
            '_locale' => $request->getLocale(),
            'seriesId' => $series->getId(),
        ]);
    }

    #[Route('/{_locale<nl|en>}/report-series/{id}', name: 'report_series_edit', methods: ['GET', 'POST'])]
    public function edit(ReportSeries $series, Request $request): Response
    {
        $latestReport = $this->latestReportForSeries($series);

        if ($latestReport instanceof Report) {
            return $this->redirectToRoute('reports_edit', [
                '_locale' => $request->getLocale(),
                'id' => $latestReport->getId(),
            ]);
        }

        return $this->redirectToRoute('reports_new', [
            '_locale' => $request->getLocale(),
            'seriesId' => $series->getId(),
        ]);
    }

    private function seriesFor(ObjectRecord $object, ?Project $project): ReportSeries
    {
        $criteria = [
            'objectRecord' => $object,
            'project' => $project,
        ];

        $series = $this->entityManager
            ->getRepository(ReportSeries::class)
            ->findOneBy($criteria);

        if ($series instanceof ReportSeries) {
            return $series;
        }

        $series = new ReportSeries($object, $object->getInventoryNumber(), $project);
        $this->entityManager->persist($series);
        $this->entityManager->flush();

        return $series;
    }

    private function latestReportForSeries(ReportSeries $series): ?Report
    {
        return $this->entityManager
            ->getRepository(Report::class)
            ->createQueryBuilder('report')
            ->andWhere('report.series = :series')
            ->setParameter('series', $series)
            ->orderBy('report.createdAt', 'DESC')
            ->addOrderBy('report.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
