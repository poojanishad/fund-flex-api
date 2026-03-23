<?php

namespace App\Repository;

use App\Entity\Account;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    public function findByUsername(string $username): ?Account
    {
        return $this->findOneBy(['username' => $username]);
    }

    public function findByUsernameOrEmail(string $identifier): ?Account
    {
        return $this->createQueryBuilder('a')
            ->where('a.username = :val OR a.email = :val')
            ->setParameter('val', $identifier)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
