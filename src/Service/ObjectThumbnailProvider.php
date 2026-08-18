<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ObjectManifest;
use App\Entity\ObjectRecord;
use Doctrine\ORM\EntityManagerInterface;

final class ObjectThumbnailProvider
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<ObjectRecord> $objects
     * @return array<int, string>
     */
    public function thumbnailsForObjects(array $objects): array
    {
        $objects = array_values(array_filter($objects, static fn (ObjectRecord $object): bool => $object->getId() !== null));

        if ($objects === []) {
            return [];
        }

        $thumbnails = [];
        $links = $this->entityManager
            ->getRepository(ObjectManifest::class)
            ->createQueryBuilder('link')
            ->addSelect('objectRecord', 'manifest')
            ->join('link.objectRecord', 'objectRecord')
            ->join('link.manifest', 'manifest')
            ->andWhere('link.objectRecord IN (:objects)')
            ->andWhere('link.role = :role')
            ->setParameter('objects', $objects)
            ->setParameter('role', ObjectManifest::ROLE_SOURCE)
            ->getQuery()
            ->getResult();

        foreach ($links as $link) {
            $objectId = $link->getObjectRecord()->getId();
            $thumbnailUrl = $link->getManifest()->getThumbnailUrl();

            if ($objectId !== null && $thumbnailUrl !== null) {
                $thumbnails[$objectId] = $thumbnailUrl;
            }
        }

        foreach ($objects as $object) {
            $objectId = $object->getId();

            if ($objectId !== null && !isset($thumbnails[$objectId])) {
                $thumbnailUrl = $object->getSourceData()['thumbnail'] ?? null;

                if (is_string($thumbnailUrl) && trim($thumbnailUrl) !== '') {
                    $thumbnails[$objectId] = trim($thumbnailUrl);
                }
            }
        }

        return $thumbnails;
    }

    public function thumbnailForObject(ObjectRecord $object): ?string
    {
        $thumbnails = $this->thumbnailsForObjects([$object]);

        return $object->getId() === null ? null : ($thumbnails[$object->getId()] ?? null);
    }
}
