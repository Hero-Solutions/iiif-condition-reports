<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\{ObjectLogEntry, ObjectRecord, User};
use Doctrine\ORM\EntityManagerInterface;

final class ObjectChangeLogger
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @return array<string, ?string> */
    public function snapshot(ObjectRecord $object, ?string $manifestUrl = null): array
    {
        $values = [
            'inventoryNumber' => $object->getInventoryNumber(),
            'title' => $object->getTitle(),
            'creator' => $object->getCreator(),
            'publisher' => $object->getPublisher(),
            'objectType' => $object->getObjectType(),
            'customObjectType' => $object->getCustomObjectType(),
            'currentLocation' => $object->getCurrentLocation(),
            'description' => $object->getDescription(),
            'imageUrl' => $object->getImageUrl(),
            'thumbnailUrl' => $object->getThumbnailUrl(),
            'externalImageUrl' => $object->getExternalImageUrl(),
            'source' => $object->getSource(),
            'externalId' => $object->getExternalId(),
        ];

        if ($manifestUrl !== null) {
            $values['iiifManifestUrl'] = $manifestUrl;
        }

        foreach ($object->getSourceData() as $key => $value) {
            // Internal harvest timestamps are events, not changed object values.
            if (!str_starts_with((string) $key, '_')) {
                $values['datahub.' . $key] = is_array($value)
                    ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                    : (string) $value;
            }
        }

        return array_map(static fn (?string $value): ?string => $value === '' ? null : $value, $values);
    }

    /** @param array<string, ?string> $before */
    public function record(
        ObjectRecord $object,
        array $before,
        string $type,
        ?User $user = null,
        ?string $manifestUrl = null,
        ?\DateTimeImmutable $createdAt = null,
    ): void {
        $after = $this->snapshot($object, $manifestUrl);
        $changes = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;

            if ($old !== $new) {
                $changes[$field] = ['old' => $old, 'new' => $new];
            }
        }

        if ($changes === []) {
            return;
        }

        // Flushed in the same transaction as the object; a failed save leaves no log entry.
        $this->entityManager->persist(new ObjectLogEntry($object, $type, $changes, $user, $createdAt));
    }
}
