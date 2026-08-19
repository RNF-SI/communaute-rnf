<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #34 — notifications shown on the platform, and the queue the daily
 * summary is built from.
 */
final class Version20260819075543 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the notifications table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE communaute_rnf_notifications (id INT AUTO_INCREMENT NOT NULL, recipient_id INT NOT NULL, author_id INT DEFAULT NULL, usergroup_id INT DEFAULT NULL, type VARCHAR(50) NOT NULL, title VARCHAR(255) NOT NULL, url VARCHAR(255) NOT NULL, by_email TINYINT(1) NOT NULL, created_at DATETIME NOT NULL, read_at DATETIME DEFAULT NULL, emailed_at DATETIME DEFAULT NULL, INDEX IDX_49F73183E92F8F78 (recipient_id), INDEX IDX_49F73183F675F31B (author_id), INDEX IDX_49F73183D2112630 (usergroup_id), INDEX recipient_read (recipient_id, read_at), INDEX pending_email (by_email, emailed_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE communaute_rnf_notifications ADD CONSTRAINT FK_49F73183E92F8F78 FOREIGN KEY (recipient_id) REFERENCES communaute_rnf_users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE communaute_rnf_notifications ADD CONSTRAINT FK_49F73183F675F31B FOREIGN KEY (author_id) REFERENCES communaute_rnf_users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE communaute_rnf_notifications ADD CONSTRAINT FK_49F73183D2112630 FOREIGN KEY (usergroup_id) REFERENCES communaute_rnf_usergroups (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE communaute_rnf_notifications');
    }
}
