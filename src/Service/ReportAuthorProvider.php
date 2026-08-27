<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Report;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class ReportAuthorProvider
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<Report> $reports
     * @return array<int, string>
     */
    public function namesFor(array $reports): array
    {
        $usersById = $this->namesForUserIds(array_map(
            static fn (Report $report): ?int => $report->getCreatedById(),
            $reports,
        ));

        $names = [];

        foreach ($reports as $report) {
            $reportId = $report->getId();
            $userId = $report->getCreatedById();

            if ($reportId !== null && $userId !== null && isset($usersById[$userId])) {
                $names[$reportId] = $usersById[$userId];
            }
        }

        return $names;
    }

    public function nameFor(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        return $this->namesForUserIds([$userId])[$userId] ?? null;
    }

    /**
     * @param list<int|null> $userIds
     *
     * @return array<int, string>
     */
    public function namesForUserIds(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds, is_int(...))));

        if ($userIds === []) {
            return [];
        }

        $users = $this->entityManager
            ->getRepository(User::class)
            ->createQueryBuilder('user')
            ->andWhere('user.id IN (:ids)')
            ->setParameter('ids', $userIds)
            ->getQuery()
            ->getResult();

        $names = [];

        foreach ($users as $user) {
            $names[$user->getId()] = $user->getFullName() !== '' ? $user->getFullName() : $user->getEmail();
        }

        return $names;
    }
}
