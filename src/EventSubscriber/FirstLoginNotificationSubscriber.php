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

        // Check if this is the first login notification
        if (!$user->getFirstLoginNotified()) {
            // Check if profile needs completion
            if ($this->needsProfileCompletion($user)) {
                $profileUrl = $this->urlGenerator->generate('user_profile_edit');
                $message = sprintf(
                    'Bienvenue ! Pour tirer le meilleur parti de la plateforme, nous vous encourageons à <a href="%s">compléter votre profil</a>.',
                    $profileUrl
                );
                
                $this->session->getFlashBag()->add('info', $message);
            }

            // Mark as notified
            $user->setFirstLoginNotified(true);
            $this->entityManager->flush();
        }
    }

    private function needsProfileCompletion(User $user): bool
    {
        // Check if important profile fields are missing
        return empty($user->getName()) || 
               empty($user->getPresentation()) || 
               empty($user->getBio()) ||
               empty($user->getCity()) ||
               $user->getSkills()->isEmpty();
    }
}