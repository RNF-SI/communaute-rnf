<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250626105831 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE communaute_rnf_usergroup_hierarchy (child_id INT NOT NULL, parent_id INT NOT NULL, INDEX IDX_2110343DDD62C21B (child_id), INDEX IDX_2110343D727ACA70 (parent_id), PRIMARY KEY(child_id, parent_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE communaute_rnf_usergroup_hierarchy ADD CONSTRAINT FK_2110343DDD62C21B FOREIGN KEY (child_id) REFERENCES communaute_rnf_usergroups (id)');
        $this->addSql('ALTER TABLE communaute_rnf_usergroup_hierarchy ADD CONSTRAINT FK_2110343D727ACA70 FOREIGN KEY (parent_id) REFERENCES communaute_rnf_usergroups (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE communaute_rnf_usergroup_hierarchy');
    }
}
