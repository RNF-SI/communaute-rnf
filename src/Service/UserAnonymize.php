<?php

namespace App\Service;

use App\Entity\ConversationParticipant;
use App\Entity\User;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class UserAnonymize {

	private $manager;

	private $translator;

	private $fileManager;

	public function __construct (
		EntityManagerInterface $manager,
		TranslatorInterface $translator,
		FileManager $fileManager
	) {
		$this->manager = $manager;
		$this->translator = $translator;
		$this->fileManager = $fileManager;
	}

	public function anonymize ( User $user ) {
		$avatar = $user->getAvatar();
		if($avatar) {
			$this->fileManager->deleteFile($avatar);
			$this->manager->remove($avatar);
		}
		$user->setAvatar(null);

		$userId = $user->getId();
		$user->setEmail(
			sprintf('deleted-%d@communaute-rnf.fr',$userId)
		);
		$user->setName(
			$this->translator->trans('database_data.user.user_deleted_name', [
				'%1$d' => $userId,
			] )
		);
		$user->setDisplayName(
			$this->translator->trans( 'database_data.user.user_deleted_name', [
				'%1$d' => $userId,
			] )
		);

		$userGroupMemberships = $user->getUsergroupMemberships();
		foreach ($userGroupMemberships as $userGroupMembership) {
			$user->removeUsergroupMembership($userGroupMembership);
		}

		$user->setStatus(User::STATUS_DISABLED);
		$user->setZipCode(null);
		$user->setCity(null);
		$user->setCountry(null);
		$user->setPhone(null);
		$user->setEmailVisible(false);
		$user->setPresentation(null);
		$user->setBio(null);
		$user->setJobTitle(null);
		$user->setOrganisation(null);
		$user->setReserves(null);
		$user->setProfileVisibility(null);
		$user->setLocale(null);
		$user->setTimezone(null);
		$user->setSeenAt(null);
		$user->setResetToken(null);
		$user->setLatitude(null);
		$user->setLongitude(null);
		$user->setEmailNew(null);
		$user->setEmailToken(null);

		// La messagerie : le compte sort de ses conversations et ferme sa
		// boîte. Ce qu'il y a écrit reste — l'effacer trouerait le fil de ceux
		// qui restent, comme pour un message de discussion — mais plus
		// personne ne peut lui écrire, et il ne reçoit plus rien.
		$user->setMessagesOpen(false);

		$participations = $this->manager->getRepository(ConversationParticipant::class)
										->findBy(['user' => $user, 'leftAt' => null]);

		$now = new DateTime();

		foreach ($participations as $participation) {
			$participation->setLeftAt($now);

			$conversation = $participation->getConversation();

			// Un tête-à-tête qu'on quitte n'est plus une boîte aux lettres :
			// sans cela la clé unique retiendrait une conversation dont ce
			// compte est sorti.
			if ($conversation) {
				$conversation->setPairKey(null);
			}
		}
	}
}
