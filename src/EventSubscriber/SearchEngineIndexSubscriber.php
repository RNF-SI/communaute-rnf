<?php

namespace App\EventSubscriber;

use App\Entity\Usergroup;
use App\Entity\DiscussionMessage;
use App\Entity\Article;
use App\Entity\Page;
use App\Entity\User;
use App\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\EventSubscriber\EventSubscriberInterface;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use App\Service\SearchEngineManager;
use Psr\Log\LoggerInterface;
use Throwable;


/**
 * Tient les index de recherche à jour au fil des enregistrements.
 *
 * **Un index est un objet dérivé** : il se rebâtit en une commande. Le perdre
 * un instant fait manquer un résultat de recherche ; empêcher un
 * enregistrement, c'est bloquer la plateforme. Ce qui se passe ici ne doit
 * donc jamais faire échouer ce qui l'a déclenché.
 *
 * Ce n'est pas théorique : les fichiers SQLite des index appartiennent à qui a
 * lancé la dernière réindexation à la main. S'il n'est pas le serveur web,
 * chaque écriture lève « attempt to write a readonly database » — et comme
 * créer ou mettre à jour un compte déclenche l'indexation, **c'est la
 * connexion qui tombe**, au pire endroit possible.
 */
class SearchEngineIndexSubscriber implements EventSubscriberInterface
{
	private $searchEngineManager;

	private $logger;

	public function __construct(SearchEngineManager $searchEngineManager, LoggerInterface $logger)
	{
		$this->searchEngineManager = $searchEngineManager;
		$this->logger              = $logger;
	}


	public function getSubscribedEvents(): array
	{
		return [
			Events::postPersist,
			Events::preRemove,
			Events::postUpdate,
		];
	}


	public function postPersist(LifecycleEventArgs $args): void
	{
		$this->processIndex('persist', $args);
	}

	public function preRemove(LifecycleEventArgs $args): void
	{
		$this->processIndex('remove', $args);
	}

	public function postUpdate(LifecycleEventArgs $args): void
	{
		$this->processIndex('update', $args);
	}


	private function processIndex(string $action, LifecycleEventArgs $args): void
	{
		try {
			$this->index($action, $args);
		}
		catch (Throwable $error) {
			// Droits sur les fichiers d'index, disque plein, index absent :
			// autant de raisons de ne pas indexer, aucune de refuser
			// l'enregistrement. `search:reindex:all` rattrape ce qui a été
			// manqué.
			$this->logger->error('Search index not updated', [
				'action' => $action,
				'entity' => get_class($args->getObject()),
				'error'  => $error->getMessage(),
			]);
		}
	}

	/**
	 * @throws \Throwable ce que la mise à jour de l'index a rencontré
	 */
	private function index(string $action, LifecycleEventArgs $args): void
	{
		$entity = $args->getObject();

		if (!$entity instanceof Usergroup && !$entity instanceof DiscussionMessage && !$entity instanceof Document && !$entity instanceof Page && !$entity instanceof User && !$entity instanceof Article) {
			return;
		}
		$this->searchEngineManager->setTNTSearchConfiguration();

		if ($entity instanceof Usergroup) {
			if ($action == 'persist' && !$entity->getIsActive()) {
				return;
			}
			if ($action == 'remove' && !$entity->getIsActive()) {
				return;
			}
			if ($action == 'update') {
				$changes = $args->getEntityManager()->getUnitOfWork()->getEntityChangeSet($args->getObject());

				//if the changes affect the value of 'isActive', we add/remove the entity in the index
				if (isset($changes['isActive'])) {
					//The usergroup is now active, we add the entity in the index
					if ($changes['isActive'][0] == false) {
						$action = 'persist';
					}
					//The usergroup was deactivate, we remove the entity from the index
					else {
						$action = 'remove';
					}
				}
			}
		}
		$this->searchEngineManager->changeIndex($entity, $action);
	}
}
