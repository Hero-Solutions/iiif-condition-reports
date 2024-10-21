<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

class RemarksController extends AbstractController
{
    /**
     * Display & process form to submit remarks
     */
    #[Route("/{_locale}/remarks", name: "remarks")]
    public function remarks(Request $request, MailerInterface $mailer, TranslatorInterface $translator): Response
    {
        $locale = $request->get('_locale');
        $locales = $this->getParameter('locales');
        //Set default locale if locale is missing
        if ($locale === null || !in_array($locale, $locales)) {
            return $this->redirectToRoute('manual', array('_locale' => $locales[0]));
        }
        if (!$this->getUser()) {
            return $this->redirectToRoute('main');
        } else if (!$this->getUser()->getRoles()) {
            return $this->redirectToRoute('main');
        } else if (!in_array('ROLE_USER', $this->getUser()->getRoles(), true)) {
            return $this->redirectToRoute('main');
        }


        $form = $this->createFormBuilder()
            ->add('subject', TextType::class, [
                'label' => $translator->trans('Subject'),
                'constraints' => [
                    new NotBlank([
                        'message' => $translator->trans('Please enter a subject'),
                    ]),
                ],
            ])
            ->add('body', TextareaType::class, [
                'label' => $translator->trans('Remark'),
                'row_attr' => ['id' => 'remarks-textarea-div'],
                'constraints' => [
                    new NotBlank([
                        'message' => $translator->trans('Please enter a message'),
                    ]),
                ],
            ])
            ->add('name', TextType::class, [
                'label' => $translator->trans('Your name'),
                'constraints' => [
                    new NotBlank([
                        'message' => $translator->trans('Please enter your name'),
                    ]),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => $translator->trans('Your e-mail address'),
                'attr' => ['autocomplete' => 'email'],
                'constraints' => [
                    new NotBlank([
                        'message' => $translator->trans('Please enter your email'),
                    ]),
                ],
            ])
            ->getForm();
        $form->handleRequest($request);

        $locale = $request->get('_locale');
        $locales = $this->getParameter('locales');
        $translatedRoutes = array();
        foreach($locales as $l) {
            $translatedRoutes[] = array(
                'lang' => $l,
                'url' => $this->generateUrl('remarks', array('_locale' => $l)),
                'active' => $l === $locale
            );
        }

        if ($form->isSubmitted() && $form->isValid()) {
             $this->sendRemarksEmail(
                $form->get('subject')->getData(),
                $form->get('body')->getData(),
                $form->get('name')->getData(),
                $form->get('email')->getData(),
                $mailer
            );
            return $this->render('remarks_thank_you.html.twig', [
                'current_page' => 'remarks',
                'translated_routes' => $translatedRoutes
            ]);
        } else {
            return $this->render('remarks.html.twig', [
                'form' => $form->createView(),
                'current_page' => 'remarks',
                'translated_routes' => $translatedRoutes
            ]);
        }
    }

    private function sendRemarksEmail($subject, $body, $name, $emailAddress, MailerInterface $mailer)
    {
        $email = (new Email())
            ->from(new Address($emailAddress, $name))
            ->replyTo($emailAddress)
            ->to('michiel@herosolutions.be')
            ->subject($subject)
            ->text($body . PHP_EOL . PHP_EOL . 'Submitted by ' . $emailAddress)
        ;
        $mailer->send($email);

        $email = (new Email())
            ->from(new Address($emailAddress, $name))
            ->replyTo($emailAddress)
            ->to('pascal.ennaert@meemoo.be')
            ->subject($subject)
            ->text($body . PHP_EOL . PHP_EOL . 'Submitted by ' . $emailAddress)
        ;
        $mailer->send($email);

        $email = (new Email())
            ->from(new Address($emailAddress, $name))
            ->replyTo($emailAddress)
            ->to('an.seurinck@meemoo.be')
            ->subject($subject)
            ->text($body . PHP_EOL . PHP_EOL . 'Submitted by ' . $emailAddress)
        ;
        $mailer->send($email);
    }
}
