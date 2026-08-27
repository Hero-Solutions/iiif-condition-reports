<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Project;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class ProjectType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'projects.project_title',
                'constraints' => [
                    new NotBlank(message: 'projects.required_title'),
                    new Length(max: 255, maxMessage: 'projects.max_255'),
                ],
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'projects.type',
                'attr' => [
                    'data-smart-select' => '1',
                    'data-project-type-select' => '1',
                    'data-smart-select-custom-value' => Project::TYPE_OTHER,
                    'data-smart-select-key' => 'project-type',
                ],
                'choices' => [
                    'project_type.loan' => Project::TYPE_LOAN,
                    'project_type.exhibition' => Project::TYPE_EXHIBITION,
                    'project_type.restoration' => Project::TYPE_RESTORATION,
                    'project_type.research' => Project::TYPE_RESEARCH,
                    'project_type.movement' => Project::TYPE_MOVEMENT,
                    'project_type.conservation' => Project::TYPE_CONSERVATION,
                    'project_type.other' => Project::TYPE_OTHER,
                ],
                'choice_translation_domain' => 'messages',
            ])
            ->add('customType', TextType::class, [
                'label' => 'projects.custom_type',
                'required' => false,
                'row_attr' => [
                    'data-project-custom-type-row' => '1',
                ],
                'attr' => [
                    'data-project-custom-type-input' => '1',
                    'data-smart-select-custom-input' => 'project-type',
                ],
                'constraints' => [
                    new Length(max: 100, maxMessage: 'projects.max_100'),
                ],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'projects.status',
                'choices' => [
                    'project_status.planned' => Project::STATUS_PLANNED,
                    'project_status.active' => Project::STATUS_ACTIVE,
                    'project_status.completed' => Project::STATUS_COMPLETED,
                    'project_status.cancelled' => Project::STATUS_CANCELLED,
                ],
                'choice_translation_domain' => 'messages',
            ])
            ->add('referenceCode', TextType::class, [
                'label' => 'projects.reference_code',
                'required' => false,
                'constraints' => [
                    new Length(max: 100, maxMessage: 'projects.max_100'),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'projects.description',
                'required' => false,
            ])
            ->add('address', TextareaType::class, [
                'label' => 'projects.address',
                'required' => false,
            ])
            ->add('website', TextType::class, [
                'label' => 'projects.website',
                'required' => false,
                'constraints' => [new Length(max: 500, maxMessage: 'projects.max_500')],
            ])
            ->add('startDate', DateType::class, [
                'label' => 'projects.start_date',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('endDate', DateType::class, [
                'label' => 'projects.end_date',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('insuranceStartDate', DateType::class, [
                'label' => 'projects.insurance_start_date',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('insuranceEndDate', DateType::class, [
                'label' => 'projects.insurance_end_date',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Project::class,
            'translation_domain' => 'messages',
        ]);
    }
}
