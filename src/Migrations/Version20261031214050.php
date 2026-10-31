<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261031214050 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_ticket_status_created_id ON ticket (status, created_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_ticket_status_created_id');
    }
}
