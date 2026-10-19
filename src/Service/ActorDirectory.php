<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Actor;
use App\Value\Pagination;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/** Groups the administration list without changing actor identities or assignments. */
final class ActorDirectory
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function page(string $q = '', int $page = 1): array
    {
        $q = trim($q);
        $query = $this->entityManager->getRepository(Actor::class)->createQueryBuilder('actor')
            ->addSelect('COALESCE(actor.alias, actor.name) AS HIDDEN sortName')
            ->orderBy('sortName', 'ASC')
            ->addOrderBy('actor.id', 'ASC');
        // Stream identities to count/filter whole groups, retaining only IDs and match flags.
        $rows = (clone $query)->select('actor.id, actor.name, actor.alias, actor.tmsKey, actor.tmsData')
            ->addSelect('COALESCE(actor.alias, actor.name) AS HIDDEN sortName')->getQuery()->toIterable([], Query::HYDRATE_ARRAY);
        $index = [];
        foreach ($rows as $actor) {
            $sourceId = $actor['tmsData']['record']['ConstituentID'] ?? null;
            $constituentId = $actor['tmsKey'] !== null && is_string($sourceId)
                && preg_match('/^[0-9]{1,18}$/D', $sourceId) && trim($sourceId, '0') !== ''
                ? ltrim($sourceId, '0') : null;
            $key = $constituentId !== null ? 'tms:' . $constituentId : 'actor:' . $actor['id'];
            $index[$key] ??= ['ids' => [], 'constituentId' => $constituentId, 'matches' => false];
            $index[$key]['ids'][] = $actor['id'];
            if ($q === '' || mb_stripos($actor['name'], $q) !== false || mb_stripos($actor['alias'] ?? '', $q) !== false) {
                $index[$key]['matches'] = true;
            }
        }
        $index = array_filter($index, static fn (array $group): bool => $group['matches']);
        $pagination = new Pagination(count($index), $page);
        $index = array_slice($index, $pagination->offset, Pagination::PER_PAGE, true);
        $groups = $actorKeys = [];
        foreach ($index as $key => $group) {
            $groups[$key] = ['actor' => null, 'variants' => []];
            foreach ($group['ids'] as $id) $actorKeys[$id] = $key;
        }
        if ($actorKeys === []) return ['groups' => [], 'pagination' => $pagination];

        // Fetch display fields only for this page, including every variant of a matching actor.
        $rows = $query->select('actor.id, actor.name, actor.alias, actor.type, actor.address, actor.postal, actor.city,
                actor.stateProvince, actor.country, actor.email, actor.phone')
            ->addSelect('COALESCE(actor.alias, actor.name) AS HIDDEN sortName')
            ->andWhere('actor.id IN (:ids)')->setParameter('ids', array_keys($actorKeys))
            ->getQuery()->getArrayResult();
        foreach ($rows as $actor) {
            $key = $actorKeys[$actor['id']];
            $actor['displayName'] = $actor['alias'] ?? $actor['name'];
            $actor['constituentId'] = $index[$key]['constituentId'];
            $groups[$key]['actor'] ??= $actor;
            $groups[$key]['variants'][] = $actor;
        }
        // An administrator may have deleted a group between the identity and display queries.
        return ['groups' => array_values(array_filter($groups, static fn (array $group): bool => $group['actor'] !== null)),
            'pagination' => $pagination];
    }
}
