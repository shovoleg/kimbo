<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_users');
        }

        return $this->render('security/login.html.twig', [
            'last_email' => $authenticationUtils->getLastUsername(),
            'error'      => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Перехватывается firewall\'ом.');
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        MailerInterface $mailer,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_users');
        }

        if ($request->isMethod('GET')) {
            return $this->render('security/register.html.twig');
        }

        if (!$this->isCsrfTokenValid('register', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_register');
        }

        $name     = trim((string) $request->request->get('name'));
        $email    = trim((string) $request->request->get('email'));
        $password = (string) $request->request->get('password');

        $errors = [];
        if ('' === $name) {
            $errors[] = 'Name is required.';
        }
        if ('' === $email) {
            $errors[] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if ('' === $password) {
            $errors[] = 'Password is required.';
        }

        if ($errors) {
            foreach ($errors as $e) {
                $this->addFlash('danger', $e);
            }

            return $this->render('security/register.html.twig', [
                'old_name'  => $name,
                'old_email' => $email,
            ]);
        }

        $user = new User();
        $user->setName($name);
        $user->setEmail($email);
        $user->setPassword($hasher->hashPassword($user, $password));
        $user->setStatus(User::STATUS_UNVERIFIED);

        try {
            $em->persist($user);
            $em->flush();
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('danger', 'This email address is already registered.');

            return $this->render('security/register.html.twig', [
                'old_name'  => $name,
                'old_email' => $email,
            ]);
        }

        $link = rtrim((string) $this->getParameter('app.base_url'), '/')
            . $this->generateUrl('app_verify_email', [
                'id'    => $user->getId(),
                'token' => $this->makeToken($user),
            ]);

        try {
            $mail = (new TemplatedEmail())
                ->from(new Address((string) $this->getParameter('app.mail_from'), 'kimbo'))
                ->to(new Address((string) $user->getEmail(), (string) $user->getName()))
                ->subject('Confirm your email address')
                ->htmlTemplate('email/verify.html.twig')
                ->context([
                    'name' => $user->getName(),
                    'link' => $link,
                ]);

            $mailer->send($mail);

            $this->addFlash('success',
                'Registration complete. We have sent a confirmation link to ' . $user->getEmail() . '.');
        } catch (TransportExceptionInterface $e) {
            $this->addFlash('warning',
                'Account created, but the confirmation email could not be queued. You can still sign in.');
        }

        return $this->redirectToRoute('app_login');
    }

    #[Route('/verify/{id}/{token}', name: 'app_verify_email', methods: ['GET'])]
    public function verify(
        int $id,
        string $token,
        UserRepository $repo,
        EntityManagerInterface $em,
    ): Response {
        $user = $repo->find($id);

        if (null === $user) {
            $this->addFlash('danger', 'Verification link is invalid or the account no longer exists.');

            return $this->redirectToRoute('app_login');
        }

        if (!hash_equals($this->makeToken($user), $token)) {
            $this->addFlash('danger', 'Verification link is invalid.');

            return $this->redirectToRoute('app_login');
        }

        if (User::STATUS_UNVERIFIED === $user->getStatus()) {
            $user->setStatus(User::STATUS_ACTIVE);
            $em->flush();
            $this->addFlash('success', 'Email confirmed. Your account is now active.');
        } elseif ($user->isBlocked()) {
            $this->addFlash('warning', 'Your account is blocked. Please contact an administrator.');
        } else {
            $this->addFlash('info', 'This email address has already been confirmed.');
        }

        return $this->redirectToRoute('app_login');
    }

    private function makeToken(User $user): string
    {
        return substr(hash_hmac(
            'sha256',
            $user->getId() . '|' . $user->getEmail(),
            (string) $this->getParameter('kernel.secret')
        ), 0, 40);
    }
}
