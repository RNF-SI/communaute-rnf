<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add profile_updated_at column to track when user profile was last modified
 */
final class Version20250707114900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add profile_updated_at column to users table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communaute_rnf_users ADD profile_updated_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communaute_rnf_users DROP profile_updated_at');
    }
}