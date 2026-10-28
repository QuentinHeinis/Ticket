<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261028175530 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_user (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, name VARCHAR(100) NOT NULL, password VARCHAR(255) NOT NULL, roles CLOB NOT NULL, available BOOLEAN NOT NULL, capacity INTEGER NOT NULL, last_assigned_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_88BDF3E9E7927C74 ON app_user (email)');
        $this->addSql('CREATE TABLE ticket (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(255) NOT NULL, description CLOB NOT NULL, priority VARCHAR(255) NOT NULL, status VARCHAR(255) DEFAULT \'new\' NOT NULL, created_at DATETIME NOT NULL, priority_changed_at DATETIME NOT NULL, assignee_id INTEGER DEFAULT NULL, CONSTRAINT FK_97A0ADA359EC7D60 FOREIGN KEY (assignee_id) REFERENCES app_user (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_ticket_status_assignee ON ticket (status, assignee_id)');
        $this->addSql('CREATE INDEX idx_ticket_status_priority_created ON ticket (status, priority, created_at)');
        $this->addSql('CREATE INDEX idx_ticket_created_at ON ticket (created_at)');
        $this->addSql('CREATE INDEX IDX_97A0ADA359EC7D60 ON ticket (assignee_id)');
        $this->addSql('CREATE TABLE ticket_history (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, type VARCHAR(255) NOT NULL, from_value VARCHAR(255) DEFAULT NULL, to_value VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, ticket_id INTEGER NOT NULL, actor_id INTEGER DEFAULT NULL, CONSTRAINT FK_2B762919700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B76291910DAF24A FOREIGN KEY (actor_id) REFERENCES app_user (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_history_ticket_created ON ticket_history (ticket_id, created_at)');
        $this->addSql('CREATE INDEX IDX_2B762919700047D2 ON ticket_history (ticket_id)');
        $this->addSql('CREATE INDEX IDX_2B76291910DAF24A ON ticket_history (actor_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE ticket');
        $this->addSql('DROP TABLE ticket_history');
    }
}
