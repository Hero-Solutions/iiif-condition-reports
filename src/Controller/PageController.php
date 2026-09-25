<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Project;
use App\Entity\ProjectObject;
use App\Entity\Report;
use App\Service\ObjectThumbnailProvider;
use App\Service\ReportAuthorProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PageController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function home(): Response
    {
        return $this->redirectToRoute('projects_index', [
            '_locale' => 'nl',
        ]);
    }

    #[Route('/{_locale<nl|en>}/reports', name: 'reports_index')]
    public function reports(Request $request, EntityManagerInterface $entityManager, ObjectThumbnailProvider $thumbnailProvider, ReportAuthorProvider $reportAuthorProvider): Response
    {
        $projectId = filter_var($request->query->get('project'), FILTER_VALIDATE_INT);
        $selectedProject = null;

        if (is_int($projectId) && $projectId > 0) {
            $selectedProject = $entityManager->getRepository(Project::class)->find($projectId);

            if ($selectedProject === null) {
                throw $this->createNotFoundException();
            }
        }

        $reports = $this->filteredReports($request, $entityManager, 500);
        $objects = array_map(static fn (Report $report) => $report->getObjectRecord(), $reports);

        return $this->render('reports/index.html.twig', [
            'reports' => $reports,
            'q' => trim((string) $request->query->get('q', '')),
            'selected_project' => $selectedProject,
            'report_rooms' => $this->reportRooms($reports, $entityManager),
            'thumbnail_urls' => $thumbnailProvider->thumbnailsForObjects($objects),
            'report_author_names' => $reportAuthorProvider->namesFor($reports),
        ]);
    }

    #[Route('/{_locale<nl|en>}/reports/export/csv', name: 'reports_csv', methods: ['GET'])]
    public function reportsCsv(Request $request, EntityManagerInterface $entityManager, ReportAuthorProvider $reportAuthorProvider): Response
    {
        $reports = $this->filteredReports($request, $entityManager, null);
        $rooms = $this->reportRooms($reports, $entityManager);
        $authors = $reportAuthorProvider->namesFor($reports);
        $stream = fopen('php://temp', 'w+');

        if ($stream === false) {
            throw new \RuntimeException('The CSV export could not be created.');
        }

        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Rapport', 'Type', 'Inventarisnummer', 'Object', 'Project', 'Zaal / ruimte', 'Datum', 'Maker', 'Status'], ';');

        foreach ($reports as $report) {
            fputcsv($stream, [
                $report->getTitle(),
                $report->getType() === Report::TYPE_OTHER ? $report->getCustomType() : $report->getType(),
                $report->getObjectRecord()->getInventoryNumber(),
                $report->getObjectRecord()->getDisplayTitle($request->getLocale()),
                $report->getProject()?->getTitle(),
                $rooms[$report->getId()] ?? null,
                $report->getCreatedAt()->format('Y-m-d H:i'),
                $authors[$report->getId()] ?? null,
                $report->getStatus(),
            ], ';');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return new Response($csv === false ? '' : $csv, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="condition-reports-' . date('Y-m-d') . '.csv"',
        ]);
    }

    /** @return list<Report> */
    private function filteredReports(Request $request, EntityManagerInterface $entityManager, ?int $limit): array
    {
        $q = trim((string) $request->query->get('q', ''));
        $projectId = filter_var($request->query->get('project'), FILTER_VALIDATE_INT);
        $queryBuilder = $entityManager
            ->getRepository(Report::class)
            ->createQueryBuilder('report')
            ->addSelect('objectRecord', 'project')
            ->join('report.objectRecord', 'objectRecord')
            ->leftJoin('report.project', 'project')
            ->orderBy('report.updatedAt', 'DESC');

        if ($limit !== null) {
            $queryBuilder->setMaxResults($limit);
        }

        if ($q !== '') {
            $queryBuilder
                ->andWhere('objectRecord.inventoryNumber LIKE :q OR objectRecord.title LIKE :q')
                ->setParameter('q', '%' . $q . '%');
        }

        if (is_int($projectId) && $projectId > 0) {
            $queryBuilder->andWhere('project.id = :projectId')->setParameter('projectId', $projectId);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /** @param list<Report> $reports @return array<int, string> */
    private function reportRooms(array $reports, EntityManagerInterface $entityManager): array
    {
        $projectIds = [];
        $objectIds = [];

        foreach ($reports as $report) {
            if ($report->getProject()?->getId() !== null && $report->getObjectRecord()->getId() !== null) {
                $projectIds[] = $report->getProject()?->getId();
                $objectIds[] = $report->getObjectRecord()->getId();
            }
        }

        if ($projectIds === [] || $objectIds === []) {
            return [];
        }

        $links = $entityManager->getRepository(ProjectObject::class)->createQueryBuilder('projectObject')
            ->addSelect('project', 'objectRecord')
            ->join('projectObject.project', 'project')
            ->join('projectObject.objectRecord', 'objectRecord')
            ->andWhere('project.id IN (:projectIds)')
            ->andWhere('objectRecord.id IN (:objectIds)')
            ->setParameter('projectIds', array_values(array_unique($projectIds)))
            ->setParameter('objectIds', array_values(array_unique($objectIds)))
            ->getQuery()->getResult();
        $byContext = [];

        foreach ($links as $link) {
            $byContext[$link->getProject()->getId() . ':' . $link->getObjectRecord()->getId()] = $link->getRoom();
        }

        $rooms = [];

        foreach ($reports as $report) {
            $key = $report->getProject()?->getId() . ':' . $report->getObjectRecord()->getId();

            if (($byContext[$key] ?? null) !== null) {
                $rooms[(int) $report->getId()] = $byContext[$key];
            }
        }

        return $rooms;
    }

    #[Route('/{_locale<nl|en>}/admin', name: 'admin_index')]
    public function admin(): Response
    {
        return $this->render('admin/index.html.twig');
    }

    #[Route('/{_locale<nl|en>}/manual', name: 'manual_index')]
    public function manual(): Response
    {
        return $this->render('manual/index.html.twig');
    }
}
