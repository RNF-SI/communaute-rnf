<?php

namespace App\Twig;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Lets the layout show how many notifications are waiting, without every
 * controller having to pass the count along. (#34)
 */
class NotificationExtension extends AbstractExtension {
	private $manager;

	private $security;

	public function __construct ( EntityManagerInterface $manager, Security $security ) {
		$this->manager  = $manager;
		$this->security = $security;
	}

	public function getFunctions (): array {
		return [
				new TwigFunction( 'unread_notifications', [ $this, 'unreadNotifications' ] ),
		];
	}

	/**
	 * @return int
	 */
	public function unreadNotifications () {
		$user = $this->security->getUser();

		if ( !$user instanceof User ) {
			return 0;
		}

		return $this->manager->getRepository( Notification::class )->countUnread( $user );
	}
}
