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
            ['user1', 'user1@test.com', 'alpha123',  '1000.00'],
            ['user2', 'user2@test.com', 'beta456',   '1000.00'],
            ['user3', 'user3@test.com', 'gamma789',  '5000.00'],
        ];

        foreach ($accounts as [$username, $email, $password, $balance]) {
            $account = new Account();
            $account->setUsername($username);
            $account->setPassword(password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]));
            $account->setEmail($email);
            $account->setBalance($balance);
            $account->setCreatedAt(new \DateTimeImmutable());

            $manager->persist($account);
        }

        $manager->flush();
    }
}