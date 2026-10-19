<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Actor;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/** Groups the administration list without changing actor identities or assignments. */
final class ActorDirectory
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function groups(): array
    {
        // Stream lightweight rows so large TMS payloads do not stay in Doctrine's identity map.
        $rows = $this->entityManager->getRepository(Actor::class)->createQueryBuilder('actor')
            ->select('actor.id, actor.name, actor.alias, actor.type, actor.address, actor.postal, actor.city,
                actor.stateProvince, actor.country, actor.email, actor.phone, actor.tmsKey, actor.tmsData')
            ->addSelect('COALESCE(actor.alias, actor.name) AS HIDDEN sortName')
            ->orderBy('sortName', 'ASC')
            ->addOrderBy('actor.id', 'ASC')
            ->getQuery()->toIterable([], Query::HYDRATE_ARRAY);
        $groups = [];
        foreach ($rows as $actor) {
            $sourceId = $actor['tmsData']['record']['ConstituentID'] ?? null;
            $constituentId = $actor['tmsKey'] !== null && is_string($sourceId)
                && preg_match('/^[0-9]{1,18}$/D', $sourceId) && trim($sourceId, '0') !== ''
                ? ltrim($sourceId, '0') : null;
            $key = $constituentId !== null ? 'tms:' . $constituentId : 'actor:' . $actor['id'];
            $actor['displayName'] = $actor['alias'] ?? $actor['name'];
            $actor['constituentId'] = $constituentId;
            unset($actor['tmsKey'], $actor['tmsData']);
            $groups[$key] ??= ['actor' => $actor, 'variants' => []];
            $groups[$key]['variants'][] = $actor;
        }
        return array_values($groups);
    }
}
