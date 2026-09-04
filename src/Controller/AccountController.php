<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AccountController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        #[Autowire('%env(MAIL_FROM)%')] private readonly string $mailFrom,
        #[Autowire('%env(FEEDBACK_EMAIL)%')] private readonly string $feedbackEmail,
        #[Autowire('%env(bool:LOCAL_LOGIN_ENABLED)%')] private readonly bool $localLoginEnabled,
    ) {
    }

    #[Route('/{_locale<nl|en>}/profile', name: 'profile_edit', methods: ['GET', 'POST'])]
    public function profile(Request $request): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if ($user->isSsoManaged()) {
            return $this->render('account/profile.html.twig', ['user' => $user]);
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('profile_edit', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $fullName = trim((string) $request->request->get('full_name'));
            $email = mb_strtolower(trim((string) $request->request->get('email')));
            $newPassword = (string) $request->request->get('new_password');
            $currentPassword = (string) $request->request->get('current_password');
            $passwordConfirmation = (string) $request->request->get('password_confirmation');
            $existingUser = $email === '' ? null : $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

            if ($fullName === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $this->addFlash('error', 'profile.invalid_details');
            } elseif ($existingUser instanceof User && $existingUser !== $user) {
                $this->addFlash('error', 'users.email_exists');
            } elseif ($newPassword !== '' && (!$this->passwordHasher->isPasswordValid($user, $currentPassword) || strlen($newPassword) < 8 || $newPassword !== $passwordConfirmation)) {
                $this->addFlash('error', 'profile.invalid_password_change');
            } else {
                $user->setFullName(mb_substr($fullName, 0, 255))->setEmail(mb_substr($email, 0, 180));

                if ($newPassword !== '') {
                    $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
                }

                $this->entityManager->flush();
                $this->addFlash('success', 'profile.saved');

                return $this->redirectToRoute('profile_edit', ['_locale' => $request->getLocale()]);
            }
        }

        return $this->render('account/profile.html.twig', ['user' => $user]);
    }

    #[Route('/{_locale<nl|en>}/forgot-password', name: 'password_forgot', methods: ['GET', 'POST'])]
    public function forgotPassword(Request $request): Response
    {
        if (!$this->localLoginEnabled) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('password_forgot', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $emailAddress = mb_strtolower(trim((string) $request->request->get('email')));
            $user = $this->entityManager->getRepository(User::class)->findOneBy([
                'email' => $emailAddress,
                'active' => true,
                'entraObjectId' => null,
            ]);

            if ($user instanceof User) {
                foreach ($this->entityManager->getRepository(PasswordResetToken::class)->findBy(['user' => $user]) as $existingToken) {
                    $this->entityManager->remove($existingToken);
                }

                $plainToken = bin2hex(random_bytes(32));
                $this->entityManager->persist(new PasswordResetToken($user, $plainToken, new \DateTimeImmutable('+1 hour')));
                $this->entityManager->flush();
                $resetUrl = $this->generateUrl('password_reset', [
                    '_locale' => $request->getLocale(),
                    'token' => $plainToken,
                ], UrlGeneratorInterface::ABSOLUTE_URL);

                try {
                    $this->mailer->send((new Email())
                        ->from($this->mailFrom)
                        ->to($user->getEmail())
                        ->subject($this->translatorMessage($request, 'password_reset.email_subject'))
                        ->text($this->translatorMessage($request, 'password_reset.email_text', ['%url%' => $resetUrl])));
                } catch (TransportExceptionInterface) {
                    // Keep the public response identical to avoid disclosing accounts.
                }
            }

            $this->addFlash('success', 'password_reset.request_received');

            return $this->redirectToRoute('app_login', ['_locale' => $request->getLocale()]);
        }

        return $this->render('account/forgot_password.html.twig');
    }

    #[Route('/{_locale<nl|en>}/reset-password/{token<[a-f0-9]{64}>}', name: 'password_reset', methods: ['GET', 'POST'])]
    public function resetPassword(string $token, Request $request): Response
    {
        if (!$this->localLoginEnabled) {
            throw $this->createNotFoundException();
        }

        $resetToken = $this->entityManager->getRepository(PasswordResetToken::class)->findOneBy([
            'tokenHash' => PasswordResetToken::hash($token),
        ]);

        if (!$resetToken instanceof PasswordResetToken || $resetToken->isExpired()) {
            $this->addFlash('error', 'password_reset.invalid_token');

            return $this->redirectToRoute('password_forgot', ['_locale' => $request->getLocale()]);
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('password_reset_' . $token, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $password = (string) $request->request->get('password');
            $confirmation = (string) $request->request->get('password_confirmation');

            if (strlen($password) < 8 || $password !== $confirmation) {
                $this->addFlash('error', 'password_reset.invalid_password');
            } else {
                $user = $resetToken->getUser();
                $user->setPassword($this->passwordHasher->hashPassword($user, $password));

                foreach ($this->entityManager->getRepository(PasswordResetToken::class)->findBy(['user' => $user]) as $tokenToRemove) {
                    $this->entityManager->remove($tokenToRemove);
                }

                $this->entityManager->flush();
                $this->addFlash('success', 'password_reset.saved');

                return $this->redirectToRoute('app_login', ['_locale' => $request->getLocale()]);
            }
        }

        return $this->render('account/reset_password.html.twig', ['token' => $token]);
    }

    #[Route('/{_locale<nl|en>}/feedback', name: 'feedback', methods: ['GET', 'POST'])]
    public function feedback(Request $request): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('feedback', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $subject = trim((string) $request->request->get('subject'));
            $message = trim((string) $request->request->get('message'));

            if ($subject === '' || $message === '') {
                $this->addFlash('error', 'feedback.required');
            } else {
                try {
                    $this->mailer->send((new Email())
                        ->from($this->mailFrom)
                        ->replyTo($user->getEmail())
                        ->to($this->feedbackEmail)
                        ->subject('[Conditierapporten] ' . mb_substr($subject, 0, 180))
                        ->text($user->getFullName() . ' <' . $user->getEmail() . ">\n\n" . $message));
                    $this->addFlash('success', 'feedback.sent');

                    return $this->redirectToRoute('feedback', ['_locale' => $request->getLocale()]);
                } catch (TransportExceptionInterface) {
                    $this->addFlash('error', 'feedback.failed');
                }
            }
        }

        return $this->render('account/feedback.html.twig', ['user' => $user]);
    }

    #[Route('/{_locale<nl|en>}/disclaimer', name: 'disclaimer', methods: ['GET'])]
    public function disclaimer(): Response
    {
        return $this->render('account/disclaimer.html.twig');
    }

    /** @param array<string, string> $parameters */
    private function translatorMessage(Request $request, string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'messages', $request->getLocale());
    }
}
