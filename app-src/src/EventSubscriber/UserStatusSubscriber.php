<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class UserStatusSubscriber implements EventSubscriberInterface
{
    private const ALLOWED_ROUTES = [
        'app_login',
        'app_logout',
        'app_register',
        'app_verify_email',
    ];

    private const TOUCH_INTERVAL = 60;

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 7]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route   = (string) $request->attributes->get('_route');

        if ('' === $route || str_starts_with($route, '_')) {
            return;
        }

        if (\in_array($route, self::ALLOWED_ROUTES, true)) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $fresh = $this->em->getRepository(User::class)->find($user->getId());

        if (null === $fresh) {
            $this->kickOut($event, 'Your account has been deleted.');

            return;
        }

        if ($fresh->isBlocked()) {
            $this->kickOut($event, 'Your account has been blocked.');

            return;
        }

        $now  = new \DateTimeImmutable();
        $last = $fresh->getLastSeenAt();

        if (null === $last || ($now->getTimestamp() - $last->getTimestamp()) > self::TOUCH_INTERVAL) {
            $fresh->setLastSeenAt($now);
            $this->em->flush();
        }
    }

    private function kickOut(RequestEvent $event, string $message): void
    {
        $request = $event->getRequest();

        $this->tokenStorage->setToken(null);

        if ($request->hasSession()) {
            $session = $request->getSession();
            $session->invalidate();
            $session->start();
            $session->getFlashBag()->add('danger', $message);
        }

        $event->setResponse(
            new RedirectResponse($this->urlGenerator->generate('app_login'))
        );
    }
}
