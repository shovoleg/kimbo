<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class UserController extends AbstractController
{
    #[Route('/', name: 'app_users', methods: ['GET'])]
    public function index(Request $request, UserRepository $repo): Response
    {
        $sort = (string) $request->query->get('sort', 'lastSeen');
        $dir  = (string) $request->query->get('dir', 'desc');

        return $this->render('user/index.html.twig', [
            'users'            => $repo->findAllSorted($sort, $dir),
            'sort'             => $sort,
            'dir'              => $dir,
            'unverifiedCount'  => $repo->countUnverified(),
        ]);
    }

    #[Route('/users/action', name: 'app_users_action', methods: ['POST'])]
    public function action(
        Request $request,
        UserRepository $repo,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('user_action', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_users');
        }

        $action = (string) $request->request->get('action');
        $ids    = array_values(array_filter(array_map('intval', (array) $request->request->all('ids'))));

        $back = [
            'sort' => (string) $request->request->get('sort', 'lastSeen'),
            'dir'  => (string) $request->request->get('dir', 'desc'),
        ];

        if ('delete_unverified' === $action) {
            $count = $repo->deleteUnverified();

            $this->addFlash(
                $count > 0 ? 'success' : 'info',
                $count > 0
                    ? \sprintf('Deleted %d unverified %s.', $count, 1 === $count ? 'account' : 'accounts')
                    : 'There are no unverified accounts to delete.'
            );

            $em->clear();
            if ($this->currentUserGone($repo)) {
                return $this->redirectToRoute('app_logout');
            }

            return $this->redirectToRoute('app_users', $back);
        }

        if ([] === $ids) {
            $this->addFlash('warning', 'Please select at least one user.');

            return $this->redirectToRoute('app_users', $back);
        }

        $currentId = $this->getUser() instanceof User ? $this->getUser()->getId() : null;

        $selfAffected = null !== $currentId && \in_array($currentId, $ids, true);

        switch ($action) {
            case 'block':
                $n = $repo->updateStatusByIds($ids, User::STATUS_BLOCKED);
                $this->addFlash('success', \sprintf('Blocked %d %s.', $n, 1 === $n ? 'user' : 'users'));
                break;

            case 'unblock':

                $n = $repo->updateStatusByIds($ids, User::STATUS_ACTIVE);
                $this->addFlash('success', \sprintf('Unblocked %d %s.', $n, 1 === $n ? 'user' : 'users'));
                break;

            case 'delete':
                $n = $repo->deleteByIds($ids);
                $this->addFlash('success', \sprintf('Deleted %d %s.', $n, 1 === $n ? 'user' : 'users'));
                break;

            default:
                $this->addFlash('danger', 'Unknown action.');

                return $this->redirectToRoute('app_users', $back);
        }

        $em->clear();

        if ($selfAffected && \in_array($action, ['block', 'delete'], true)) {
            return $this->redirectToRoute('app_logout');
        }

        return $this->redirectToRoute('app_users', $back);
    }

    private function currentUserGone(UserRepository $repo): bool
    {
        $user = $this->getUser();

        return $user instanceof User && null === $repo->find($user->getId());
    }
}
