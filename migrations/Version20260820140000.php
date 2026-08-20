<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #26 — des étiquettes pour filtrer les documents. Le vocabulaire est
 * fermé, commun à la plateforme et tenu par les administrateurs : un dossier
 * dit où un document est rangé, une étiquette dit ce qu'il est, et les deux
 * se croisent.
 */
final class Version20260820140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a shared vocabulary of document tags';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE communaute_rnf_document_tags (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(60) NOT NULL, slug VARCHAR(60) NOT NULL, UNIQUE INDEX UNIQ_RNF_DOCTAG_SLUG (slug), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE communaute_rnf_documents_tags (document_id INT NOT NULL, document_tag_id INT NOT NULL, INDEX IDX_RNF_DOCTAG_DOC (document_id), INDEX IDX_RNF_DOCTAG_TAG (document_tag_id), PRIMARY KEY(document_id, document_tag_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE communaute_rnf_documents_tags ADD CONSTRAINT FK_RNF_DOCTAG_DOC FOREIGN KEY (document_id) REFERENCES communaute_rnf_document (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE communaute_rnf_documents_tags ADD CONSTRAINT FK_RNF_DOCTAG_TAG FOREIGN KEY (document_tag_id) REFERENCES communaute_rnf_document_tags (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE communaute_rnf_documents_tags');
        $this->addSql('DROP TABLE communaute_rnf_document_tags');
    }
}
