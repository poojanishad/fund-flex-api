<?php

namespace App\Tests\Api;

use App\Entity\Account;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Integration tests for POST /api/transfer.
 *
 * These tests inject a fake auth token directly into Redis so they run
 * independently of the login endpoint.
 */
class TransferTest extends WebTestCase
{
    private const TEST_TOKEN = 'test-static-token-for-phpunit';

    /** Seed two accounts and return them. */
    private function seedAccounts(string $balanceA = '1000.00', string $balanceB = '1000.00'): array
    {
        $em = static::getContainer()->get('doctrine')->getManager();

        $a = (new Account())
            ->setEmail(uniqid() . '@test.com')
            ->setBalance($balanceA)
            ->setCreatedAt(new \DateTimeImmutable());

        $b = (new Account())
            ->setEmail(uniqid() . '@test.com')
            ->setBalance($balanceB)
            ->setCreatedAt(new \DateTimeImmutable());

        $em->persist($a);
        $em->persist($b);
        $em->flush();

        return [$a, $b];
    }

    /** Inject a token into Redis so the auth listener accepts it. */
    private function injectToken(): void
    {
        $redis = static::getContainer()->get(\Predis\Client::class);
        $redis->setex('auth_token:' . self::TEST_TOKEN, 3600, 'admin@example.com');
    }

    private function authHeaders(): array
    {
        return [
            'CONTENT_TYPE'    => 'application/json',
            'HTTP_Authorization' => 'Bearer ' . self::TEST_TOKEN,
        ];
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function testSuccessfulTransfer(): void
    {
        $client = static::createClient();
        $this->injectToken();
        [$a, $b] = $this->seedAccounts();

        $client->request('POST', '/api/transfer', [], [], array_merge($this->authHeaders(), [
            'HTTP_Idempotency-Key' => uniqid('key_'),
        ]), json_encode([
            'fromAccount' => $a->getId(),
            'toAccount'   => $b->getId(),
            'amount'      => '100.00',
            'referenceId' => uniqid('ref_'),
        ]));

        $this->assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertEquals('SUCCESS', $data['data']['status']);
    }

    public function testBalancesUpdatedCorrectly(): void
    {
        $client = static::createClient();
        $this->injectToken();
        [$a, $b] = $this->seedAccounts('500.00', '200.00');

        $client->request('POST', '/api/transfer', [], [], array_merge($this->authHeaders(), [
            'HTTP_Idempotency-Key' => uniqid('key_'),
        ]), json_encode([
            'fromAccount' => $a->getId(),
            'toAccount'   => $b->getId(),
            'amount'      => '150.00',
            'referenceId' => uniqid('ref_'),
        ]));

        $this->assertResponseIsSuccessful();

        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        $freshA = $em->find(Account::class, $a->getId());
        $freshB = $em->find(Account::class, $b->getId());

        $this->assertEquals('350.00', $freshA->getBalance());
        $this->assertEquals('350.00', $freshB->getBalance());
    }

    // -------------------------------------------------------------------------
    // Idempotency
    // -------------------------------------------------------------------------

    public function testSameIdempotencyKeyReturnsSameResponse(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->injectToken();
        [$a, $b] = $this->seedAccounts();

        $headers = array_merge($this->authHeaders(), [
            'HTTP_Idempotency-Key' => 'idem-key-stable-' . uniqid(),
        ]);

        $body = json_encode([
            'fromAccount' => $a->getId(),
            'toAccount'   => $b->getId(),
            'amount'      => '50.00',
            'referenceId' => uniqid('ref_'),
        ]);

        $client->request('POST', '/api/transfer', [], [], $headers, $body);
        $first = $client->getResponse()->getContent();

        $client->request('POST', '/api/transfer', [], [], $headers, $body);
        $second = $client->getResponse()->getContent();

        $this->assertResponseIsSuccessful();
        $this->assertEquals($first, $second);
    }

    // -------------------------------------------------------------------------
    // Validation errors
    // -------------------------------------------------------------------------

    public function testInsufficientBalance(): void
    {
        $client = static::createClient();
        $this->injectToken();
        [$a, $b] = $this->seedAccounts('10.00');

        $client->request('POST', '/api/transfer', [], [], array_merge($this->authHeaders(), [
            'HTTP_Idempotency-Key' => uniqid('key_'),
        ]), json_encode([
            'fromAccount' => $a->getId(),
            'toAccount'   => $b->getId(),
            'amount'      => '999.00',
            'referenceId' => uniqid('ref_'),
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Insufficient', $data['error']);
    }

    public function testSameAccountTransferRejected(): void
    {
        $client = static::createClient();
        $this->injectToken();
        [$a] = $this->seedAccounts();

        $client->request('POST', '/api/transfer', [], [], array_merge($this->authHeaders(), [
            'HTTP_Idempotency-Key' => uniqid('key_'),
        ]), json_encode([
            'fromAccount' => $a->getId(),
            'toAccount'   => $a->getId(),
            'amount'      => '10.00',
            'referenceId' => uniqid('ref_'),
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testInvalidJsonRejected(): void
    {
        $client = static::createClient();
        $this->injectToken();

        $client->request('POST', '/api/transfer', [], [], array_merge($this->authHeaders(), [
            'HTTP_Idempotency-Key' => uniqid('key_'),
        ]), '{not-valid-json}');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testMissingIdempotencyKeyRejected(): void
    {
        $client = static::createClient();
        $this->injectToken();
        [$a, $b] = $this->seedAccounts();

        $client->request('POST', '/api/transfer', [], [], $this->authHeaders(), json_encode([
            'fromAccount' => $a->getId(),
            'toAccount'   => $b->getId(),
            'amount'      => '10.00',
            'referenceId' => uniqid('ref_'),
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testAccountNotFoundReturns404(): void
    {
        $client = static::createClient();
        $this->injectToken();

        $client->request('POST', '/api/transfer', [], [], array_merge($this->authHeaders(), [
            'HTTP_Idempotency-Key' => uniqid('key_'),
        ]), json_encode([
            'fromAccount' => 9999999,
            'toAccount'   => 9999998,
            'amount'      => '10.00',
            'referenceId' => uniqid('ref_'),
        ]));

        $this->assertResponseStatusCodeSame(404);
    }

    // -------------------------------------------------------------------------
    // Auth guard
    // -------------------------------------------------------------------------

    public function testRequestWithoutTokenRejected(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/transfer', [], [], [
            'CONTENT_TYPE'        => 'application/json',
            'HTTP_Idempotency-Key' => uniqid('key_'),
        ], json_encode([
            'fromAccount' => 1,
            'toAccount'   => 2,
            'amount'      => '10.00',
            'referenceId' => uniqid('ref_'),
        ]));

        $this->assertResponseStatusCodeSame(401);
    }

    public function testRequestWithInvalidTokenRejected(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/transfer', [], [], [
            'CONTENT_TYPE'        => 'application/json',
            'HTTP_Authorization'  => 'Bearer invalid-token-xyz',
            'HTTP_Idempotency-Key' => uniqid('key_'),
        ], json_encode([
            'fromAccount' => 1,
            'toAccount'   => 2,
            'amount'      => '10.00',
            'referenceId' => uniqid('ref_'),
        ]));

        $this->assertResponseStatusCodeSame(401);
    }
}
