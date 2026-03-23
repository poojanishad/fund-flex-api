<?php

namespace App\Tests\Api;

use App\Entity\Account;
use App\Entity\Transaction;
use App\Repository\AccountRepository;
use App\Tests\Stub\FakeRedisClient;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for the Fund Transfer API.
 *
 * No live MySQL or Redis instance is required:
 *  - Redis  → replaced by FakeRedisClient (in-memory, registered in services.yaml when@test)
 *  - MySQL  → EntityManager mocked via static::getContainer()->set() before each request
 */
class TransferApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private FakeRedisClient $redis;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        /** @var FakeRedisClient $redis */
        $redis = static::getContainer()->get(FakeRedisClient::class);
        $redis->reset();
        $this->redis = $redis;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function authToken(int $accountId = 1): string
    {
        $token = 'test_token_' . $accountId . '_' . uniqid();
        $this->redis->setex("auth_token:{$token}", 3600, (string) $accountId);
        return $token;
    }

    private function makeAccount(int $id, string $balance = '1000.00', string $rawPassword = 'secret'): Account
    {
        $account = new Account();
        $account->setUsername("user{$id}");
        $account->setEmail("user{$id}@test.com");
        $account->setPassword(password_hash($rawPassword, PASSWORD_BCRYPT, ['cost' => 4]));
        $account->setBalance($balance);
        $account->setCreatedAt(new \DateTimeImmutable());

        $ref = new \ReflectionProperty(Account::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($account, $id);

        return $account;
    }

    private function mockEm(?\Closure $findFn = null, array $repoMap = []): EntityManagerInterface
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('executeQuery')->willReturn($this->createMock(Result::class));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $em->method('persist');
        $em->method('flush');

        if ($findFn) {
            $em->method('find')->willReturnCallback($findFn);
        }

        $em->method('getRepository')->willReturnCallback(function (string $class) use ($repoMap) {
            $r = $this->createMock(\Doctrine\ORM\EntityRepository::class);
            $r->method('findOneBy')->willReturn($repoMap[$class] ?? null);
            return $r;
        });

        return $em;
    }

    // ═════════════════════════════════════════════════════════════════════
    // GET /health
    // ═════════════════════════════════════════════════════════════════════

    public function testHealthReturnsCorrectShape(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('executeQuery')->willReturn($this->createMock(Result::class));
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        static::getContainer()->set(EntityManagerInterface::class, $em);

        $this->client->request('GET', '/health');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('status',   $data);
        $this->assertArrayHasKey('database', $data);
        $this->assertArrayHasKey('redis',    $data);
        $this->assertSame('UP', $data['redis']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // POST /api/login
    // ═════════════════════════════════════════════════════════════════════

    public function testLoginReturns400WhenFieldsMissing(): void
    {
        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['login' => 'user1']));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testLoginReturns401ForUnknownUser(): void
    {
        $repo = $this->createMock(AccountRepository::class);
        $repo->method('findByUsernameOrEmail')->willReturn(null);
        static::getContainer()->set(AccountRepository::class, $repo);

        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['login' => 'nobody', 'password' => 'x']));

        $this->assertResponseStatusCodeSame(401);
        $this->assertSame('Invalid credentials', json_decode($this->client->getResponse()->getContent(), true)['error']);
    }

    public function testLoginReturns401ForWrongPassword(): void
    {
        $account = $this->makeAccount(1, '1000.00', 'correct');
        $repo = $this->createMock(AccountRepository::class);
        $repo->method('findByUsernameOrEmail')->willReturn($account);
        static::getContainer()->set(AccountRepository::class, $repo);

        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['login' => 'user1', 'password' => 'WRONG']));

        $this->assertResponseStatusCodeSame(401);
    }

    public function testLoginSuccessReturnsTokenAndStoresItInRedis(): void
    {
        $account = $this->makeAccount(1, '1000.00', 'mypassword');
        $repo = $this->createMock(AccountRepository::class);
        $repo->method('findByUsernameOrEmail')->willReturn($account);
        static::getContainer()->set(AccountRepository::class, $repo);

        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['login' => 'user1', 'password' => 'mypassword']));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('token',      $data);
        $this->assertArrayHasKey('expires_in', $data);
        $this->assertSame(3600, $data['expires_in']);
        $this->assertSame('1', $this->redis->get("auth_token:{$data['token']}"));
    }

    // ═════════════════════════════════════════════════════════════════════
    // POST /api/transfer — auth guard
    // ═════════════════════════════════════════════════════════════════════

    public function testTransferReturns401WithNoAuthHeader(): void
    {
        $this->client->request('POST', '/api/transfer', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        $this->assertResponseStatusCodeSame(401);
        $this->assertSame('Authorization token required',
            json_decode($this->client->getResponse()->getContent(), true)['error']);
    }

    public function testTransferReturns401WithInvalidToken(): void
    {
        $this->client->request('POST', '/api/transfer', [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer bad_token'], '{}');

        $this->assertResponseStatusCodeSame(401);
        $this->assertSame('Invalid or expired token',
            json_decode($this->client->getResponse()->getContent(), true)['error']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // POST /api/transfer — request validation
    // ═════════════════════════════════════════════════════════════════════

    public function testTransferReturns400ForInvalidJson(): void
    {
        $token = $this->authToken(1);

        $this->client->request('POST', '/api/transfer', [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$token}", 'HTTP_IDEMPOTENCY_KEY' => 'k1'],
            'not-valid-json');

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('Invalid JSON', json_decode($this->client->getResponse()->getContent(), true)['error']);
    }

    public function testTransferReturns400WhenFieldsMissing(): void
    {
        $token = $this->authToken(1);

        $this->client->request('POST', '/api/transfer', [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$token}", 'HTTP_IDEMPOTENCY_KEY' => 'k1'],
            json_encode(['fromAccount' => 1]));

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('Invalid request', json_decode($this->client->getResponse()->getContent(), true)['error']);
    }

    public function testTransferReturns400WhenIdempotencyKeyMissing(): void
    {
        $token = $this->authToken(1);

        $this->client->request('POST', '/api/transfer', [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$token}"],
            json_encode(['fromAccount' => 1, 'toAccount' => 2, 'amount' => '10.00', 'referenceId' => 'r1']));

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('Idempotency-Key missing', json_decode($this->client->getResponse()->getContent(), true)['error']);
    }

    public function testTransferReturns403WhenFromAccountMismatch(): void
    {
        $token = $this->authToken(99);

        $this->client->request('POST', '/api/transfer', [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$token}", 'HTTP_IDEMPOTENCY_KEY' => 'k1'],
            json_encode(['fromAccount' => 1, 'toAccount' => 2, 'amount' => '10.00', 'referenceId' => 'r1']));

        $this->assertResponseStatusCodeSame(403);
        $this->assertSame('You can only transfer from your own account',
            json_decode($this->client->getResponse()->getContent(), true)['error']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // POST /api/transfer — business logic
    // ═════════════════════════════════════════════════════════════════════

    public function testSuccessfulTransfer(): void
    {
        $token = $this->authToken(1);
        $from  = $this->makeAccount(1, '1000.00');
        $to    = $this->makeAccount(2, '500.00');

        static::getContainer()->set(EntityManagerInterface::class,
            $this->mockEm(
                fn(string $c, int $id) => match($id) { 1 => $from, 2 => $to, default => null }
            )
        );

        $this->client->request('POST', '/api/transfer', [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$token}", 'HTTP_IDEMPOTENCY_KEY' => 'idem-ok'],
            json_encode(['fromAccount' => 1, 'toAccount' => 2, 'amount' => '100.00', 'referenceId' => 'ref-ok']));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('SUCCESS',  $data['data']['status']);
        $this->assertSame('ref-ok', $data['data']['referenceId']);
    }

    public function testIdempotentRequestReturnsCachedResult(): void
    {
        $token    = $this->authToken(1);
        $idempKey = 'idem-cached-001';

        $cached = json_encode(['success' => true, 'data' => ['status' => 'SUCCESS', 'referenceId' => 'ref-c']]);
        $this->redis->setex("txn:{$idempKey}", 3600, $cached);

        static::getContainer()->set(EntityManagerInterface::class, $this->mockEm());

        $this->client->request('POST', '/api/transfer', [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$token}", 'HTTP_IDEMPOTENCY_KEY' => $idempKey],
            json_encode(['fromAccount' => 1, 'toAccount' => 2, 'amount' => '50.00', 'referenceId' => 'ref-c']));

        $this->assertResponseIsSuccessful();
        $this->assertTrue(json_decode($this->client->getResponse()->getContent(), true)['success']);
    }

    public function testTransferReturns400ForInsufficientBalance(): void
    {
        $token = $this->authToken(1);
        $from  = $this->makeAccount(1, '10.00');
        $to    = $this->makeAccount(2, '500.00');

        static::getContainer()->set(EntityManagerInterface::class,
            $this->mockEm(fn(string $c, int $id) => match($id) { 1 => $from, 2 => $to, default => null }));

        $this->client->request('POST', '/api/transfer', [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$token}", 'HTTP_IDEMPOTENCY_KEY' => 'idem-insuf'],
            json_encode(['fromAccount' => 1, 'toAccount' => 2, 'amount' => '500.00', 'referenceId' => 'ref-insuf']));

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('Insufficient balance', json_decode($this->client->getResponse()->getContent(), true)['error']);
    }

    public function testTransferReturns400ForSameSourceAndDestination(): void
    {
        $token   = $this->authToken(1);
        $account = $this->makeAccount(1, '1000.00');

        static::getContainer()->set(EntityManagerInterface::class,
            $this->mockEm(fn() => $account));

        $this->client->request('POST', '/api/transfer', [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$token}", 'HTTP_IDEMPOTENCY_KEY' => 'idem-same'],
            json_encode(['fromAccount' => 1, 'toAccount' => 1, 'amount' => '50.00', 'referenceId' => 'ref-same']));

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('Cannot transfer to the same account',
            json_decode($this->client->getResponse()->getContent(), true)['error']);
    }

    public function testTransferReturns404WhenAccountDoesNotExist(): void
    {
        $token = $this->authToken(1);

        static::getContainer()->set(EntityManagerInterface::class,
            $this->mockEm(fn() => null));

        $this->client->request('POST', '/api/transfer', [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$token}", 'HTTP_IDEMPOTENCY_KEY' => 'idem-nf'],
            json_encode(['fromAccount' => 1, 'toAccount' => 99999, 'amount' => '50.00', 'referenceId' => 'ref-nf']));

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame('Account not found', json_decode($this->client->getResponse()->getContent(), true)['error']);
    }

    public function testTransferReturns400ForDuplicateReferenceId(): void
    {
        $token = $this->authToken(1);

        static::getContainer()->set(EntityManagerInterface::class,
            $this->mockEm(null, [Transaction::class => new Transaction()]));

        $this->client->request('POST', '/api/transfer', [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$token}", 'HTTP_IDEMPOTENCY_KEY' => 'idem-dup'],
            json_encode(['fromAccount' => 1, 'toAccount' => 2, 'amount' => '50.00', 'referenceId' => 'ref-dup']));

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('Duplicate referenceId', json_decode($this->client->getResponse()->getContent(), true)['error']);
    }

    public function testTransferReturns429WhenRateLimitExceeded(): void
    {
        $token = $this->authToken(1);

        // Pre-fill counter well above the test-env limit of 1 000
        $this->redis->set('rate_limit:127.0.0.1', '9999');

        static::getContainer()->set(EntityManagerInterface::class, $this->mockEm());

        $this->client->request('POST', '/api/transfer', [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$token}", 'HTTP_IDEMPOTENCY_KEY' => 'idem-rate'],
            json_encode(['fromAccount' => 1, 'toAccount' => 2, 'amount' => '10.00', 'referenceId' => 'ref-rate']));

        $this->assertResponseStatusCodeSame(429);
    }
}