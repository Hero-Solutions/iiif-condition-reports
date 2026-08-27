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

        $projects = $this->projectsForObject($object);
        $selection = count($projects) === 1
            ? (string) $projects[0]->getId()
            : (count($projects) === 0 ? 'standalone' : '');
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('report_project_choice_' . $object->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $selection = trim((string) $request->request->get('project_id', ''));
            $project = $selection === 'standalone' ? null : $this->projectForSelection($projects, $selection);

            if ($selection !== 'standalone' && !$project instanceof Project) {
                $error = 'reports.invalid_project';
            } else {
                $series = $this->seriesFor($object, $project);

                return $this->redirectToRoute('reports_new', [
                    '_locale' => $request->getLocale(),
                    'seriesId' => $series->getId(),
                ]);
            }
        }

        return $this->render('reports/project_choice.html.twig', [
            'object' => $object,
            'report' => null,
            'projects' => $projects,
            'selection' => $selection,
            'error' => $error,
            'allow_standalone' => true,
            'csrf_token_id' => 'report_project_choice_' . $object->getId(),
            'back_url' => $this->generateUrl('objects_edit', [
                '_locale' => $request->getLocale(),
                'id' => $object->getId(),
            ]),
        ]);
    }

    #[Route('/{_locale<nl|en>}/reports/{id}/project', name: 'reports_link_project', methods: ['GET', 'POST'])]
    public function linkProject(Report $report, Request $request): Response
    {
        if (!$report->isEditable()) {
            $this->addFlash('error', 'reports.final_report_locked');

            return $this->redirectToRoute('reports_show', [
                '_locale' => $request->getLocale(),
                'id' => $report->getId(),
            ]);
        }

        if ($report->getProject() instanceof Project) {
            return $this->redirectToRoute('reports_edit', [
                '_locale' => $request->getLocale(),
                'id' => $report->getId(),
            ]);
        }

        $object = $report->getObjectRecord();
        $projects = $this->projectsForObject($object);
        $selection = count($projects) === 1 ? (string) $projects[0]->getId() : '';
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('report_link_project_' . $report->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $selection = trim((string) $request->request->get('project_id', ''));
            $project = $this->projectForSelection($projects, $selection);

            if (!$project instanceof Project) {
                $error = 'reports.invalid_project';
            } else {
                $report->setSeries($this->seriesFor($object, $project));
                $this->entityManager->flush();
                $this->addFlash('success', 'reports.project_linked');

                return $this->redirectToRoute('reports_edit', [
                    '_locale' => $request->getLocale(),
                    'id' => $report->getId(),
                ]);
            }
        }

        return $this->render('reports/project_choice.html.twig', [
            'object' => $object,
            'report' => $report,
            'projects' => $projects,
            'selection' => $selection,
            'error' => $error,
            'allow_standalone' => false,
            'csrf_token_id' => 'report_link_project_' . $report->getId(),
            'back_url' => $this->generateUrl('reports_edit', [
                '_locale' => $request->getLocale(),
                'id' => $report->getId(),
            ]),
        ]);
    }

    #[Route('/{_locale<nl|en>}/projects/{projectId}/objects/{objectId}/unlinked-reports', name: 'reports_link_unlinked', methods: ['GET', 'POST'])]
    public function linkUnlinkedReports(int $projectId, int $objectId, Request $request): Response
    {
        $project = $this->entityManager->getRepository(Project::class)->find($projectId);
        $object = $this->entityManager->getRepository(ObjectRecord::class)->find($objectId);

        if (!$project instanceof Project || !$object instanceof ObjectRecord) {
            throw $this->createNotFoundException();
        }

        $projectObject = $this->entityManager->getRepository(ProjectObject::class)->findOneBy([
            'project' => $project,
            'objectRecord' => $object,
        ]);

        if (!$projectObject instanceof ProjectObject) {
            throw $this->createNotFoundException();
        }

        $reports = $this->unlinkedReportsForObject($object);

        if ($reports === []) {
            return $this->redirectToRoute('projects_edit', [
                '_locale' => $request->getLocale(),
                'id' => $project->getId(),
            ]);
        }

        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('link_unlinked_reports_' . $project->getId() . '_' . $object->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $selectedIds = array_map('intval', $request->request->all('report_ids'));
            $selectedIds = array_values(array_filter($selectedIds, static fn (int $id): bool => $id > 0));

            if ($selectedIds === []) {
                $error = 'reports.select_report_to_link';
            } else {
                $series = $this->seriesFor($object, $project);

                foreach ($reports as $unlinkedReport) {
                    if (in_array($unlinkedReport->getId(), $selectedIds, true)) {
                        $unlinkedReport->setSeries($series);
                    }
                }

                $this->entityManager->flush();
                $this->addFlash('success', 'reports.project_linked');

                return $this->redirectToRoute('projects_edit', [
                    '_locale' => $request->getLocale(),
                    'id' => $project->getId(),
                ]);
            }
        }

        return $this->render('reports/link_unlinked.html.twig', [
            'project' => $project,
            'object' => $object,
            'reports' => $reports,
            'error' => $error,
            'csrf_token_id' => 'link_unlinked_reports_' . $project->getId() . '_' . $object->getId(),
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

    /**
     * @return list<Project>
     */
    private function projectsForObject(ObjectRecord $object): array
    {
        $links = $this->entityManager
            ->getRepository(ProjectObject::class)
            ->createQueryBuilder('projectObject')
            ->addSelect('project')
            ->join('projectObject.project', 'project')
            ->andWhere('projectObject.objectRecord = :object')
            ->setParameter('object', $object)
            ->orderBy('project.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (ProjectObject $link): Project => $link->getProject(),
            $links,
        );
    }

    /**
     * @param list<Project> $projects
     */
    private function projectForSelection(array $projects, string $selection): ?Project
    {
        foreach ($projects as $project) {
            if ((string) $project->getId() === $selection) {
                return $project;
            }
        }

        return null;
    }

    /**
     * @return list<Report>
     */
    private function unlinkedReportsForObject(ObjectRecord $object): array
    {
        return $this->entityManager
            ->getRepository(Report::class)
            ->createQueryBuilder('report')
            ->andWhere('report.objectRecord = :object')
            ->andWhere('report.project IS NULL')
            ->andWhere('report.status = :status')
            ->setParameter('object', $object)
            ->setParameter('status', Report::STATUS_ACTIVE)
            ->orderBy('report.createdAt', 'ASC')
            ->addOrderBy('report.id', 'ASC')
            ->getQuery()
            ->getResult();
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
