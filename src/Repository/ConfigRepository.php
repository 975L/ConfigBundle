<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Repository;

use c975L\ConfigBundle\Entity\Config;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Config::class);
    }

    // Returns configs flagged with a severity whose value is still empty, i.e. requiring admin attention
    public function findRequiringAttention(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.severity IS NOT NULL')
            ->andWhere("c.value IS NULL OR c.value = ''")
            ->orderBy('c.label', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // Returns every config belonging to the given group (e.g. Config::GROUP_THEME), sorted by label
    public function findByGroup(string $group): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.group = :group')
            ->setParameter('group', $group)
            ->orderBy('c.label', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // Config count per group, respecting the same "sensitive"/"restricted" visibility rules as ConfigCrudController's own index query - backs its intermediate "pick a group" screen. Reads live DISTINCT group values rather than the fixed Config::GROUPS enum, so a group only present in data (e.g. a bundle's configs.json using a value not yet added to that enum) still shows up
    public function countsByGroup(bool $isSensitive, bool $includeRestricted): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c.group AS grp, COUNT(c.id) AS itemCount')
            ->andWhere('c.group IS NOT NULL')
            ->andWhere('c.isSensitive = :isSensitive')
            ->setParameter('isSensitive', $isSensitive)
            ->groupBy('c.group')
            ->orderBy('c.group', 'ASC')
        ;

        if (!$includeRestricted) {
            $qb->andWhere('c.isRestricted IS NULL OR c.isRestricted = :isRestricted')
                ->setParameter('isRestricted', false);
        }

        $rows = $qb->getQuery()->getResult();

        return array_combine(array_column($rows, 'grp'), array_column($rows, 'itemCount'));
    }
}