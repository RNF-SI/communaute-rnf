<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\SecurityEvents;

class FirstLoginNotificationSubscriber implements EventSubscriberInterface
{
    private $entityManager;
    private $session;
    private $urlGenerator;

    public function __construct(
        EntityManagerInterface $entityManager,
        SessionInterface $session,
        UrlGeneratorInterface $urlGenerator
    ) {
        $this->entityManager = $entityManager;
        $this->session = $session;
        $this->urlGenerator = $urlGenerator;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SecurityEvents::INTERACTIVE_LOGIN => 'onInteractiveLogin',
        ];
    }

    public function onInteractiveLogin(InteractiveLoginEvent $event): void
    {
        $user = $event->getAuthenticationToken()->getUser();

        if (!$user instanceof User) {
            return;
        }

        // Show notification if profile has never been updated
        if ($user->getProfileUpdatedAt() === null) {
            $profileUrl = $this->urlGenerator->generate('user_profile_edit');
            $message = sprintf(
                'Bienvenue ! Pour tirer le meilleur parti de la plateforme, nous vous encourageons à <a href="%s">compléter votre profil</a>.',
                $profileUrl
            );
            
            $this->session->getFlashBag()->add('info', $message);
            
            // Mark as notified only the first time
            if (!$user->getFirstLoginNotified()) {
                $user->setFirstLoginNotified(true);
                $this->entityManager->flush();
            }
        }
    }
}