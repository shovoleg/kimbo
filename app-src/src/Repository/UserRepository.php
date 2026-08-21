<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class UserRepository extends ServiceEntityRepository
{
    private const SORTABLE = [
        'name'      => 'u.name',
        'email'     => 'u.email',
        'status'    => 'u.status',
        'lastSeen'  => 'u.lastSeenAt',
        'createdAt' => 'u.createdAt',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findAllSorted(string $sort = 'lastSeen', string $dir = 'desc'): array
    {
        $field = self::SORTABLE[$sort] ?? self::SORTABLE['lastSeen'];
        $dir   = 'asc' === strtolower($dir) ? 'ASC' : 'DESC';

        $qb = $this->createQueryBuilder('u')->orderBy($field, $dir);

        if ('u.lastSeenAt' === $field) {
            $qb->addOrderBy('u.createdAt', $dir);
        }

        return $qb->getQuery()->getResult();
    }

    public function updateStatusByIds(array $ids, string $status): int
    {
        if ([] === $ids) {
            return 0;
        }

        return (int) $this->createQueryBuilder('u')
            ->update()
            ->set('u.status', ':status')
            ->where('u.id IN (:ids)')
            ->setParameter('status', $status)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }

    public function deleteByIds(array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }

        return (int) $this->createQueryBuilder('u')
            ->delete()
            ->where('u.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }

    public function deleteUnverified(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->delete()
            ->where('u.status = :status')
            ->setParameter('status', User::STATUS_UNVERIFIED)
            ->getQuery()
            ->execute();
    }

    public function countUnverified(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.status = :status')
            ->setParameter('status', User::STATUS_UNVERIFIED)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
