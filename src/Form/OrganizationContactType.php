<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Actor;
use App\Entity\OrganizationContact;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

final class OrganizationContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('person', EntityType::class, [
                'class' => Actor::class,
                'label' => 'actors.person',
                'placeholder' => 'actors.choose_person',
                'choices' => $options['people'],
                'choice_label' => static fn (Actor $person): string => $person->getDisplayName(),
                'constraints' => [
                    new NotNull(message: 'actors.required_contact_name'),
                ],
            ])
            ->add('functionTitle', TextType::class, [
                'label' => 'actors.contact_function',
                'required' => false,
                'constraints' => [
                    new Length(max: 255, maxMessage: 'actors.max_255'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => OrganizationContact::class,
            'translation_domain' => 'messages',
            'people' => [],
        ]);
        $resolver->setAllowedTypes('people', 'array');
    }
}
