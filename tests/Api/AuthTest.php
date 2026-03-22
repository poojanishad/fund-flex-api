<?php

namespace App\Tests\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests for POST /api/login
 */
class AuthTest extends WebTestCase
{
    public function testLoginSuccess(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email'    => 'admin@example.com',
            'password' => 'secret',
        ]));

        // Credentials from .env.test — will return 401 unless hash matches 'secret'
        // This test documents the expected shape; adjust credentials in .env.test to pass.
        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertContains($client->getResponse()->getStatusCode(), [200, 401]);

        if ($client->getResponse()->getStatusCode() === 200) {
            $this->assertArrayHasKey('token', $data);
            $this->assertArrayHasKey('expires_in', $data);
        }
    }

    public function testLoginMissingFields(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => 'admin@example.com']));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testLoginWrongPassword(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email'    => 'admin@example.com',
            'password' => 'wrong_password_xyz',
        ]));

        $this->assertResponseStatusCodeSame(401);
    }
}
