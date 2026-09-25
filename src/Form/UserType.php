<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isNew = (bool) $options['is_new'];
        /** @var User $user */
        $user = $options['data'];
        $ssoManaged = $user->isSsoManaged();

        $builder
            ->add('fullName', TextType::class, [
                'label' => 'users.full_name',
                'disabled' => $ssoManaged,
                'constraints' => [
                    new NotBlank(message: 'users.required_full_name'),
                    new Length(max: 255, maxMessage: 'users.max_255'),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'users.email',
                'disabled' => $ssoManaged,
                'constraints' => [
                    new NotBlank(message: 'users.required_email'),
                    new Email(message: 'users.invalid_email'),
                    new Length(max: 180, maxMessage: 'users.max_180'),
                ],
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'users.active',
                'required' => false,
                'disabled' => $options['lock_access'],
            ])
            ->add('accessLevel', ChoiceType::class, [
                'label' => 'users.access_level',
                'mapped' => false,
                'expanded' => true,
                'multiple' => false,
                'choices' => [
                    'users.role_read_only' => User::ROLE_READ_ONLY,
                    'users.role_user' => User::ROLE_USER,
                    'users.role_admin' => User::ROLE_ADMIN,
                ],
                'choice_translation_domain' => 'messages',
                'disabled' => $options['lock_access'] || $ssoManaged,
                'data' => $user->isAdmin()
                    ? User::ROLE_ADMIN
                    : ($user->isReadOnly() ? User::ROLE_READ_ONLY : User::ROLE_USER),
            ]);

        if (!$ssoManaged) {
            $builder->add('plainPassword', PasswordType::class, [
                'label' => $isNew ? 'users.password' : 'users.new_password',
                'mapped' => false,
                'required' => $isNew,
                'constraints' => array_filter([
                    $isNew ? new NotBlank(message: 'users.password_required') : null,
                    new Length(max: 255, maxMessage: 'users.max_255'),
                ]),
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'translation_domain' => 'messages',
            'is_new' => false,
            'lock_access' => false,
        ]);

        $resolver->setAllowedTypes('is_new', 'bool');
        $resolver->setAllowedTypes('lock_access', 'bool');
    }
}
