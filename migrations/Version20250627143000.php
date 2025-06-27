<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250627143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add all RNF authentication columns to users table';
    }

    public function up(Schema $schema): void
    {
        // Vérifier si les colonnes existent déjà avant de les ajouter
        $connection = $this->connection;
        $schemaManager = $connection->getSchemaManager();
        $columns = $schemaManager->listTableColumns('communaute_rnf_users');
        
        $columnNames = array_map(function($column) {
            return $column->getName();
        }, $columns);
        
        // Ajouter seulement les colonnes qui n'existent pas
        if (!in_array('rnf_id_role', $columnNames)) {
            $this->addSql('ALTER TABLE communaute_rnf_users ADD rnf_id_role INT DEFAULT NULL');
        }
        
        if (!in_array('rnf_id_organisme', $columnNames)) {
            $this->addSql('ALTER TABLE communaute_rnf_users ADD rnf_id_organisme INT DEFAULT NULL');
        }
        
        if (!in_array('rnf_user_login', $columnNames)) {
            $this->addSql('ALTER TABLE communaute_rnf_users ADD rnf_user_login VARCHAR(255) DEFAULT NULL');
        }
        
        if (!in_array('rnf_prenom_role', $columnNames)) {
            $this->addSql('ALTER TABLE communaute_rnf_users ADD rnf_prenom_role VARCHAR(100) DEFAULT NULL');
        }
        
        if (!in_array('rnf_nom_role', $columnNames)) {
            $this->addSql('ALTER TABLE communaute_rnf_users ADD rnf_nom_role VARCHAR(100) DEFAULT NULL');
        }
        
        if (!in_array('rnf_role_info', $columnNames)) {
            $this->addSql('ALTER TABLE communaute_rnf_users ADD rnf_role_info JSON DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communaute_rnf_users DROP rnf_id_role');
        $this->addSql('ALTER TABLE communaute_rnf_users DROP rnf_id_organisme');
        $this->addSql('ALTER TABLE communaute_rnf_users DROP rnf_user_login');
        $this->addSql('ALTER TABLE communaute_rnf_users DROP rnf_prenom_role');
        $this->addSql('ALTER TABLE communaute_rnf_users DROP rnf_nom_role');
        $this->addSql('ALTER TABLE communaute_rnf_users DROP rnf_role_info');
    }
}