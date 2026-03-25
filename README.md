# Fund Transfer API

A secure, production-ready REST API for transferring funds between accounts, built with **PHP 8.3 + Symfony 7.4 + MySQL 8 + Redis 7**.

---

## Architecture Overview

```
POST /api/login      →  AuthController                          → Redis (token store, 1h TTL)
POST /api/transfer   →  ApiAuthListener → TransferController   → TransferService
                                                                   ├─ MySQL  (ACID transaction)
                                                                   ├─ Redis  (idempotency cache)
                                                                   └─ AuditLog (DB table)
GET  /health         →  HealthController → MySQL + Redis ping
```

**Key design decisions:**

| Concern | Approach |
|---------|----------|
| Auth | Stateless Bearer token (64-byte hex, Redis-backed, 1 h TTL) |
| Transaction integrity | DB transaction + `SELECT … FOR UPDATE` with sorted lock order (prevents deadlocks) |
| Idempotency | Two-layer: Redis (fast path) → MySQL `idempotency_key` (survives Redis restarts) |
| Balance arithmetic | `bcmath` — zero floating-point rounding errors |
| Rate limiting | Redis sliding counter: 10 req / IP / 60 s |
| Audit trail | Every success and failure written to `audit_log` table |
| Deadlock prevention | Lock IDs sorted ascending before `FOR UPDATE` |

---

## Quick Start — Docker (recommended)

### Prerequisites
- Docker ≥ 24 and Docker Compose ≥ 2

### 1. Clone

```bash
git clone <your-repo-url> fund-transfer-api
cd fund-transfer-api
```

### 2. Set your admin password

```bash
# Generate a bcrypt hash
php bin/console app:hash-password your_password
# or without PHP locally:
php -r "echo password_hash('your_password', PASSWORD_BCRYPT) . PHP_EOL;"
```

Open `docker-compose.yml` and replace the `API_USER_PASSWORD_HASH` value with the output.  
The default hash corresponds to the password **`password`** — change it before any real use.

### 3. Start all services

```bash
docker compose up -d --build
```

MySQL needs ~15 s to initialise on first boot. Watch it with:

```bash
docker compose logs -f db
```

### 4. Run database migrations

```bash
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
```

### 5. (Optional) Load sample accounts

```bash
docker compose exec app php bin/console doctrine:fixtures:load --no-interaction
# Creates: user1@test.com ($1,000), user2@test.com ($1,000), user3@test.com ($5,000)
```

The API is now live at **`http://localhost:8000`**.

---

## Manual / Local Setup

### Prerequisites
- PHP ≥ 8.3 with extensions: `pdo_mysql`, `bcmath`, `zip`, `intl`
- Composer 2
- MySQL 8.0
- Redis 7

### 1. Install dependencies

```bash
composer install
```

### 2. Configure environment

```bash
cp .env .env.local
```

Edit `.env.local` with your local values:

```dotenv
DATABASE_URL="mysql://root:@127.0.0.1:3306/fund_transfer?serverVersion=8.0&charset=utf8mb4"
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
API_USER_EMAIL=admin@example.com
API_USER_PASSWORD_HASH=<output of app:hash-password or password_hash()>
```

### 3. Create the database and run migrations

```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate --no-interaction
```

### 4. (Optional) Load sample accounts

```bash
php bin/console doctrine:fixtures:load --no-interaction
```

### 5. Start the development server

```bash
php -S localhost:8000 -t public/
```

---

## API Reference

### `POST /api/login`

Exchange credentials for a Bearer token. 

**Request body**
```json
{
    "login": "user1@test.com",
    "password": "alpha123"
}
```

**200 OK**
```json
{
    "token": "ab97c5d4cfd8dd5dcc69188aa9b8ac9d63d3a5cab97b5b0d40841c715895f4a9",
    "expires_in": 3600,
    "account_id": 1
}
```

