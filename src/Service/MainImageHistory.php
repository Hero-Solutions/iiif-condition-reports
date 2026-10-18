<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\{Annotation, ObjectImageVersion, ObjectManifest, ObjectRecord, Report, ReportMainImage};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class MainImageHistory
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ObjectImageStorage $storage,
    ) {
    }

    public function currentSource(ObjectRecord $object, ?ObjectManifest $link = null): array
    {
        if ($link === null && $object->getId() !== null) {
            $link = $this->entityManager->getRepository(ObjectManifest::class)->findOneBy([
                'objectRecord' => $object, 'role' => ObjectManifest::ROLE_SOURCE,
            ]);
        }

        return [
            'manifestUrl' => $link?->getManifest()->getSourceUrl() ?: ($link?->getManifest()->getManifestId() ?? ''),
            'infoUrl' => trim((string) ($object->getSourceData()['iiif_image_info_url'] ?? '')),
            'imageUrl' => $object->getImageUrl() ?? '',
            'thumbnailUrl' => $object->getThumbnailUrl() ?? $link?->getManifest()->getThumbnailUrl() ?? '',
        ];
    }

    public function fingerprint(array $source): string
    {
        return hash('sha256', json_encode([$source['manifestUrl'], $source['infoUrl'], $source['imageUrl']], JSON_THROW_ON_ERROR));
    }

    public function forReport(Report $report): array
    {
        $link = $this->linkFor($report);
        if ($link !== null) {
            $version = $link->getImageVersion();
            return [
                'manifestUrl' => '', 'infoUrl' => '',
                'imageUrl' => $version->getImages()[0]['image_url'],
                'thumbnailUrl' => $version->getImages()[0]['thumbnail_url'],
                'fingerprint' => $version->getFingerprint(),
                'images' => $version->getImages(),
            ];
        }

        $source = $this->currentSource($report->getObjectRecord());
        return $source + ['fingerprint' => $this->fingerprint($source), 'images' => []];
    }

    public function thumbnailsForReports(array $reports, array $objectThumbnails): array
    {
        $thumbnails = [];
        foreach ($reports as $report) {
            $thumbnails[$report->getId()] = $objectThumbnails[$report->getObjectRecord()->getId()] ?? null;
        }
        if ($reports === []) return $thumbnails;
        $links = $this->entityManager->getRepository(ReportMainImage::class)->createQueryBuilder('link')
            ->addSelect('version')->innerJoin('link.imageVersion', 'version')
            ->where('link.report IN (:reports)')->setParameter('reports', $reports)->getQuery()->getResult();
        foreach ($links as $link) {
            $thumbnails[$link->getReport()->getId()] = $link->getImageVersion()->getImages()[0]['thumbnail_url'];
        }
        return $thumbnails;
    }

    /** Called before saving a main-image annotation. Never accept a drawing from a stale page. */
    public function preserveForAnnotation(Report $report, string $fingerprint, string $sourceKey): void
    {
        $source = $this->forReport($report);
        if (!hash_equals($source['fingerprint'], $fingerprint)) {
            throw new ConflictHttpException('The main image has changed. Reload the report before drawing.');
        }

        $link = $this->linkFor($report);
        $version = $link?->getImageVersion() ?? $this->versionFor($report->getObjectRecord(), $source);
        if (!in_array($sourceKey, array_column($version->getImages(), 'key'), true)) {
            throw new ConflictHttpException('The IIIF image list has changed. Reload the report before drawing.');
        }
        if ($link === null) $this->entityManager->persist(new ReportMainImage($report, $version));
    }

    /** Run before replacing source data, so uncaptured annotations still refer to the old source. */
    public function beforeSync(ObjectRecord $object, ?ObjectManifest $oldLink, array $incoming, \DateTimeImmutable $date): void
    {
        if ($object->getId() === null) return;
        $old = $this->currentSource($object, $oldLink);
        $next = [
            'manifestUrl' => trim((string) ($incoming['iiif_manifest_url'] ?? '')),
            'infoUrl' => trim((string) ($incoming['iiif_image_info_url'] ?? '')),
            'imageUrl' => '', 'thumbnailUrl' => '',
        ];
        if (($next['manifestUrl'] === '' && $next['infoUrl'] === '')
            || $this->fingerprint($old) === $this->fingerprint($next)) return;

        $query = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT report')->from(Report::class, 'report')
            ->innerJoin(Annotation::class, 'annotation', 'WITH', 'annotation.report = report')
            ->innerJoin('annotation.damageCase', 'damageCase')
            ->where('report.objectRecord = :object')->setParameter('object', $object)
            ->andWhere('annotation.reportImage IS NULL AND (annotation.sourceKey = :main OR annotation.sourceKey LIKE :canvas)')
            ->setParameter('main', 'main')->setParameter('canvas', 'main:%')
            ->andWhere('annotation.deleted = false AND damageCase.deleted = false')
            ->getQuery();
        // A locking read sees drawings committed while this sync waited for the object lock.
        if ($this->entityManager->getConnection()->isTransactionActive()) $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        $reports = $query->getResult();
        $activeIds = [];
        $version = null;
        foreach ($reports as $report) {
            $activeIds[$report->getId()] = true;
            $link = $this->linkFor($report);
            if ($link === null) {
                $version ??= $this->versionFor($object, $old);
                $link = new ReportMainImage($report, $version);
                $this->entityManager->persist($link);
            }
            $link->getImageVersion()->markReplaced($date);
        }

        // An undone/deleted drawing should not keep a report on an outdated photograph.
        $links = $this->entityManager->getRepository(ReportMainImage::class)->createQueryBuilder('link')
            ->innerJoin('link.report', 'report')->where('report.objectRecord = :object')
            ->setParameter('object', $object)->getQuery()->getResult();
        foreach ($links as $link) {
            if (!isset($activeIds[$link->getReport()->getId()])) {
                $this->entityManager->remove($link);
            }
        }
    }

    /** @return list<ObjectImageVersion> */
    public function previousImages(ObjectRecord $object): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('DISTINCT version')->from(ObjectImageVersion::class, 'version')
            ->innerJoin(ReportMainImage::class, 'link', 'WITH', 'link.imageVersion = version')
            ->where('version.objectRecord = :object AND version.replacedAt IS NOT NULL')
            ->setParameter('object', $object)->orderBy('version.replacedAt', 'DESC')->addOrderBy('version.id', 'DESC')
            ->getQuery()->getResult();
    }

    private function linkFor(Report $report): ?ReportMainImage
    {
        if ($report->getId() === null) return null;
        $query = $this->entityManager->getRepository(ReportMainImage::class)->createQueryBuilder('link')
            ->where('link.report = :report')->setParameter('report', $report)->getQuery();
        if ($this->entityManager->getConnection()->isTransactionActive()) $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        return $query->getOneOrNullResult();
    }

    private function versionFor(ObjectRecord $object, array $source): ObjectImageVersion
    {
        $fingerprint = $this->fingerprint($source);
        $version = $this->entityManager->getRepository(ObjectImageVersion::class)->findOneBy([
            'objectRecord' => $object, 'fingerprint' => $fingerprint, 'replacedAt' => null,
        ]);
        if ($version === null) {
            $images = $this->storage->archiveMainImages($source);
            $version = new ObjectImageVersion($object, $fingerprint, $source, $images);
            $this->entityManager->persist($version);
        }
        return $version;
    }
}
