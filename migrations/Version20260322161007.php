<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260322161007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE account (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, balance NUMERIC(15, 2) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_7D3656A4E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE audit_log (id INT AUTO_INCREMENT NOT NULL, event_type VARCHAR(50) NOT NULL, payload JSON NOT NULL, created_at DATETIME NOT NULL, INDEX idx_event (event_type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE idempotency_key (id INT AUTO_INCREMENT NOT NULL, idempotency_key VARCHAR(100) NOT NULL, response JSON NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_7FD1C1477FD1C147 (idempotency_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE transactions (id INT AUTO_INCREMENT NOT NULL, from_before_balance NUMERIC(15, 2) NOT NULL, from_after_balance NUMERIC(15, 2) NOT NULL, to_before_balance NUMERIC(15, 2) NOT NULL, to_after_balance NUMERIC(15, 2) NOT NULL, amount NUMERIC(15, 2) NOT NULL, status VARCHAR(20) NOT NULL, reference_id VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, from_account_id INT NOT NULL, to_account_id INT NOT NULL, UNIQUE INDEX UNIQ_EAA81A4C1645DEA9 (reference_id), INDEX IDX_EAA81A4CB0CF99BD (from_account_id), INDEX IDX_EAA81A4CBC58BDC7 (to_account_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_EAA81A4CB0CF99BD FOREIGN KEY (from_account_id) REFERENCES account (id)');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_EAA81A4CBC58BDC7 FOREIGN KEY (to_account_id) REFERENCES account (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE transactions DROP FOREIGN KEY FK_EAA81A4CB0CF99BD');
        $this->addSql('ALTER TABLE transactions DROP FOREIGN KEY FK_EAA81A4CBC58BDC7');
        $this->addSql('DROP TABLE account');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE idempotency_key');
        $this->addSql('DROP TABLE transactions');
    }
}
