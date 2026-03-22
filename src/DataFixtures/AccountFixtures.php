<?php

namespace App\DataFixtures;

use App\Entity\Account;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AccountFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $accounts = [
            ['user1@test.com', '1000.00'],
            ['user2@test.com', '1000.00'],
            ['user3@test.com', '5000.00'],
        ];

        foreach ($accounts as [$email, $balance]) {
            $account = new Account();
            $account->setEmail($email);
            $account->setBalance($balance);
            $account->setCreatedAt(new \DateTimeImmutable());

            $manager->persist($account);
        }

        $manager->flush();
    }
}