| Status | Reason |
|--------|--------|
| 400 | Missing `email` or `password` |
| 401 | Wrong credentials |

---

### `POST /api/transfer`

Transfer funds between two accounts.

**Required headers**
```
Authorization:   Bearer <token>
Idempotency-Key: <unique string — use UUID or similar>
Content-Type:    application/json
```

**Request body**
```json
{
  "fromAccount": 1,
  "toAccount":   2,
  "amount":      150.50,
  "referenceId": "order-789"
}
```

**200 OK**
```json
{
  "success": true,
  "data": {
    "status":      "SUCCESS",
    "referenceId": "order-789"
  }
}
```

**Error responses**

| Status | Reason |
|--------|--------|
| 400 | Insufficient balance / same account / missing field / duplicate `referenceId` |
| 401 | Missing or invalid Bearer token |
| 404 | `fromAccount` or `toAccount` not found |
| 429 | Rate limit exceeded (10 req / IP / min) |

> **Idempotency:** Sending the same `Idempotency-Key` twice always returns the identical response. Safe to retry on network failure.

---

### `GET /health`

No auth required. Returns infrastructure status.

```json
{
  "status":   "OK",
  "database": "UP",
  "redis":    "UP"
}
```

`status` is `DEGRADED` if either dependency is unavailable.

---

## Useful Console Commands

```bash
# Generate a bcrypt password hash for .env
php bin/console app:hash-password my_secret_password

# Re-run migrations
php bin/console doctrine:migrations:migrate

# Check current migration status
php bin/console doctrine:migrations:status

# Reload sample fixture accounts
php bin/console doctrine:fixtures:load --no-interaction

# Clear application cache
php bin/console cache:clear
```

---

## Running Tests

Tests require MySQL and Redis. The test suite uses a separate `_test` database (auto-created by Symfony's test environment via `dbname_suffix`).

```bash
# With Docker
docker compose exec app php bin/phpunit --testdox

# Locally
php bin/phpunit --testdox
```

### Test coverage

**`tests/Api/AuthTest.php`**

| Test | Verifies |
|------|----------|
| `testLoginSuccess` | Token returned on valid credentials |
| `testLoginMissingFields` | 400 on incomplete body |
| `testLoginWrongPassword` | 401 on bad credentials |

**`tests/Api/TransferTest.php`**

| Test | Verifies |
|------|----------|
| `testSuccessfulTransfer` | Happy path — 200 + correct response shape |
| `testBalancesUpdatedCorrectly` | Exact debit/credit amounts in DB |
| `testSameIdempotencyKeyReturnsSameResponse` | Duplicate request returns identical body |
| `testInsufficientBalance` | 400 with descriptive error |
| `testSameAccountTransferRejected` | 400 — business rule guard |
| `testInvalidJsonRejected` | 400 on malformed body |
| `testMissingIdempotencyKeyRejected` | 400 on missing header |
| `testAccountNotFoundReturns404` | 404 for non-existent account IDs |
| `testRequestWithoutTokenRejected` | 401 — auth listener blocks unauthenticated |
| `testRequestWithInvalidTokenRejected` | 401 — expired/invalid token rejected |

---

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_ENV` | `dev` | `dev` / `test` / `prod` |
| `APP_SECRET` | — | Random 32-char string (required) |
| `DATABASE_URL` | — | Doctrine DBAL connection string |
| `REDIS_HOST` | `127.0.0.1` | Redis hostname |
| `REDIS_PORT` | `6379` | Redis port |
| `API_USER_EMAIL` | — | Login email for the API user |
| `API_USER_PASSWORD_HASH` | — | bcrypt hash — generate with `app:hash-password` |

---

## What I Would Add in a Full Production System

- **`GET /api/transfer/{referenceId}`** — poll transaction status by reference
- **`GET /api/accounts/{id}/balance`** — balance lookup endpoint
- **Distributed lock** (`SET NX` in Redis) as a guard layer before the DB row lock

