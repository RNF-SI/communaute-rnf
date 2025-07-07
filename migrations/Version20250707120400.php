<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove site_id column and foreign key constraint from users table
 */
final class Version20250707120400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove site_id column and foreign key constraint from users table';
    }

    public function up(Schema $schema): void
    {
        // Drop foreign key constraint first
        $this->addSql('ALTER TABLE communaute_rnf_users DROP FOREIGN KEY FK_B94B7BBF6BD1646');
        // Drop the index
        $this->addSql('DROP INDEX IDX_B94B7BBF6BD1646 ON communaute_rnf_users');
        // Drop the site_id column
        $this->addSql('ALTER TABLE communaute_rnf_users DROP site_id');
    }

    public function down(Schema $schema): void
    {
        // Re-add the site_id column
        $this->addSql('ALTER TABLE communaute_rnf_users ADD site_id INT DEFAULT NULL');
        // Re-add the foreign key constraint
        $this->addSql('ALTER TABLE communaute_rnf_users ADD CONSTRAINT FK_B94B7BBF6BD1646 FOREIGN KEY (site_id) REFERENCES communaute_rnf_sites (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        // Re-add the index
        $this->addSql('CREATE INDEX IDX_B94B7BBF6BD1646 ON communaute_rnf_users (site_id)');
    }
}