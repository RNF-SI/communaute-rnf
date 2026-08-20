<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use TeamTNT\TNTSearch\TNTSearch;
use App\Service\SearchEngineManager;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ReindexCommand extends Command {
	protected static $defaultName = 'search:reindex';

	private $searchEngineManager;

	public function __construct ( SearchEngineManager $searchEngineManager ) {
		$this->searchEngineManager = $searchEngineManager;

		parent::__construct();
	}

	protected function configure () {
		$this
				->setDescription( 'Generate a particular index' )
				->setHelp( 'Possibilities are: pages, discussions_messages, articles, documents, group et members.' );

		$this
				->addArgument( 'index-name', InputArgument::REQUIRED, 'The name of the index.' );
	}

	protected function execute ( InputInterface $input, OutputInterface $output ) {
		$indexName = $input->getArgument('index-name');

		$tnt = new TNTSearch;
		// Obtain and load the configuration that can be generated with the previous described method
		$tnt->loadConfig($this->searchEngineManager->getTNTSearchConfiguration());

		$query = $this->getQueryFromIndex($indexName);
		if($query !== ''){
			$output->writeln($indexName.'.index generation');
			$indexer = $tnt->createIndex($indexName.'.index');
			$indexer->query($query);
			$indexer->run();
		} else {
			$output->writeln('Attention:');
			$output->writeln($input->getArgument('index-name').' ne correspond pas à un index existant');
		}
	}

	protected function getQueryFromIndex($indexName) {
		switch ($indexName) {
			case 'pages':
				return 'SELECT id, title, body FROM communaute_rnf_pages;';
			case 'discussions_messages':
				return 'SELECT id, body FROM communaute_rnf_discussion_message;';
			case 'articles':
				return 'SELECT id, title, body FROM communaute_rnf_articles;';
			case 'documents':
				return 'SELECT id, title, description FROM communaute_rnf_document;';
			case 'groups':
				return 'SELECT id, name, description, presentation, is_important FROM communaute_rnf_usergroups
						WHERE is_active<>0
						ORDER BY is_important DESC, id ASC;';
			case 'members':
				return 'SELECT id, name, presentation, job_title AS jobTitle, organisation, reserves FROM communaute_rnf_users;';
			default:
				return '';
		}
	}
}
