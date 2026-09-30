<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Yaml\Yaml;

class AppTextManager
{
    private $manager;

    public function __construct(EntityManagerInterface $manager, string $projectDir)
    {
        $this->manager = $manager;
        $this->projectDir = $projectDir;
        if (!file_exists($this->projectDir.'/config/platform/config.yaml')) {
            copy($this->projectDir.'/config/platform/default.config.yaml', $this->projectDir.'/config/platform/config.yaml');
        }
    }

    public function changeText($tab, $key, $value)
    {
        $adminYaml = Yaml::parse(file_get_contents($this->projectDir.'/config/platform/config.yaml'));
        $adminYaml[$tab][$key] = $value;
        $adminYaml = Yaml::dump($adminYaml, 5);
        file_put_contents($this->projectDir.'/config/platform/config.yaml', $adminYaml);
    }

    public function getTabText($tab)
    {
        $adminYamlTab = Yaml::parse(file_get_contents($this->projectDir.'/config/platform/config.yaml'))[$tab] ?? [];
        $defaultAdminYamlTab = Yaml::parse(file_get_contents($this->projectDir.'/config/platform/default.config.yaml'))[$tab] ?? [];
        foreach ($adminYamlTab as $key => $text) {
            if (is_null($text)) {
                $adminYamlTab[$key] = $defaultAdminYamlTab[$key];
            }
        }

        // config.yaml est copié de default.config.yaml à l'installation, et
        // n'est pas versionné : une section ajoutée depuis (la colonne « Nos
        // autres plateformes », #42) n'y figure pas. Elle vient du défaut
        // tant que l'administration ne l'a pas enregistrée.
        foreach ($defaultAdminYamlTab as $key => $text) {
            if (!array_key_exists($key, $adminYamlTab)) {
                $adminYamlTab[$key] = $text;
            }
        }

        return $adminYamlTab;
    }

    public function getTabSectionText($tab, $section)
    {
        $defaultAdminYamlTab = Yaml::parse(file_get_contents($this->projectDir.'/config/platform/default.config.yaml'))[$tab][$section] ?? [];
        // Même repli que getTabText() pour une section absente de config.yaml.
        $adminYamlTab = Yaml::parse(file_get_contents($this->projectDir.'/config/platform/config.yaml'))[$tab][$section] ?? $defaultAdminYamlTab;
        foreach ($adminYamlTab as $key => $text) {
            if (is_null($text)) {
                $adminYamlTab[$key] = $defaultAdminYamlTab[$key];
            }
        }

        return $adminYamlTab;
    }

    public function changeLiensTitle($tab, $value, $liensType)
    {
        $adminYaml = Yaml::parse(file_get_contents($this->projectDir.'/config/platform/config.yaml'));
        if (!is_null($value)) {
            $adminYaml[$tab][$liensType]['title'] = $value;
        }
        $adminYaml = Yaml::dump($adminYaml, 5);
        file_put_contents($this->projectDir.'/config/platform/config.yaml', $adminYaml);
    }

    public function changeLiens($tab, $liens, $liensType)
    {
        $adminYaml = Yaml::parse(file_get_contents($this->projectDir.'/config/platform/config.yaml'));

        $adminYaml[$tab][$liensType]['liens'] = [];
        foreach ($liens as $index => $lienObj) {
            if (!is_null($lienObj->getNom()) & !is_null($lienObj->getLien())) {
                $adminYaml[$tab][$liensType]['liens'][$index]['nom'] = $lienObj->getNom();
                $adminYaml[$tab][$liensType]['liens'][$index]['lien'] = $lienObj->getLien();
            }
        }
        $adminYaml = Yaml::dump($adminYaml, 5);
        file_put_contents($this->projectDir.'/config/platform/config.yaml', $adminYaml);
    }
}
