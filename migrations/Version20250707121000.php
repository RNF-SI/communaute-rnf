<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop the sites table completely as it's no longer used
 */
final class Version20250707121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the sites table completely as it\'s no longer used';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE communaute_rnf_sites');
    }

    public function down(Schema $schema): void
    {
        // Recreate the sites table if needed
        $this->addSql('CREATE TABLE communaute_rnf_sites (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, UNIQUE INDEX UNIQ_5453D1335E237E06 (name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
    }
}