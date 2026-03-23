<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260323000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add username and password fields to account table for DB-based login';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD username VARCHAR(100) NOT NULL DEFAULT \'\' AFTER id');
        $this->addSql('ALTER TABLE account ADD password VARCHAR(255) NOT NULL DEFAULT \'\' AFTER username');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7D3656A4F85E0677 ON account (username)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_7D3656A4F85E0677 ON account');
        $this->addSql('ALTER TABLE account DROP username, DROP password');
    }
}
