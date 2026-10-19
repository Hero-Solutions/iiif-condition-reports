<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\{Annotation, ObjectImageVersion, ObjectManifest, ObjectRecord, Report, ReportMainImage};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class MainImageHistory
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
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
            $source = $version->getSource();
            $images = $version->getImages();
            if ($images !== []) {
                $source = [
                    'manifestUrl' => '', 'infoUrl' => $images[0]['info_url'] ?? '',
                    'imageUrl' => $images[0]['image_url'],
                    'thumbnailUrl' => $images[0]['thumbnail_url'],
                ];
            }
            return $source + ['fingerprint' => $version->getFingerprint(), 'images' => $images];
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
            $version = $link->getImageVersion();
            $thumbnails[$link->getReport()->getId()] = $version->getImages()[0]['thumbnail_url']
                ?? ($version->getSource()['thumbnailUrl'] ?: null);
        }
        return $thumbnails;
    }

    /** Called before saving a main-image annotation. Never accept a drawing from a stale page. */
    public function preserveForAnnotation(Report $report, string $fingerprint, string $sourceKey, array $references = []): void
    {
        $source = $this->forReport($report);
        if (!hash_equals($source['fingerprint'], $fingerprint)) {
            throw new ConflictHttpException('The main image has changed. Reload the report before drawing.');
        }

        $link = $this->linkFor($report);
        $images = $this->referenceImages($references);
        $version = $link?->getImageVersion() ?? $this->versionFor($report->getObjectRecord(), $source, $images);
        if ($version->getImages() === [] && $images !== []) $version->setImages($images);
        if ($version->getImages() !== [] && !in_array($sourceKey, array_column($version->getImages(), 'key'), true)) {
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

    private function versionFor(ObjectRecord $object, array $source, array $images = []): ObjectImageVersion
    {
        $source = array_intersect_key($source, array_flip(['manifestUrl', 'infoUrl', 'imageUrl', 'thumbnailUrl']));
        $fingerprint = $this->fingerprint($source);
        $version = $this->entityManager->getRepository(ObjectImageVersion::class)->findOneBy([
            'objectRecord' => $object, 'fingerprint' => $fingerprint, 'replacedAt' => null,
        ]);
        if ($version === null) {
            // Store references only. Saving a drawing or syncing an object must
            // never fetch IIIF metadata, download pixels or write image files.
            $version = new ObjectImageVersion($object, $fingerprint, $source, $images);
            $this->entityManager->persist($version);
        }
        return $version;
    }

    /** @param list<array{key: string, url: string}> $references */
    private function referenceImages(array $references): array
    {
        if (count($references) > 256) throw new BadRequestHttpException('Too many main image references.');
        $images = [];
        foreach ($references as $reference) {
            $key = is_array($reference) ? ($reference['key'] ?? null) : null;
            $url = is_array($reference) ? ($reference['url'] ?? null) : null;
            if (!is_string($key) || !preg_match('/^main(?::[1-9][0-9]*)?$/D', $key)
                || isset($images[$key]) || !is_string($url) || strlen($url) > 8192) {
                throw new BadRequestHttpException('Invalid main image reference.');
            }
            $parts = parse_url($url);
            $remote = filter_var($url, FILTER_VALIDATE_URL) && is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment']);
            $upload = preg_match('~^/uploads/objects/[a-zA-Z0-9_-]+\.(?:jpg|jpeg|png|webp)$~D', $url);
            if (!$remote && !$upload) throw new BadRequestHttpException('Invalid main image URL.');

            $info = $remote && str_ends_with($parts['path'] ?? '', '/info.json');
            $imageUrl = $url;
            $thumbnailUrl = $url;
            if ($info) {
                [$base] = explode('?', $url, 2);
                $base = substr($base, 0, -strlen('/info.json'));
                $query = isset($parts['query']) ? '?' . $parts['query'] : '';
                $imageUrl = $base . '/full/2000,/0/default.jpg' . $query;
                $thumbnailUrl = $base . '/full/!480,480/0/default.jpg' . $query;
            }
            $images[$key] = ['key' => $key, 'info_url' => $info ? $url : '',
                'image_url' => $imageUrl, 'thumbnail_url' => $thumbnailUrl];
        }
        return array_values($images);
    }
}
