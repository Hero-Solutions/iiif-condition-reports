<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Annotation;
use App\Entity\DamageCase;
use App\Entity\Report;
use App\Entity\ReportImage;
use App\Entity\User;
use App\Service\ReportAuthorProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ReportAnnotationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ReportAuthorProvider $authorProvider,
    ) {
    }

    #[Route('/{_locale<nl|en>}/reports/{id}/annotations', name: 'report_annotations_state', methods: ['GET'])]
    public function state(Report $report): JsonResponse
    {
        $damageCases = $this->entityManager->getRepository(DamageCase::class)->findBy(
            ['report' => $report, 'deleted' => false],
            ['sortOrder' => 'ASC', 'createdAt' => 'ASC', 'id' => 'ASC'],
        );
        $annotations = $this->entityManager->getRepository(Annotation::class)->findBy(
            ['report' => $report],
            ['createdAt' => 'ASC', 'id' => 'ASC'],
        );
        $authorNames = $this->authorProvider->namesForUserIds(array_map(
            static fn (Annotation $annotation): ?int => $annotation->getCreatedById(),
            $annotations,
        ));

        return $this->json([
            'damageCases' => array_map($this->damageCaseData(...), $damageCases),
            'annotations' => array_values(array_map(
                fn (Annotation $annotation): array => $this->annotationData($annotation, $authorNames),
                array_filter($annotations, static fn (Annotation $annotation): bool => !$annotation->isDeleted()),
            )),
            'history' => array_map(
                fn (Annotation $annotation): array => $this->historyData($annotation, $authorNames),
                array_reverse($annotations),
            ),
        ]);
    }

    #[Route('/{_locale<nl|en>}/reports/{id}/damage-cases', name: 'report_damage_cases_create', methods: ['POST'])]
    public function createDamageCase(Report $report, Request $request): JsonResponse
    {
        $this->assertWritableRequest($report, $request);
        $payload = $this->jsonPayload($request);
        $clientId = mb_substr(trim((string) ($payload['clientId'] ?? '')), 0, 255);
        $label = mb_substr(trim((string) ($payload['label'] ?? '')), 0, 255);

        if ($clientId === '' || $label === '') {
            throw new BadRequestHttpException('Client ID and label are required.');
        }

        $repository = $this->entityManager->getRepository(DamageCase::class);
        $damageCase = $repository->findOneBy(['report' => $report, 'clientId' => $clientId]);

        if (!$damageCase instanceof DamageCase) {
            $damageCase = $repository->findOneBy(['report' => $report, 'label' => $label, 'deleted' => false]);
        }

        $created = false;

        if (!$damageCase instanceof DamageCase) {
            $created = true;
            $damageCase = new DamageCase(
                $report,
                $clientId,
                $label,
                (string) ($payload['color'] ?? ''),
            );
            $damageCase
                ->setCreatedById($this->currentUserId())
                ->setStrokeWidth((float) ($payload['strokeWidth'] ?? 2.2))
                ->setSortOrder(count($repository->findBy(['report' => $report, 'deleted' => false])));
            $this->entityManager->persist($damageCase);
        } else {
            $damageCase->restore();
        }

        $this->entityManager->flush();

        return $this->json($this->damageCaseData($damageCase), $created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    #[Route('/{_locale<nl|en>}/reports/{reportId}/damage-cases/{caseId}', name: 'report_damage_cases_update', methods: ['PATCH'])]
    public function updateDamageCase(int $reportId, int $caseId, Request $request): JsonResponse
    {
        [$report, $damageCase] = $this->damageCaseForIds($reportId, $caseId);
        $this->assertWritableRequest($report, $request);
        $payload = $this->jsonPayload($request);

        if (array_key_exists('label', $payload)) {
            $label = mb_substr(trim((string) $payload['label']), 0, 255);

            if ($label === '') {
                throw new BadRequestHttpException('Label is required.');
            }

            $damageCase->setLabel($label);
        }

        if (array_key_exists('color', $payload)) {
            $damageCase->setColor((string) $payload['color']);
        }

        if (array_key_exists('legendGeometry', $payload)) {
            $damageCase->setLegendGeometry($this->legendGeometry($payload['legendGeometry']));
        }

        if (array_key_exists('legendNote', $payload)) {
            $damageCase->setLegendNote(mb_substr(trim((string) $payload['legendNote']), 0, 2000));
        }

        if (array_key_exists('strokeWidth', $payload)) {
            $damageCase->setStrokeWidth((float) $payload['strokeWidth']);
        }

        if (array_key_exists('sortOrder', $payload)) {
            $damageCase->setSortOrder((int) $payload['sortOrder']);
        }

        $this->entityManager->flush();

        return $this->json($this->damageCaseData($damageCase));
    }

    #[Route('/{_locale<nl|en>}/reports/{reportId}/damage-cases/{caseId}', name: 'report_damage_cases_delete', methods: ['DELETE'])]
    public function deleteDamageCase(int $reportId, int $caseId, Request $request): JsonResponse
    {
        [$report, $damageCase] = $this->damageCaseForIds($reportId, $caseId);
        $this->assertWritableRequest($report, $request);
        $damageCase->delete();

        foreach ($this->entityManager->getRepository(Annotation::class)->findBy(['damageCase' => $damageCase]) as $annotation) {
            $annotation->delete();
        }

        $this->entityManager->flush();

        return $this->json(['deleted' => true]);
    }

    #[Route('/{_locale<nl|en>}/reports/{id}/annotations', name: 'report_annotations_save', methods: ['POST'])]
    public function saveAnnotation(Report $report, Request $request): JsonResponse
    {
        $this->assertWritableRequest($report, $request);
        $payload = $this->jsonPayload($request);
        $annotationPayload = is_array($payload['annotation'] ?? null) ? $payload['annotation'] : [];
        $clientId = mb_substr(trim((string) ($annotationPayload['id'] ?? '')), 0, 255);
        $sourceKey = mb_substr(trim((string) ($payload['sourceKey'] ?? '')), 0, 255);
        $damageCase = $this->entityManager->getRepository(DamageCase::class)->findOneBy([
            'id' => (int) ($payload['damageCaseId'] ?? 0),
            'report' => $report,
            'deleted' => false,
        ]);

        if ($clientId === '' || $sourceKey === '' || !$damageCase instanceof DamageCase) {
            throw new BadRequestHttpException('Invalid annotation data.');
        }

        $target = is_array($annotationPayload['target'] ?? null) ? $annotationPayload['target'] : [];
        $bodies = is_array($annotationPayload['bodies'] ?? null) ? array_values($annotationPayload['bodies']) : [];

        if (!is_array($target['selector'] ?? null)) {
            throw new BadRequestHttpException('Annotation selector is required.');
        }

        $reportImage = null;
        $reportImageId = (int) ($payload['reportImageId'] ?? 0);

        if ($reportImageId > 0) {
            $reportImage = $this->entityManager->getRepository(ReportImage::class)->findOneBy([
                'id' => $reportImageId,
                'report' => $report,
            ]);

            if (!$reportImage instanceof ReportImage) {
                throw new BadRequestHttpException('Invalid report image.');
            }
        }

        $repository = $this->entityManager->getRepository(Annotation::class);
        $annotation = $repository->findOneBy(['report' => $report, 'clientId' => $clientId]);
        $created = false;

        if (!$annotation instanceof Annotation) {
            $created = true;
            $annotation = new Annotation($report, $damageCase, $sourceKey, $clientId);
            $annotation
                ->setCreatedById($this->currentUserId())
                ->setSortOrder(count($repository->findBy(['report' => $report])));
            $this->entityManager->persist($annotation);
        }

        $target['annotation'] = $clientId;
        $annotation
            ->setDamageCase($damageCase)
            ->setReportImage($reportImage)
            ->setSourceKey($sourceKey)
            ->setTarget($target)
            ->setBodies($bodies)
            ->restore();

        $this->entityManager->flush();

        return $this->json($this->annotationData($annotation), $created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    #[Route('/{_locale<nl|en>}/reports/{reportId}/annotations/{annotationId}', name: 'report_annotations_delete', methods: ['DELETE'])]
    public function deleteAnnotation(int $reportId, int $annotationId, Request $request): JsonResponse
    {
        $report = $this->reportForId($reportId);
        $this->assertWritableRequest($report, $request);
        $annotation = $this->entityManager->getRepository(Annotation::class)->findOneBy([
            'id' => $annotationId,
            'report' => $report,
        ]);

        if (!$annotation instanceof Annotation) {
            throw $this->createNotFoundException();
        }

        $annotation->delete();
        $this->entityManager->flush();

        return $this->json(['deleted' => true]);
    }

    /** @return array{Report, DamageCase} */
    private function damageCaseForIds(int $reportId, int $caseId): array
    {
        $report = $this->reportForId($reportId);
        $damageCase = $this->entityManager->getRepository(DamageCase::class)->findOneBy([
            'id' => $caseId,
            'report' => $report,
            'deleted' => false,
        ]);

        if (!$damageCase instanceof DamageCase) {
            throw $this->createNotFoundException();
        }

        return [$report, $damageCase];
    }

    private function reportForId(int $id): Report
    {
        $report = $this->entityManager->getRepository(Report::class)->find($id);

        if (!$report instanceof Report) {
            throw $this->createNotFoundException();
        }

        return $report;
    }

    private function assertWritableRequest(Report $report, Request $request): void
    {
        if (!$report->isEditable() || $this->isGranted(User::ROLE_READ_ONLY)) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('report_edit_' . $report->getId(), $request->headers->get('X-CSRF-TOKEN', ''))) {
            throw $this->createAccessDeniedException();
        }
    }

    /** @return array<string, mixed> */
    private function jsonPayload(Request $request): array
    {
        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new BadRequestHttpException('Invalid JSON.', $exception);
        }

        if (!is_array($payload)) {
            throw new BadRequestHttpException('Invalid JSON payload.');
        }

        return $payload;
    }

    /** @return list<list<array{0: float, 1: float}>> */
    private function legendGeometry(mixed $geometry): array
    {
        if (!is_array($geometry)) {
            return [];
        }

        if (isset($geometry[0][0]) && is_numeric($geometry[0][0])) {
            $geometry = [$geometry];
        }

        $strokes = [];

        foreach (array_slice($geometry, 0, 32) as $stroke) {
            if (!is_array($stroke)) {
                continue;
            }

            $points = [];

            foreach (array_slice($stroke, 0, 256) as $point) {
                if (!is_array($point) || !is_numeric($point[0] ?? null) || !is_numeric($point[1] ?? null)) {
                    continue;
                }

                $points[] = [
                    max(0.0, min(100.0, (float) $point[0])),
                    max(0.0, min(100.0, (float) $point[1])),
                ];
            }

            if ($points !== []) {
                $strokes[] = $points;
            }
        }

        return $strokes;
    }

    /** @return array<string, mixed> */
    private function damageCaseData(DamageCase $damageCase): array
    {
        return [
            'id' => $damageCase->getId(),
            'clientId' => $damageCase->getClientId(),
            'label' => $damageCase->getLabel(),
            'color' => $damageCase->getColor(),
            'legendGeometry' => $damageCase->getLegendGeometry(),
            'legendNote' => $damageCase->getLegendNote(),
            'strokeWidth' => $damageCase->getStrokeWidth(),
            'sortOrder' => $damageCase->getSortOrder(),
        ];
    }

    /** @return array<string, mixed> */
    private function annotationData(Annotation $annotation, ?array $authorNames = null): array
    {
        $target = $annotation->getTarget();
        $target['annotation'] = $annotation->getClientId();

        return [
            'id' => $annotation->getId(),
            'clientId' => $annotation->getClientId(),
            'damageCaseId' => $annotation->getDamageCase()->getId(),
            'sourceKey' => $annotation->getSourceKey(),
            'reportImageId' => $annotation->getReportImage()?->getId(),
            'deleted' => $annotation->isDeleted(),
            'createdAt' => $annotation->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $annotation->getUpdatedAt()->format(DATE_ATOM),
            'createdBy' => $authorNames === null
                ? $this->authorProvider->nameFor($annotation->getCreatedById())
                : ($authorNames[$annotation->getCreatedById()] ?? null),
            'annotation' => [
                'id' => $annotation->getClientId(),
                'bodies' => $annotation->getBodies(),
                'target' => $target,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function historyData(Annotation $annotation, ?array $authorNames = null): array
    {
        return [
            'id' => $annotation->getId(),
            'damageCase' => $annotation->getDamageCase()->getLabel(),
            'deleted' => $annotation->isDeleted(),
            'createdAt' => $annotation->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $annotation->getUpdatedAt()->format(DATE_ATOM),
            'createdBy' => $authorNames === null
                ? $this->authorProvider->nameFor($annotation->getCreatedById())
                : ($authorNames[$annotation->getCreatedById()] ?? null),
        ];
    }

    private function currentUserId(): ?int
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getId() : null;
    }
}
