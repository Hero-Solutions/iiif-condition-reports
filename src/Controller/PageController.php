<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Report;
use App\Service\ObjectThumbnailProvider;
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
    public function reports(Request $request, EntityManagerInterface $entityManager, ObjectThumbnailProvider $thumbnailProvider): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $queryBuilder = $entityManager
            ->getRepository(Report::class)
            ->createQueryBuilder('report')
            ->addSelect('objectRecord', 'project')
            ->join('report.objectRecord', 'objectRecord')
            ->leftJoin('report.project', 'project')
            ->orderBy('report.updatedAt', 'DESC')
            ->setMaxResults(100);

        if ($q !== '') {
            $queryBuilder
                ->andWhere('report.title LIKE :q OR objectRecord.inventoryNumber LIKE :q OR objectRecord.title LIKE :q')
                ->setParameter('q', '%' . $q . '%');
        }

        $reports = $queryBuilder->getQuery()->getResult();
        $objects = array_map(static fn (Report $report) => $report->getObjectRecord(), $reports);

        return $this->render('reports/index.html.twig', [
            'reports' => $reports,
            'q' => $q,
            'thumbnail_urls' => $thumbnailProvider->thumbnailsForObjects($objects),
        ]);
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
