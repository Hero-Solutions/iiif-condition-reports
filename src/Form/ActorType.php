<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Actor;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class ActorType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'actors.type',
                'choices' => Actor::typeChoices(),
                'choice_translation_domain' => 'messages',
                'disabled' => $options['lock_type'],
            ])
            ->add('alias', TextType::class, [
                'label' => 'actors.alias',
                'required' => false,
                'constraints' => [
                    new Length(max: 255, maxMessage: 'actors.max_255'),
                ],
            ])
            ->add('name', TextType::class, [
                'label' => 'actors.name',
                'constraints' => [
                    new NotBlank(message: 'actors.required_name'),
                    new Length(max: 255, maxMessage: 'actors.max_255'),
                ],
            ])
            ->add('logo', TextType::class, [
                'label' => 'actors.logo',
                'required' => false,
                'constraints' => [
                    new Length(max: 255, maxMessage: 'actors.max_255'),
                ],
            ])
            ->add('vat', TextType::class, [
                'label' => 'actors.vat',
                'required' => false,
                'constraints' => [
                    new Length(max: 255, maxMessage: 'actors.max_255'),
                ],
            ])
            ->add('address', TextType::class, [
                'label' => 'actors.address',
                'required' => false,
                'constraints' => [
                    new Length(max: 255, maxMessage: 'actors.max_255'),
                ],
            ])
            ->add('postal', TextType::class, [
                'label' => 'actors.postal',
                'required' => false,
                'constraints' => [
                    new Length(max: 255, maxMessage: 'actors.max_255'),
                ],
            ])
            ->add('city', TextType::class, [
                'label' => 'actors.city',
                'required' => false,
                'constraints' => [
                    new Length(max: 255, maxMessage: 'actors.max_255'),
                ],
            ])
            ->add('stateProvince', TextType::class, [
                'label' => 'actors.state_province',
                'required' => false,
                'constraints' => [
                    new Length(max: 255, maxMessage: 'actors.max_255'),
                ],
            ])
            ->add('country', TextType::class, [
                'label' => 'actors.country',
                'required' => false,
                'constraints' => [
                    new Length(max: 255, maxMessage: 'actors.max_255'),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'actors.email',
                'required' => false,
                'constraints' => [
                    new Length(max: 180, maxMessage: 'actors.max_180'),
                ],
            ])
            ->add('website', TextType::class, [
                'label' => 'actors.website',
                'required' => false,
                'constraints' => [
                    new Length(max: 255, maxMessage: 'actors.max_255'),
                ],
            ])
            ->add('phone', TextType::class, [
                'label' => 'actors.phone',
                'required' => false,
                'constraints' => [
                    new Length(max: 100, maxMessage: 'actors.max_100'),
                ],
            ])
            ->add('mobile', TextType::class, [
                'label' => 'actors.mobile',
                'required' => false,
                'constraints' => [
                    new Length(max: 100, maxMessage: 'actors.max_100'),
                ],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'actors.notes',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Actor::class,
            'translation_domain' => 'messages',
            'lock_type' => false,
        ]);
        $resolver->setAllowedTypes('lock_type', 'bool');
    }
}
