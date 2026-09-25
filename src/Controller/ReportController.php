<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Actor;
use App\Entity\Annotation;
use App\Entity\DamageCase;
use App\Entity\OrganizationContact;
use App\Entity\IIIFManifest;
use App\Entity\ObjectManifest;
use App\Entity\ObjectRecord;
use App\Entity\Project;
use App\Entity\ProjectActor;
use App\Entity\ProjectObject;
use App\Entity\ProjectObjectActor;
use App\Entity\Report;
use App\Entity\ReportActor;
use App\Entity\ReportDocument;
use App\Entity\ReportImage;
use App\Entity\ReportManifest;
use App\Entity\ReportSeries;
use App\Entity\User;
use App\Service\ObjectThumbnailProvider;
use App\Service\ObjectImageStorage;
use App\Service\FrameSchemaCatalog;
use App\Service\ProjectReportDefaults;
use App\Service\ReportAuthorProvider;
use App\Service\ReportDocumentBuilder;
use App\Service\ReportDocumentStorage;
use App\Service\ReportFormDefinition;
use App\Service\ReportPdfRenderer;
use App\Service\ReportImageStorage;
use App\Value\ActorRole;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ReportController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ReportFormDefinition $formDefinition,
        private readonly ObjectThumbnailProvider $thumbnailProvider,
        private readonly TranslatorInterface $translator,
        private readonly ProjectReportDefaults $projectReportDefaults,
        private readonly ReportDocumentBuilder $documentBuilder,
        private readonly ReportPdfRenderer $pdfRenderer,
        private readonly ReportAuthorProvider $authorProvider,
        private readonly ObjectImageStorage $imageStorage,
        private readonly ReportImageStorage $reportImageStorage,
        private readonly ReportDocumentStorage $reportDocumentStorage,
        private readonly FrameSchemaCatalog $frameSchemaCatalog,
    ) {
    }

    #[Route('/{_locale<nl|en>}/report-series/{seriesId}/reports/new', name: 'reports_new', methods: ['GET', 'POST'])]
    public function new(int $seriesId, Request $request): Response
    {
        $this->assertWritableUser();

        $series = $this->entityManager->getRepository(ReportSeries::class)->find($seriesId);

        if (!$series instanceof ReportSeries) {
            throw $this->createNotFoundException();
        }

        $report = $this->entityManager->wrapInTransaction(function () use ($series): Report {
            // Serialize draft creation for this object, including requests from other projects.
            $this->entityManager->lock($series->getObjectRecord(), LockMode::PESSIMISTIC_WRITE);

            $activeDraft = $this->entityManager
                ->getRepository(Report::class)
                ->findOneBy([
                    'objectRecord' => $series->getObjectRecord(),
                    'status' => Report::STATUS_ACTIVE,
                ], ['createdAt' => 'DESC', 'id' => 'DESC']);

            return $activeDraft ?? $this->createDraft($series);
        });

        if ($report->getSeries() !== $series) {
            $project = $series->getProject();

            return $this->render('reports/existing_draft.html.twig', [
                'report' => $report,
                'back_url' => $project instanceof Project
                    ? $this->generateUrl('projects_edit', ['_locale' => $request->getLocale(), 'id' => $project->getId()])
                    : $this->generateUrl('objects_edit', ['_locale' => $request->getLocale(), 'id' => $series->getObjectRecord()->getId()]),
            ]);
        }

        return $this->redirectToRoute('reports_edit', [
            '_locale' => $request->getLocale(),
            'id' => $report->getId(),
        ]);
    }

    #[Route('/{_locale<nl|en>}/reports/{id}/edit', name: 'reports_edit', methods: ['GET', 'POST'])]
    public function edit(Report $report, Request $request): Response
    {
        if (!$report->isEditable() || $this->isGranted(User::ROLE_READ_ONLY)) {
            return $this->redirectToRoute('reports_show', [
                '_locale' => $request->getLocale(),
                'id' => $report->getId(),
            ]);
        }

        return $this->handleReport($report, $request);
    }

    #[Route('/{_locale<nl|en>}/reports/{id}', name: 'reports_show', methods: ['GET'])]
    public function show(Report $report, Request $request): Response
    {
        return $this->render('reports/show.html.twig', $this->documentContext(
            $report,
            $request,
            $this->reportMainImageSource($report, false),
        ));
    }

    #[Route('/{_locale<nl|en>}/reports/{id}/pdf', name: 'reports_pdf', methods: ['GET'])]
    public function pdf(Report $report, Request $request): Response
    {
        if ($report->isEditable()) {
            throw $this->createAccessDeniedException('Only finalized reports can be exported.');
        }

        $pdf = $this->pdfRenderer->render($this->documentContext(
            $report,
            $request,
            $this->reportMainImageSource($report, true),
            true,
        ));
        $filename = 'condition-report-' . $report->getId() . '.pdf';

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => (string) strlen($pdf),
        ]);
    }

    #[Route('/{_locale<nl|en>}/reports/{id}/json', name: 'reports_json', methods: ['GET'])]
    public function exportJson(Report $report): JsonResponse
    {
        $images = array_map(fn (ReportImage $image): array => [
            'path' => $image->getPath(),
            'thumbnail' => $image->getThumbnailPath(),
            'source' => $image->getSource(),
            'name' => $this->reportImageLabel($image),
        ], $this->reportImages($report));
        $documents = array_map(static fn (ReportDocument $document): array => [
            'category' => $document->getCategory(),
            'path' => $document->getPath(),
            'name' => $document->getOriginalName(),
            'mime_type' => $document->getMimeType(),
            'size' => $document->getSize(),
        ], $this->reportDocuments($report));

        return $this->json([
            'id' => $report->getId(),
            'status' => $report->getStatus(),
            'type' => $report->getType(),
            'custom_type' => $report->getCustomType(),
            'title' => $report->getTitle(),
            'description' => $report->getDescription(),
            'reason' => $report->getReason(),
            'custom_reason' => $report->getCustomReason(),
            'receipt_at' => $report->getReceiptAt()?->format('Y-m-d'),
            'started_at' => $report->getStartedAt()?->format(\DateTimeInterface::ATOM),
            'ended_at' => $report->getEndedAt()?->format(\DateTimeInterface::ATOM),
            'object' => [
                'id' => $report->getObjectRecord()->getId(),
                'inventory_number' => $report->getObjectRecord()->getInventoryNumber(),
                'title' => $report->getObjectRecord()->getDisplayTitle('nl'),
            ],
            'project' => $report->getProject() ? [
                'id' => $report->getProject()?->getId(),
                'reference' => $report->getProject()?->getReferenceCode(),
                'title' => $report->getProject()?->getTitle(),
            ] : null,
            'data' => $report->getData(),
            'images' => $images,
            'documents' => $documents,
            'created_at' => $report->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $report->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'finalized_at' => $report->getFinalizedAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/{_locale<nl|en>}/reports/{id}/images', name: 'reports_images_upload', methods: ['POST'])]
    public function uploadImages(Report $report, Request $request): Response
    {
        $this->assertReportMediaRequest($report, $request, 'report_images_');
        $files = $request->files->all('images');
        $sortOrder = $this->nextReportImageSortOrder($report);
        $stored = 0;

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            try {
                $result = $this->reportImageStorage->store($file);
                $image = (new ReportImage($report, $result['image_url']))
                    ->setThumbnailPath($result['thumbnail_url'])
                    ->setObjectRecord($report->getObjectRecord())
                    ->setOriginalName($file->getClientOriginalName())
                    ->setMimeType($file->getClientMimeType())
                    ->setSortOrder($sortOrder++);
                $this->entityManager->persist($image);
                ++$stored;
            } catch (FileException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        $this->entityManager->flush();

        if ($stored > 0) {
            $this->addFlash('success', 'reports.images_added');
        }

        return $this->redirectToReportTab($report, $request, 'photos');
    }

    #[Route('/{_locale<nl|en>}/reports/{id}/images/url', name: 'reports_images_url', methods: ['POST'])]
    public function addImageUrl(Report $report, Request $request): Response
    {
        $this->assertReportMediaRequest($report, $request, 'report_images_');
        $url = trim((string) $request->request->get('image_url'));

        if ($url === '') {
            $this->addFlash('error', 'reports.image_url_required');

            return $this->redirectToReportTab($report, $request, 'photos');
        }

        try {
            $result = $this->reportImageStorage->storeExternal($url);
            $image = (new ReportImage($report, $result['image_url'], ReportImage::SOURCE_URL))
                ->setThumbnailPath($result['thumbnail_url'])
                ->setObjectRecord($report->getObjectRecord())
                ->setOriginalName(basename((string) parse_url($url, PHP_URL_PATH)) ?: null)
                ->setSortOrder($this->nextReportImageSortOrder($report));
            $this->entityManager->persist($image);
            $this->entityManager->flush();
            $this->addFlash('success', 'reports.images_added');
        } catch (FileException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToReportTab($report, $request, 'photos');
    }

    #[Route('/{_locale<nl|en>}/reports/{id}/images/iiif', name: 'reports_images_iiif', methods: ['POST'])]
    public function addIiifImage(Report $report, Request $request): Response
    {
        $this->assertReportMediaRequest($report, $request, 'report_images_');
        $manifestUrl = trim((string) $request->request->get('manifest_url'));

        if ($manifestUrl === '') {
            $this->addFlash('error', 'reports.iiif_url_required');

            return $this->redirectToReportTab($report, $request, 'photos');
        }

        try {
            $result = $this->reportImageStorage->storeIiifManifest($manifestUrl);
            $image = (new ReportImage($report, $result['image_url'], ReportImage::SOURCE_IIIF))
                ->setThumbnailPath($result['thumbnail_url'])
                ->setObjectRecord($report->getObjectRecord())
                ->setOriginalName('IIIF')
                ->setSortOrder($this->nextReportImageSortOrder($report));
            $this->entityManager->persist($image);
            $this->entityManager->flush();
            $this->addFlash('success', 'reports.images_added');
        } catch (FileException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToReportTab($report, $request, 'photos');
    }

    #[Route('/{_locale<nl|en>}/reports/{id}/images/frame-schema', name: 'reports_images_frame_schema', methods: ['POST'])]
    public function addFrameSchema(Report $report, Request $request): Response
    {
        $this->assertReportMediaRequest($report, $request, 'report_images_');
        $schemaKey = trim((string) $request->request->get('schema'));
        $schema = $this->frameSchemaCatalog->get($schemaKey);

        if ($schema === null) {
            $this->addFlash('error', 'reports.annotation_frame_invalid');

            return $this->redirectToReportTab($report, $request, 'photos');
        }

        $existing = $this->entityManager->getRepository(ReportImage::class)->findOneBy([
            'report' => $report,
            'source' => ReportImage::SOURCE_SCHEMA,
            'path' => $schema['path'],
        ]);

        if (!$existing instanceof ReportImage) {
            $image = (new ReportImage($report, $schema['path'], ReportImage::SOURCE_SCHEMA))
                ->setThumbnailPath($schema['path'])
                ->setObjectRecord($report->getObjectRecord())
                ->setOriginalName($schemaKey)
                ->setMimeType('image/svg+xml')
                ->setSortOrder($this->nextReportImageSortOrder($report));
            $this->entityManager->persist($image);
            $this->entityManager->flush();
            $existing = $image;
        }

        return $this->redirectToReportTab($report, $request, 'photos');
    }

    #[Route('/{_locale<nl|en>}/reports/{reportId}/images/{imageId}/rotate', name: 'reports_images_rotate', methods: ['POST'])]
    public function rotateImage(int $reportId, int $imageId, Request $request): Response
    {
        [$report, $image] = $this->reportImageForIds($reportId, $imageId);
        $this->assertReportMediaRequest($report, $request, 'report_image_' . $imageId . '_');

        try {
            $this->reportImageStorage->rotate($image->getPath());
            $this->addFlash('success', 'reports.image_rotated');
        } catch (FileException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToReportTab($report, $request, 'photos');
    }

    #[Route('/{_locale<nl|en>}/reports/{reportId}/images/{imageId}/move', name: 'reports_images_move', methods: ['POST'])]
    public function moveImage(int $reportId, int $imageId, Request $request): Response
    {
        [$report, $image] = $this->reportImageForIds($reportId, $imageId);
        $this->assertReportMediaRequest($report, $request, 'report_image_' . $imageId . '_');
        $direction = $request->request->get('direction') === 'up' ? -1 : 1;
        $images = $this->reportPhotos($report);
        $position = array_search($image, $images, true);
        $target = is_int($position) ? $position + $direction : -1;

        if (is_int($position) && isset($images[$target])) {
            $currentOrder = $image->getSortOrder();
            $image->setSortOrder($images[$target]->getSortOrder());
            $images[$target]->setSortOrder($currentOrder);
            $this->entityManager->flush();
        }

        return $this->redirectToReportTab($report, $request, 'photos');
    }

    #[Route('/{_locale<nl|en>}/reports/{reportId}/images/{imageId}/delete', name: 'reports_images_delete', methods: ['POST'])]
    public function deleteImage(int $reportId, int $imageId, Request $request): Response
    {
        [$report, $image] = $this->reportImageForIds($reportId, $imageId);
        $this->assertReportMediaRequest($report, $request, 'report_image_' . $imageId . '_');

        foreach ($this->entityManager->getRepository(Annotation::class)->findBy(['reportImage' => $image]) as $annotation) {
            $annotation->delete();
        }

        if ($image->getSource() !== ReportImage::SOURCE_SCHEMA) {
            $this->reportImageStorage->remove($image->getPath());
            $this->reportImageStorage->remove($image->getThumbnailPath());
        }

        $this->entityManager->remove($image);
        $this->entityManager->flush();

        return $this->redirectToReportTab($report, $request, 'photos');
    }

    #[Route('/{_locale<nl|en>}/reports/{id}/documents', name: 'reports_documents_upload', methods: ['POST'])]
    public function uploadDocuments(Report $report, Request $request): Response
    {
        $this->assertReportMediaRequest($report, $request, 'report_documents_');
        $files = $request->files->all('documents');
        $category = (string) $request->request->get('category', ReportDocument::CATEGORY_GENERAL);
        $sortOrder = count($this->reportDocuments($report));
        $stored = 0;

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            try {
                $result = $this->reportDocumentStorage->store($file);
                $document = (new ReportDocument(
                    $report,
                    $result['path'],
                    $file->getClientOriginalName(),
                    $result['mime_type'],
                ))
                    ->setCategory($category)
                    ->setSize($result['size'])
                    ->setSortOrder($sortOrder++);
                $this->entityManager->persist($document);
                ++$stored;
            } catch (FileException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        $this->entityManager->flush();

        if ($stored > 0) {
            $this->addFlash('success', 'reports.documents_added');
        }

        return $this->redirectToReportTab($report, $request, 'documents');
    }

    #[Route('/{_locale<nl|en>}/reports/{reportId}/documents/{documentId}/delete', name: 'reports_documents_delete', methods: ['POST'])]
    public function deleteDocument(int $reportId, int $documentId, Request $request): Response
    {
        [$report, $document] = $this->reportDocumentForIds($reportId, $documentId);
        $this->assertReportMediaRequest($report, $request, 'report_document_' . $documentId . '_');
        $this->reportDocumentStorage->remove($document->getPath());
        $this->entityManager->remove($document);
        $this->entityManager->flush();

        return $this->redirectToReportTab($report, $request, 'documents');
    }

    #[Route('/{_locale<nl|en>}/reports/{id}/autosave', name: 'reports_autosave', methods: ['POST'])]
    public function autosave(Report $report, Request $request): JsonResponse
    {
        if (!$report->isEditable() || $this->isGranted(User::ROLE_READ_ONLY)) {
            return new JsonResponse(['saved' => false, 'reason' => 'read_only'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('report_edit_' . $report->getId(), (string) $request->request->get('_token'))) {
            return new JsonResponse(['saved' => false], Response::HTTP_FORBIDDEN);
        }

        if (!$this->hasCurrentVersion($report, $request)) {
            return new JsonResponse(['saved' => false, 'reason' => 'conflict'], Response::HTTP_CONFLICT);
        }

        $this->applySubmittedReport($report, $request);

        try {
            $this->entityManager->flush();
        } catch (OptimisticLockException) {
            return new JsonResponse(['saved' => false, 'reason' => 'conflict'], Response::HTTP_CONFLICT);
        }

        return new JsonResponse([
            'saved' => true,
            'saved_at' => $report->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'version' => $report->getVersion(),
        ]);
    }

    #[Route('/{_locale<nl|en>}/reports/{id}/delete', name: 'reports_delete', methods: ['POST'])]
    public function delete(Report $report, Request $request): Response
    {
        $this->assertWritableUser();

        if (!$this->isCsrfTokenValid('report_delete_' . $report->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $backUrl = match ($request->request->get('return_to')) {
            'reports_index' => $this->generateUrl('reports_index', ['_locale' => $request->getLocale()]),
            'object' => $this->generateUrl('objects_edit', [
                '_locale' => $request->getLocale(),
                'id' => $report->getObjectRecord()->getId(),
            ]),
            default => $this->backUrl($report, $request),
        };

        if ($report->getStatus() !== Report::STATUS_ACTIVE && !$this->isGranted(User::ROLE_ADMIN)) {
            $this->addFlash('error', 'reports.delete_draft_only');

            return $this->redirect($backUrl);
        }

        $this->removeReportFiles($report);
        $this->entityManager->remove($report);
        $this->entityManager->flush();
        $this->addFlash('success', 'reports.deleted');

        return $this->redirect($backUrl);
    }

    #[Route('/{_locale<nl|en>}/reports/{reportId}/actors/add', name: 'reports_actors_add', methods: ['POST'])]
    public function addReportActor(int $reportId, Request $request): Response
    {
        $report = $this->reportForId($reportId);
        $this->assertReportEditable($report);

        if (!$this->isCsrfTokenValid('report_actor_add_' . $report->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $actorData = $this->actorAssignmentData($request);

        if ($actorData === null) {
            $this->addFlash('error', 'actors.invalid');

            return $this->redirectToReport($report, $request);
        }

        $actor = $this->findOrCreateActor($actorData);

        if (!$actor instanceof Actor) {
            $this->addFlash('error', 'actors.invalid');

            return $this->redirectToReport($report, $request);
        }

        $reportActor = new ReportActor($report, $actor);
        $reportActor
            ->setRole($actorData['role'])
            ->setCustomRole($actorData['custom_role'])
            ->normalizeCustomRole();

        $this->entityManager->persist($reportActor);
        $this->entityManager->flush();
        $this->addFlash('success', 'actors.added');

        return $this->redirectToReport($report, $request);
    }

    #[Route('/{_locale<nl|en>}/reports/{reportId}/actors/{id}/remove', name: 'reports_actors_remove', methods: ['POST'])]
    public function removeReportActor(int $reportId, ReportActor $reportActor, Request $request): Response
    {
        $report = $this->reportForId($reportId);
        $this->assertReportEditable($report);

        if ($reportActor->getReport()->getId() !== $report->getId()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('report_actor_remove_' . $reportActor->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->entityManager->remove($reportActor);
        $this->entityManager->flush();
        $this->addFlash('success', 'actors.removed');

        return $this->redirectToReport($report, $request);
    }

    #[Route('/{_locale<nl|en>}/reports/{reportId}/actors/{id}/edit', name: 'reports_actors_edit_assignment', methods: ['POST'])]
    public function editReportActorAssignment(int $reportId, ReportActor $reportActor, Request $request): Response
    {
        $report = $this->reportForId($reportId);
        $this->assertReportEditable($report);

        if ($reportActor->getReport()->getId() !== $report->getId()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('report_actor_edit_' . $reportActor->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->updateActorAssignment($reportActor, $request)) {
            $this->addFlash('error', 'actors.invalid');

            return $this->redirectToReport($report, $request);
        }

        $this->entityManager->flush();
        $this->addFlash('success', 'actors.saved');

        return $this->redirectToReport($report, $request);
    }

    #[Route('/{_locale<nl|en>}/reports/{reportId}/actors/{id}/contact', name: 'reports_actors_contact', methods: ['POST'])]
    public function updateReportActorContact(int $reportId, ReportActor $reportActor, Request $request): Response
    {
        $report = $this->reportForId($reportId);
        $this->assertReportEditable($report);

        if ($reportActor->getReport()->getId() !== $report->getId()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('report_actor_contact_' . $reportActor->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $contactResult = $this->contactPersonAssignment($reportActor->getActor(), $request);

        if (!$contactResult['valid']) {
            $this->addFlash('error', 'actors.invalid_contact');

            return $this->redirectToReport($report, $request);
        }

        $reportActor->setContactPerson($contactResult['contact_person']);
        $this->entityManager->flush();
        $this->addFlash('success', 'actors.contact_saved');

        return $this->redirectToReport($report, $request);
    }

    #[Route('/{_locale<nl|en>}/reports/{id}/finalize', name: 'reports_finalize', methods: ['POST'])]
    public function finalize(Report $report, Request $request): Response
    {
        $this->assertReportEditable($report);

        if (!$this->isCsrfTokenValid('report_edit_' . $report->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->hasCurrentVersion($report, $request)) {
            $this->addFlash('error', 'reports.edit_conflict');

            return $this->redirectToReport($report, $request);
        }

        $this->applySubmittedReport($report, $request);
        if (!$report->hasSelectedType()) {
            try {
                $this->entityManager->flush();
            } catch (OptimisticLockException) {
                $this->addFlash('error', 'reports.edit_conflict');

                return $this->redirectToReport($report, $request);
            }
            $this->addFlash('error', 'reports.finalize_incomplete');

            return $this->redirectToReport($report, $request);
        }

        $user = $this->getUser();
        $report->finalize($user instanceof User ? $user->getId() : null);

        try {
            $this->entityManager->flush();
        } catch (OptimisticLockException) {
            $this->addFlash('error', 'reports.edit_conflict');

            return $this->redirectToReport($report, $request);
        }

        $this->addFlash('success', 'reports.finalized');

        return $this->redirectToRoute('reports_show', [
            '_locale' => $request->getLocale(),
            'id' => $report->getId(),
        ]);
    }

    #[Route('/{_locale<nl|en>}/reports/{id}/archive', name: 'reports_archive', methods: ['POST'])]
    public function archive(Report $report, Request $request): Response
    {
        $this->assertWritableUser();

        if (!$this->isCsrfTokenValid('report_archive_' . $report->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if ($report->getStatus() !== Report::STATUS_FINALIZED) {
            throw $this->createAccessDeniedException();
        }

        $report->archive();

        try {
            $this->entityManager->flush();
        } catch (OptimisticLockException) {
            $this->addFlash('error', 'reports.edit_conflict');

            return $this->redirectToRoute('reports_show', [
                '_locale' => $request->getLocale(),
                'id' => $report->getId(),
            ]);
        }
        $this->addFlash('success', 'reports.archived');

        return $this->redirectToRoute('reports_show', [
            '_locale' => $request->getLocale(),
            'id' => $report->getId(),
        ]);
    }

    private function handleReport(Report $report, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $tokenId = 'report_edit_' . $report->getId();

            if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            if (!$this->hasCurrentVersion($report, $request)) {
                $this->addFlash('error', 'reports.edit_conflict');

                return $this->redirectToReport($report, $request);
            }

            $this->applySubmittedReport($report, $request);

            try {
                $this->entityManager->flush();
            } catch (OptimisticLockException) {
                $this->addFlash('error', 'reports.edit_conflict');

                return $this->redirectToReport($report, $request);
            }
            $this->addFlash('success', 'reports.saved');

            return $this->redirectToReport($report, $request);
        }

        $reportActors = $this->reportActors($report);
        $definition = $this->formDefinition->forObject($report->getObjectRecord());
        $reportImages = $this->reportImages($report);
        $reportPhotos = $this->reportPhotosFrom($reportImages);

        return $this->render('reports/form.html.twig', [
            'report' => $report,
            'series' => $report->getSeries(),
            'object' => $report->getObjectRecord(),
            'definition' => $definition,
            'report_types' => Report::typeChoices(),
            'report_reasons' => $this->formDefinition->reasonChoices(),
            'report_actors' => $reportActors,
            'can_finalize' => $report->hasSelectedType(),
            'actor_type_choices' => Actor::typeChoices(),
            'actor_role_choices' => ActorRole::choices(),
            'actor_options' => $this->actorOptions(),
            'contact_options' => $this->contactOptions(),
            'is_new' => false,
            'csrf_token_id' => 'report_edit_' . $report->getId(),
            'back_url' => $this->backUrl($report, $request),
            'thumbnail_url' => $this->thumbnailProvider->thumbnailForObject($report->getObjectRecord()),
            'annotation_manifest_url' => $this->annotationManifestUrl($report),
            'annotation_image_url' => $report->getObjectRecord()->getImageUrl(),
            'report_images' => $reportPhotos,
            'annotation_images' => $this->annotationImageViews($reportImages),
            'damage_case_count' => $this->activeDamageCaseCount($report),
            'frame_schema_choices' => $this->frameSchemaChoices($reportImages),
            'report_documents' => $this->reportDocuments($report),
            'document_categories' => ReportDocument::categoryChoices(),
            'can_link_project' => $report->getProject() === null && $this->objectBelongsToProject($report->getObjectRecord()),
        ]);
    }

    private function applySubmittedReport(Report $report, Request $request): void
    {
        $type = (string) $request->request->get('type', Report::TYPE_OTHER);
        $customType = mb_substr(trim((string) $request->request->get('custom_type', '')), 0, 100);
        $title = trim((string) $request->request->get('title', ''));
        $data = $request->request->all('report_data');

        $report
            ->setType($type)
            ->setCustomType($customType);
        $defaultTitle = $report->hasSelectedType()
            ? ($report->getType() === Report::TYPE_OTHER
                ? (string) $report->getCustomType()
                : $this->translator->trans('report_type.' . $report->getType()))
            : '';
        $report->setTitle($title !== '' ? $title : $defaultTitle);
        $report
            ->setDescription($this->requestText($request, 'description'))
            ->setReason($this->requestText($request, 'reason'))
            ->setCustomReason($this->requestText($request, 'custom_reason'))
            ->setReceiptAt($this->requestDate($request, 'receipt_at'));
        $report->setStartedAt($this->requestDate($request, 'started_at'));
        $report->setEndedAt($this->requestDate($request, 'ended_at'));
        $report->setData($data);
    }

    private function createDraft(ReportSeries $series): Report
    {
        $previousReport = $this->latestReportForObject($series->getObjectRecord());
        $report = new Report($series, $previousReport?->getType() ?? Report::TYPE_OTHER);
        $projectDefaults = $series->getProject() instanceof Project
            ? $this->projectReportDefaults->for($series->getProject(), $series->getObjectRecord())
            : [];
        $user = $this->getUser();

        if ($user instanceof User) {
            $report->setCreatedById($user->getId());
        }

        if ($previousReport instanceof Report) {
            $report
                ->setBasedOnReport($previousReport)
                ->setCustomType($previousReport->getCustomType())
                ->setTitle($previousReport->getTitle())
                ->setDescription($previousReport->getDescription())
                ->setReason($previousReport->getReason())
                ->setCustomReason($previousReport->getCustomReason())
                ->setReceiptAt($previousReport->getReceiptAt())
                ->setStartedAt($previousReport->getStartedAt())
                ->setEndedAt($previousReport->getEndedAt())
                ->setData(array_replace($projectDefaults, $previousReport->getData()));
        } else {
            $report
                ->setTitle($series->getTitle())
                ->setDescription($series->getDescription())
                ->setStartedAt($series->getStartedAt())
                ->setEndedAt($series->getEndedAt())
                ->setData($projectDefaults);
        }

        $this->entityManager->persist($report);
        $copiedActorKeys = [];

        if ($previousReport instanceof Report) {
            $this->copyReportActors($previousReport, $report, $copiedActorKeys);
        }

        $this->copyProjectActors($report, $copiedActorKeys);
        $this->copyProjectObjectActors($report, $copiedActorKeys);

        return $report;
    }

    private function latestReportForObject(ObjectRecord $object): ?Report
    {
        return $this->entityManager
            ->getRepository(Report::class)
            ->createQueryBuilder('report')
            ->andWhere('report.objectRecord = :object')
            ->setParameter('object', $object)
            ->orderBy('report.createdAt', 'DESC')
            ->addOrderBy('report.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param array<string, true> $copiedActorKeys
     */
    private function copyReportActors(Report $sourceReport, Report $targetReport, array &$copiedActorKeys): void
    {
        foreach ($this->reportActors($sourceReport) as $sourceActor) {
            $this->copyActorToReport(
                $targetReport,
                $sourceActor->getActor(),
                $sourceActor->getContactPerson(),
                $sourceActor->getRole(),
                $sourceActor->getCustomRole(),
                $copiedActorKeys,
            );
        }
    }

    /**
     * @param array<string, true> $copiedActorKeys
     */
    private function copyProjectActors(Report $report, array &$copiedActorKeys): void
    {
        $project = $report->getProject();

        if (!$project instanceof Project) {
            return;
        }

        $actors = $this->entityManager
            ->getRepository(ProjectActor::class)
            ->findBy(['project' => $project], ['createdAt' => 'ASC', 'id' => 'ASC']);

        foreach ($actors as $sourceActor) {
            $this->copyActorToReport(
                $report,
                $sourceActor->getActor(),
                $sourceActor->getContactPerson(),
                $sourceActor->getRole(),
                $sourceActor->getCustomRole(),
                $copiedActorKeys,
            );
        }
    }

    /**
     * @param array<string, true> $copiedActorKeys
     */
    private function copyProjectObjectActors(Report $report, array &$copiedActorKeys): void
    {
        $project = $report->getProject();

        if (!$project instanceof Project) {
            return;
        }

        $projectObject = $this->entityManager
            ->getRepository(ProjectObject::class)
            ->findOneBy([
                'project' => $project,
                'objectRecord' => $report->getObjectRecord(),
            ]);

        if (!$projectObject instanceof ProjectObject) {
            return;
        }

        $actors = $this->entityManager
            ->getRepository(ProjectObjectActor::class)
            ->findBy(['projectObject' => $projectObject], ['createdAt' => 'ASC', 'id' => 'ASC']);

        foreach ($actors as $sourceActor) {
            $this->copyActorToReport(
                $report,
                $sourceActor->getActor(),
                $sourceActor->getContactPerson(),
                $sourceActor->getRole(),
                $sourceActor->getCustomRole(),
                $copiedActorKeys,
            );
        }
    }

    /**
     * @param array<string, true> $copiedActorKeys
     */
    private function copyActorToReport(
        Report $report,
        Actor $actor,
        ?OrganizationContact $contactPerson,
        string $role,
        ?string $customRole,
        array &$copiedActorKeys,
    ): void {
        $key = $this->actorCopyKey($actor, $contactPerson, $role, $customRole);

        if (isset($copiedActorKeys[$key])) {
            return;
        }

        $copy = new ReportActor($report, $actor);
        $copy
            ->setContactPerson($contactPerson)
            ->setRole($role)
            ->setCustomRole($customRole)
            ->normalizeCustomRole();

        $this->entityManager->persist($copy);
        $copiedActorKeys[$key] = true;
    }

    private function actorCopyKey(Actor $actor, ?OrganizationContact $contactPerson, string $role, ?string $customRole): string
    {
        return implode('|', [
            (string) $actor->getId(),
            (string) ($contactPerson?->getId() ?? 0),
            $role,
            $customRole ?? '',
        ]);
    }

    /**
     * @return list<ReportActor>
     */
    private function reportActors(Report $report): array
    {
        if ($report->getId() === null) {
            return [];
        }

        return $this->entityManager
            ->getRepository(ReportActor::class)
            ->createQueryBuilder('reportActor')
            ->addSelect('actor', 'contactPerson')
            ->join('reportActor.actor', 'actor')
            ->leftJoin('reportActor.contactPerson', 'contactPerson')
            ->andWhere('reportActor.report = :report')
            ->setParameter('report', $report)
            ->orderBy('reportActor.createdAt', 'ASC')
            ->addOrderBy('reportActor.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function reportForId(int $reportId): Report
    {
        $report = $this->entityManager->getRepository(Report::class)->find($reportId);

        if (!$report instanceof Report) {
            throw $this->createNotFoundException();
        }

        return $report;
    }

    /**
     * @return array{actor_id: ?int, actor_name: string, actor_type: string, role: string, custom_role: ?string}|null
     */
    private function actorAssignmentData(Request $request): ?array
    {
        $actorId = $this->positiveInt($request->request->get('actor_id'));
        $actorName = mb_substr(trim((string) $request->request->get('actor_name', '')), 0, 255);
        $actorType = (string) $request->request->get('actor_type', Actor::TYPE_ORGANIZATION);
        $role = ActorRole::normalize((string) $request->request->get('role', ''));
        $customRole = mb_substr(trim((string) $request->request->get('custom_role', '')), 0, 100);

        if (($actorName === '' && $actorId === null) || $role === null) {
            return null;
        }

        if (!in_array($actorType, [Actor::TYPE_ORGANIZATION, Actor::TYPE_PERSON], true)) {
            return null;
        }

        if ($role === ActorRole::OTHER && $customRole === '') {
            return null;
        }

        return [
            'actor_id' => $actorId,
            'actor_name' => $actorName,
            'actor_type' => $actorType,
            'role' => $role,
            'custom_role' => $role === ActorRole::OTHER ? $customRole : null,
        ];
    }

    /**
     * @param array{actor_id: ?int, actor_name: string, actor_type: string} $actorData
     */
    private function findOrCreateActor(array $actorData): ?Actor
    {
        if ($actorData['actor_id'] !== null) {
            $actor = $this->entityManager
                ->getRepository(Actor::class)
                ->find($actorData['actor_id']);

            return $actor instanceof Actor ? $actor : null;
        }

        $actor = $this->entityManager
            ->getRepository(Actor::class)
            ->findOneBy(['name' => $actorData['actor_name']]);

        if ($actor instanceof Actor) {
            return $actor;
        }

        $actor = new Actor($actorData['actor_name'], $actorData['actor_type']);
        $this->entityManager->persist($actor);

        return $actor;
    }

    private function updateActorAssignment(ReportActor $assignment, Request $request): bool
    {
        $actor = $assignment->getActor();
        $actorName = mb_substr(trim((string) $request->request->get('actor_name', '')), 0, 255);
        $actorType = (string) $request->request->get('actor_type', Actor::TYPE_ORGANIZATION);
        $role = ActorRole::normalize((string) $request->request->get('role', ''));
        $customRole = mb_substr(trim((string) $request->request->get('custom_role', '')), 0, 100);

        if ($actorName === '' || $role === null) {
            return false;
        }

        if (!in_array($actorType, [Actor::TYPE_ORGANIZATION, Actor::TYPE_PERSON], true)) {
            return false;
        }

        if ($role === ActorRole::OTHER && $customRole === '') {
            return false;
        }

        $existingActor = $this->entityManager
            ->getRepository(Actor::class)
            ->findOneBy(['name' => $actorName]);

        if ($existingActor instanceof Actor && $existingActor->getId() !== $actor->getId()) {
            return false;
        }

        $actor
            ->setName($actorName)
            ->setType($actorType)
            ->setAlias($this->requestText($request, 'actor_alias'))
            ->setLogo($this->requestText($request, 'actor_logo'))
            ->setVat($this->requestText($request, 'actor_vat'))
            ->setAddress($this->requestText($request, 'actor_address'))
            ->setPostal($this->requestText($request, 'actor_postal'))
            ->setCity($this->requestText($request, 'actor_city'))
            ->setStateProvince($this->requestText($request, 'actor_state_province'))
            ->setCountry($this->requestText($request, 'actor_country'))
            ->setEmail($this->requestText($request, 'actor_email'))
            ->setWebsite($this->requestText($request, 'actor_website'))
            ->setPhone($this->requestText($request, 'actor_phone'))
            ->setMobile($this->requestText($request, 'actor_mobile'))
            ->setNotes($this->requestText($request, 'actor_notes'));

        $assignment
            ->setRole($role)
            ->setCustomRole($role === ActorRole::OTHER ? $customRole : null)
            ->normalizeCustomRole();

        if ($actor->getType() !== Actor::TYPE_ORGANIZATION) {
            $assignment->setContactPerson(null);

            return true;
        }

        $contactChoice = (string) $request->request->get('contact_person_id', '__new__');

        if ($contactChoice === '') {
            $assignment->setContactPerson(null);

            return true;
        }

        if ($contactChoice !== '__new__') {
            $contactPersonId = $this->positiveInt($contactChoice);

            if ($contactPersonId === null) {
                return false;
            }

            $contactPerson = $this->entityManager
                ->getRepository(OrganizationContact::class)
                ->find($contactPersonId);

            if (!$contactPerson instanceof OrganizationContact || $contactPerson->getOrganization()->getId() !== $actor->getId()) {
                return false;
            }

            $assignment->setContactPerson($contactPerson);

            return true;
        }

        $contactName = mb_substr(trim((string) $request->request->get('contact_name', '')), 0, 255);

        if ($contactName === '') {
            $assignment->setContactPerson(null);

            return true;
        }

        $contactPerson = $assignment->getContactPerson();

        if (!$contactPerson instanceof OrganizationContact || $contactPerson->getOrganization()->getId() !== $actor->getId()) {
            $contactPerson = $this->organizationContactForName($actor, $contactName);
        }

        if (!$contactPerson instanceof OrganizationContact) {
            return false;
        }

        $contactPerson
            ->setName($contactName)
            ->setAlias($this->requestText($request, 'contact_alias'))
            ->setFunctionTitle($this->requestText($request, 'contact_function'))
            ->setEmail($this->requestText($request, 'contact_email'))
            ->setPhone($this->requestText($request, 'contact_phone'))
            ->setNotes($this->requestText($request, 'contact_notes'));

        $assignment->setContactPerson($contactPerson);

        return true;
    }

    /**
     * @return array{valid: bool, contact_person: ?OrganizationContact}
     */
    private function contactPersonAssignment(Actor $actor, Request $request): array
    {
        if ($actor->getType() !== Actor::TYPE_ORGANIZATION) {
            return ['valid' => true, 'contact_person' => null];
        }

        $contactChoice = (string) $request->request->get('contact_person_id', '');

        if ($contactChoice === '') {
            return ['valid' => true, 'contact_person' => null];
        }

        if ($contactChoice !== '__new__') {
            $contactPersonId = $this->positiveInt($contactChoice);

            if ($contactPersonId === null) {
                return ['valid' => false, 'contact_person' => null];
            }

            $contactPerson = $this->entityManager
                ->getRepository(OrganizationContact::class)
                ->find($contactPersonId);

            if ($contactPerson instanceof OrganizationContact && $contactPerson->getOrganization()->getId() === $actor->getId()) {
                return ['valid' => true, 'contact_person' => $contactPerson];
            }

            return ['valid' => false, 'contact_person' => null];
        }

        $contactName = mb_substr(trim((string) $request->request->get('contact_name', '')), 0, 255);
        $contactFunction = mb_substr(trim((string) $request->request->get('contact_function', '')), 0, 255);
        $contactEmail = mb_substr(trim((string) $request->request->get('contact_email', '')), 0, 180);
        $contactPhone = mb_substr(trim((string) $request->request->get('contact_phone', '')), 0, 100);

        if ($contactName === '') {
            return ['valid' => false, 'contact_person' => null];
        }

        $contactPerson = $this->organizationContactForName($actor, $contactName);

        if (!$contactPerson instanceof OrganizationContact) {
            return ['valid' => false, 'contact_person' => null];
        }

        $contactPerson
            ->setFunctionTitle($contactFunction !== '' ? $contactFunction : null)
            ->setEmail($contactEmail !== '' ? $contactEmail : null)
            ->setPhone($contactPhone !== '' ? $contactPhone : null);

        return ['valid' => true, 'contact_person' => $contactPerson];
    }

    /**
     * @return list<Actor>
     */
    private function actorOptions(): array
    {
        return $this->entityManager
            ->getRepository(Actor::class)
            ->createQueryBuilder('actor')
            ->orderBy('actor.alias', 'ASC')
            ->addOrderBy('actor.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<OrganizationContact>
     */
    private function contactOptions(): array
    {
        return $this->entityManager
            ->getRepository(OrganizationContact::class)
            ->createQueryBuilder('contact')
            ->addSelect('organization', 'person')
            ->join('contact.organization', 'organization')
            ->join('contact.person', 'person')
            ->orderBy('organization.alias', 'ASC')
            ->addOrderBy('organization.name', 'ASC')
            ->addOrderBy('person.alias', 'ASC')
            ->addOrderBy('person.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function organizationContactForName(Actor $organization, string $name): ?OrganizationContact
    {
        $person = $this->entityManager->getRepository(Actor::class)->findOneBy(['name' => $name]);

        if ($person instanceof Actor && $person->getType() !== Actor::TYPE_PERSON) {
            return null;
        }

        if (!$person instanceof Actor) {
            $person = new Actor($name, Actor::TYPE_PERSON);
            $this->entityManager->persist($person);
        }

        $contact = $person->getId() === null ? null : $this->entityManager
            ->getRepository(OrganizationContact::class)
            ->findOneBy([
                'organization' => $organization,
                'person' => $person,
            ]);

        if (!$contact instanceof OrganizationContact) {
            $contact = new OrganizationContact($organization, $person);
            $this->entityManager->persist($contact);
        }

        return $contact;
    }

    private function requestText(Request $request, string $name): ?string
    {
        $value = trim((string) $request->request->get($name, ''));

        return $value === '' ? null : $value;
    }

    private function requestDate(Request $request, string $name): ?\DateTimeImmutable
    {
        $value = trim((string) $request->request->get($name, ''));

        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date instanceof \DateTimeImmutable ? $date : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        $intValue = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($intValue) && $intValue > 0 ? $intValue : null;
    }

    private function hasCurrentVersion(Report $report, Request $request): bool
    {
        $submittedVersion = filter_var($request->request->get('version'), FILTER_VALIDATE_INT);

        return is_int($submittedVersion) && $submittedVersion === $report->getVersion();
    }

    private function assertWritableUser(): void
    {
        if ($this->isGranted(User::ROLE_READ_ONLY)) {
            throw $this->createAccessDeniedException('Read-only users cannot make changes.');
        }
    }

    private function assertReportEditable(Report $report): void
    {
        $this->assertWritableUser();

        if (!$report->isEditable()) {
            throw $this->createAccessDeniedException('A finalized or archived report cannot be changed.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function documentContext(
        Report $report,
        Request $request,
        ?string $mainImageSource,
        bool $embedImages = false,
    ): array
    {
        $definition = $this->formDefinition->forObject($report->getObjectRecord());
        $reportImages = $this->reportImages($report);
        $reportPhotos = $this->reportPhotosFrom($reportImages);
        $annotationImageSources = array_map(
            fn (ReportImage $image): ?string => $this->reportImageDocumentSource($image, $embedImages),
            $reportImages,
        );
        $reportImageSources = array_map(
            fn (ReportImage $image): ?string => $this->reportImageDocumentSource($image, $embedImages),
            $reportPhotos,
        );

        return [
            'report' => $report,
            'object' => $report->getObjectRecord(),
            'report_actors' => $this->reportActors($report),
            'report_sections' => $this->documentBuilder->sections($report, $definition),
            'reason_label' => $this->reportReasonLabel($report),
            'room_label' => $this->reportRoom($report),
            'author_name' => $this->authorProvider->nameFor($report->getCreatedById()),
            'finalizer_name' => $this->authorProvider->nameFor($report->getFinalizedById()),
            'thumbnail_source' => $mainImageSource,
            'back_url' => $this->backUrl($report, $request),
            'report_images' => $reportPhotos,
            'report_image_sources' => $reportImageSources,
            'report_documents' => $this->reportDocuments($report),
            'damage_annotations' => $this->damageAnnotationContext(
                $report,
                $mainImageSource,
                $reportImages,
                $annotationImageSources,
            ),
        ];
    }

    /**
     * @param list<ReportImage>  $reportImages
     * @param list<string|null> $reportImageSources
     *
     * @return array{legend: list<array<string, mixed>>, sources: list<array<string, mixed>>}
     */
    private function damageAnnotationContext(
        Report $report,
        ?string $mainImageSource,
        array $reportImages,
        array $reportImageSources,
    ): array {
        $damageCases = $this->entityManager->getRepository(DamageCase::class)->findBy(
            ['report' => $report, 'deleted' => false],
            ['sortOrder' => 'ASC', 'createdAt' => 'ASC', 'id' => 'ASC'],
        );
        $annotations = $this->entityManager->getRepository(Annotation::class)->findBy(
            ['report' => $report, 'deleted' => false],
            ['sortOrder' => 'ASC', 'createdAt' => 'ASC', 'id' => 'ASC'],
        );
        $imageSources = [];

        foreach ($reportImages as $index => $image) {
            if ($image->getId() !== null && is_string($reportImageSources[$index] ?? null)) {
                $imageSources[$image->getId()] = $reportImageSources[$index];
            }
        }

        $counts = [];
        $sources = [];

        foreach ($annotations as $annotation) {
            $damageCase = $annotation->getDamageCase();

            if ($damageCase->isDeleted()) {
                continue;
            }

            $selector = $annotation->getTarget()['selector'] ?? null;
            $dimensions = $this->annotationDimensions($annotation->getTarget());

            if (!is_array($selector) || $dimensions === null) {
                continue;
            }

            $caseId = $damageCase->getId();
            $counts[$caseId] = ($counts[$caseId] ?? 0) + 1;
            $sourceKey = $annotation->getSourceKey();
            $reportImage = $annotation->getReportImage();
            $imageSource = $reportImage instanceof ReportImage
                ? ($imageSources[$reportImage->getId()] ?? null)
                : (str_starts_with($sourceKey, 'main') ? $mainImageSource : null);

            if (!is_string($imageSource) || $imageSource === '') {
                continue;
            }

            if (!isset($sources[$sourceKey])) {
                $sources[$sourceKey] = [
                    'key' => $sourceKey,
                    'label' => $reportImage instanceof ReportImage
                        ? $this->reportImageLabel($reportImage)
                        : $this->translator->trans('reports.annotation_main_image'),
                    'imageSource' => $imageSource,
                    'width' => $dimensions['width'],
                    'height' => $dimensions['height'],
                    'widthPercent' => min(100.0, max(32.0, ($dimensions['width'] / $dimensions['height']) * 82.0)),
                    'annotations' => [],
                ];
            }

            $sources[$sourceKey]['annotations'][] = [
                'selector' => $selector,
                'color' => $damageCase->getColor(),
            ];
        }

        $legend = [];

        foreach ($damageCases as $damageCase) {
            $count = $counts[$damageCase->getId()] ?? 0;

            if ($count === 0) {
                continue;
            }

            $legend[] = [
                'label' => $damageCase->getLabel(),
                'color' => $damageCase->getColor(),
                'geometry' => $damageCase->getLegendGeometry(),
                'note' => $damageCase->getLegendNote(),
                'strokeWidth' => $damageCase->getStrokeWidth(),
                'legendStrokeWidth' => max(1.5, min(6.0, $damageCase->getStrokeWidth() * 1.35)),
                'count' => $count,
            ];
        }

        return [
            'legend' => $legend,
            'sources' => array_values($sources),
        ];
    }

    /** @return array{width: float, height: float}|null */
    private function annotationDimensions(array $target): ?array
    {
        $dimensions = $target['sourceDimensions'] ?? null;
        $width = is_array($dimensions) && is_numeric($dimensions['width'] ?? null)
            ? (float) $dimensions['width']
            : 0.0;
        $height = is_array($dimensions) && is_numeric($dimensions['height'] ?? null)
            ? (float) $dimensions['height']
            : 0.0;

        return $width > 0.0 && $height > 0.0 ? ['width' => $width, 'height' => $height] : null;
    }

    /** @return list<ReportImage> */
    private function reportImages(Report $report): array
    {
        return $this->entityManager->getRepository(ReportImage::class)->findBy(
            ['report' => $report],
            ['sortOrder' => 'ASC', 'createdAt' => 'ASC', 'id' => 'ASC'],
        );
    }

    /** @return list<ReportImage> */
    private function reportPhotos(Report $report): array
    {
        return $this->reportPhotosFrom($this->reportImages($report));
    }

    /**
     * @param list<ReportImage> $images
     *
     * @return list<ReportImage>
     */
    private function reportPhotosFrom(array $images): array
    {
        return array_values(array_filter(
            $images,
            static fn (ReportImage $image): bool => $image->getSource() !== ReportImage::SOURCE_SCHEMA,
        ));
    }

    /**
     * @param list<ReportImage> $images
     *
     * @return list<array{id: int|null, path: string, thumbnailPath: string|null, label: string}>
     */
    private function annotationImageViews(array $images): array
    {
        $ordered = [
            ...$this->reportPhotosFrom($images),
            ...array_values(array_filter(
                $images,
                static fn (ReportImage $image): bool => $image->getSource() === ReportImage::SOURCE_SCHEMA,
            )),
        ];

        return array_map(fn (ReportImage $image): array => [
            'id' => $image->getId(),
            'path' => $image->getPath(),
            'thumbnailPath' => $image->getThumbnailPath(),
            'label' => $this->reportImageLabel($image),
        ], $ordered);
    }

    /**
     * @param list<ReportImage> $images
     *
     * @return list<array{key: string, side: string, label: string, path: string, imageId: int|null}>
     */
    private function frameSchemaChoices(array $images): array
    {
        $existing = [];

        foreach ($images as $image) {
            if ($image->getSource() === ReportImage::SOURCE_SCHEMA) {
                $existing[$image->getPath()] = $image->getId();
            }
        }

        $choices = [];

        foreach ($this->frameSchemaCatalog->all() as $key => $schema) {
            $choices[] = [
                'key' => $key,
                'side' => $schema['side'],
                'label' => $this->translator->trans($schema['label']),
                'path' => $schema['path'],
                'imageId' => $existing[$schema['path']] ?? null,
            ];
        }

        return $choices;
    }

    private function reportImageLabel(ReportImage $image): string
    {
        $schema = $image->getSource() === ReportImage::SOURCE_SCHEMA
            ? $this->frameSchemaCatalog->findByPath($image->getPath())
            : null;

        if ($schema !== null) {
            return $this->translator->trans('reports.annotation_frame_label', [
                '%side%' => $this->translator->trans('reports.annotation_frame_' . $schema['side']),
                '%shape%' => $this->translator->trans($schema['label']),
            ]);
        }

        return $image->getOriginalName() ?: $this->translator->trans('reports.photo');
    }

    private function reportImageDocumentSource(ReportImage $image, bool $embed): ?string
    {
        $source = $image->getPath();

        if (!$embed) {
            return $source;
        }

        return $image->getSource() === ReportImage::SOURCE_SCHEMA
            ? $this->frameSchemaCatalog->dataUri($image->getPath())
            : $this->reportImageStorage->dataUri($source);
    }

    private function activeDamageCaseCount(Report $report): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(DISTINCT damageCase.id)')
            ->from(Annotation::class, 'annotation')
            ->innerJoin('annotation.damageCase', 'damageCase')
            ->andWhere('annotation.report = :report')
            ->andWhere('annotation.deleted = false')
            ->andWhere('damageCase.deleted = false')
            ->setParameter('report', $report)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function reportMainImageSource(Report $report, bool $embed): ?string
    {
        $object = $report->getObjectRecord();
        $imageUrl = $object->getImageUrl();

        if (is_string($imageUrl) && $imageUrl !== '') {
            $source = $this->outputImageSource($imageUrl, $embed);

            if ($source !== null) {
                return $source;
            }
        }

        $iiifInfoUrl = $object->getSourceData()['iiif_image_info_url'] ?? null;

        if (is_string($iiifInfoUrl) && trim($iiifInfoUrl) !== '') {
            $iiifImageUrl = rtrim((string) preg_replace('~/info\.json$~i', '', trim($iiifInfoUrl)), '/')
                . '/full/2000,/0/default.jpg';
            $source = $this->outputImageSource($iiifImageUrl, $embed);

            if ($source !== null) {
                return $source;
            }
        }

        $manifestUrl = $this->annotationManifestUrl($report);

        if ($manifestUrl !== null) {
            try {
                $source = $this->outputImageSource($this->imageStorage->resolveIiifImageUrl($manifestUrl), $embed);

                if ($source !== null) {
                    return $source;
                }
            } catch (FileException) {
                // Fall back to the available thumbnail when the remote IIIF source is unavailable.
            }
        }

        $thumbnailUrl = $this->thumbnailProvider->thumbnailForObject($object);

        return $thumbnailUrl === null ? null : $this->outputImageSource($thumbnailUrl, $embed);
    }

    private function outputImageSource(string $url, bool $embed): ?string
    {
        if (!$embed) {
            return $url;
        }

        $dataUri = $this->imageStorage->dataUri($url);

        if ($dataUri !== null) {
            return $dataUri;
        }

        try {
            return $this->imageStorage->externalDataUri($url);
        } catch (FileException) {
            return null;
        }
    }

    /** @return list<ReportDocument> */
    private function reportDocuments(Report $report): array
    {
        return $this->entityManager->getRepository(ReportDocument::class)->findBy(
            ['report' => $report],
            ['sortOrder' => 'ASC', 'createdAt' => 'ASC', 'id' => 'ASC'],
        );
    }

    private function nextReportImageSortOrder(Report $report): int
    {
        $images = $this->reportImages($report);

        return $images === [] ? 0 : max(array_map(static fn (ReportImage $image): int => $image->getSortOrder(), $images)) + 1;
    }

    /** @return array{Report, ReportImage} */
    private function reportImageForIds(int $reportId, int $imageId): array
    {
        $report = $this->reportForId($reportId);
        $image = $this->entityManager->getRepository(ReportImage::class)->findOneBy(['id' => $imageId, 'report' => $report]);

        if (!$image instanceof ReportImage) {
            throw $this->createNotFoundException();
        }

        return [$report, $image];
    }

    /** @return array{Report, ReportDocument} */
    private function reportDocumentForIds(int $reportId, int $documentId): array
    {
        $report = $this->reportForId($reportId);
        $document = $this->entityManager->getRepository(ReportDocument::class)->findOneBy(['id' => $documentId, 'report' => $report]);

        if (!$document instanceof ReportDocument) {
            throw $this->createNotFoundException();
        }

        return [$report, $document];
    }

    private function assertReportMediaRequest(Report $report, Request $request, string $tokenPrefix): void
    {
        $this->assertReportEditable($report);

        if (!$this->isCsrfTokenValid($tokenPrefix . $report->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    /** @param array<string, scalar> $parameters */
    private function redirectToReportTab(Report $report, Request $request, string $tab, array $parameters = []): Response
    {
        return $this->redirectToRoute('reports_edit', array_merge([
            '_locale' => $request->getLocale(),
            'id' => $report->getId(),
            'tab' => $tab,
        ], $parameters));
    }

    private function removeReportFiles(Report $report): void
    {
        foreach ($this->reportImages($report) as $image) {
            $this->reportImageStorage->remove($image->getPath());
            $this->reportImageStorage->remove($image->getThumbnailPath());
        }

        foreach ($this->reportDocuments($report) as $document) {
            $this->reportDocumentStorage->remove($document->getPath());
        }
    }

    private function reportReasonLabel(Report $report): ?string
    {
        if ($report->getReason() === 'other') {
            return $report->getCustomReason();
        }

        foreach ($this->formDefinition->reasonChoices() as $label => $value) {
            if ($value === $report->getReason()) {
                return $this->translator->trans($label);
            }
        }

        return null;
    }

    private function reportRoom(Report $report): ?string
    {
        if (!$report->getProject() instanceof Project) {
            return null;
        }

        return $this->entityManager->getRepository(ProjectObject::class)->findOneBy([
            'project' => $report->getProject(),
            'objectRecord' => $report->getObjectRecord(),
        ])?->getRoom();
    }

    private function redirectToReport(Report $report, Request $request): Response
    {
        return $this->redirectToRoute('reports_edit', [
            '_locale' => $request->getLocale(),
            'id' => $report->getId(),
        ]);
    }

    private function backUrl(Report $report, Request $request): string
    {
        $project = $report->getProject();

        if ($project instanceof Project) {
            return $this->generateUrl('projects_edit', [
                '_locale' => $request->getLocale(),
                'id' => $project->getId(),
            ]);
        }

        return $this->generateUrl('objects_edit', [
            '_locale' => $request->getLocale(),
            'id' => $report->getObjectRecord()->getId(),
        ]);
    }

    private function objectBelongsToProject(ObjectRecord $object): bool
    {
        return $this->entityManager
            ->getRepository(ProjectObject::class)
            ->count(['objectRecord' => $object]) > 0;
    }

    private function annotationManifestUrl(Report $report): ?string
    {
        if ($report->getId() !== null) {
            $reportLink = $this->entityManager
                ->getRepository(ReportManifest::class)
                ->findOneBy([
                    'report' => $report,
                    'role' => ReportManifest::ROLE_REFERENCE,
                ]);

            if ($reportLink instanceof ReportManifest) {
                return $this->manifestUrl($reportLink->getManifest());
            }
        }

        $objectLink = $this->entityManager
            ->getRepository(ObjectManifest::class)
            ->findOneBy([
                'objectRecord' => $report->getObjectRecord(),
                'role' => ObjectManifest::ROLE_SOURCE,
            ]);

        return $objectLink instanceof ObjectManifest ? $this->manifestUrl($objectLink->getManifest()) : null;
    }

    private function manifestUrl(IIIFManifest $manifest): ?string
    {
        $sourceUrl = $manifest->getSourceUrl();

        if ($sourceUrl !== null && trim($sourceUrl) !== '') {
            return $sourceUrl;
        }

        $manifestId = $manifest->getManifestId();

        return trim($manifestId) !== '' ? $manifestId : null;
    }
}
