<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class AdminUserController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        #[Autowire('%env(bool:LOCAL_LOGIN_ENABLED)%')] private readonly bool $localLoginEnabled,
    ) {
    }

    #[Route('/{_locale<nl|en>}/admin/users', name: 'admin_users_index', methods: ['GET'])]
    public function index(): Response
    {
        $users = $this->entityManager
            ->getRepository(User::class)
            ->findBy([], ['fullName' => 'ASC', 'email' => 'ASC']);

        return $this->render('admin/users/index.html.twig', [
            'users' => $users,
            'local_login_enabled' => $this->localLoginEnabled,
        ]);
    }

    #[Route('/{_locale<nl|en>}/admin/users/new', name: 'admin_users_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if (!$this->localLoginEnabled) {
            throw $this->createNotFoundException();
        }

        $user = new User();
        $form = $this->createUserForm($user, true);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->saveUser($user, $form)) {
            $this->addFlash('success', 'users.saved');

            return $this->redirectToRoute('admin_users_index', [
                '_locale' => $request->getLocale(),
            ]);
        }

        return $this->render('admin/users/form.html.twig', [
            'form' => $form,
            'is_new' => true,
            'is_self' => false,
        ]);
    }

    #[Route('/{_locale<nl|en>}/admin/users/{id}/edit', name: 'admin_users_edit', methods: ['GET', 'POST'])]
    public function edit(User $user, Request $request): Response
    {
        $isSelf = $this->isCurrentUser($user);
        $form = $this->createUserForm($user, false, $isSelf);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->saveUser($user, $form, $isSelf)) {
            $this->addFlash('success', 'users.saved');

            return $this->redirectToRoute('admin_users_index', [
                '_locale' => $request->getLocale(),
            ]);
        }

        return $this->render('admin/users/form.html.twig', [
            'form' => $form,
            'is_new' => false,
            'is_self' => $isSelf,
        ]);
    }

    #[Route('/{_locale<nl|en>}/admin/users/{id}/delete', name: 'admin_users_delete', methods: ['POST'])]
    public function delete(User $user, Request $request): Response
    {
        if ($this->isCurrentUser($user)) {
            $this->addFlash('error', 'users.cannot_delete_self');

            return $this->redirectToRoute('admin_users_index', [
                '_locale' => $request->getLocale(),
            ]);
        }

        if ($user->isSsoManaged()) {
            $this->addFlash('error', 'users.cannot_delete_sso');

            return $this->redirectToRoute('admin_users_index', [
                '_locale' => $request->getLocale(),
            ]);
        }

        if (!$this->isCsrfTokenValid('admin_user_delete_' . $user->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->entityManager->remove($user);
        $this->entityManager->flush();
        $this->addFlash('success', 'users.deleted');

        return $this->redirectToRoute('admin_users_index', [
            '_locale' => $request->getLocale(),
        ]);
    }

    private function createUserForm(User $user, bool $isNew, bool $lockAccess = false): FormInterface
    {
        return $this->createForm(UserType::class, $user, [
            'is_new' => $isNew,
            'lock_access' => $lockAccess,
        ]);
    }

    private function saveUser(User $user, FormInterface $form, bool $lockAccess = false): bool
    {
        $existingUser = $this->entityManager->getRepository(User::class)->findOneBy([
            'email' => $user->getEmail(),
        ]);

        if ($existingUser instanceof User && $existingUser->getId() !== $user->getId()) {
            $this->addFlash('error', 'users.email_exists');

            return false;
        }

        if (!$lockAccess && !$user->isSsoManaged()) {
            $roles = [User::ROLE_USER];
            $accessLevel = (string) $form->get('accessLevel')->getData();

            if ($accessLevel === User::ROLE_ADMIN) {
                $roles[] = User::ROLE_ADMIN;
            } elseif ($accessLevel === User::ROLE_READ_ONLY) {
                $roles[] = User::ROLE_READ_ONLY;
            }

            $user->setRoles($roles);
        }

        $plainPassword = trim((string) $form->get('plainPassword')->getData());

        if (!$user->isSsoManaged() && $plainPassword !== '') {
            $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return true;
    }

    private function isCurrentUser(User $user): bool
    {
        $currentUser = $this->getUser();

        return $currentUser instanceof User && $currentUser->getId() === $user->getId();
    }
}
