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
}