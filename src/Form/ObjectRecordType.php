<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ObjectRecord;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;

final class ObjectRecordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('inventoryNumber', TextType::class, [
                'label' => 'objects.inventory_number',
                'constraints' => [
                    new NotBlank(message: 'objects.required_inventory_number'),
                    new Length(max: 100, maxMessage: 'objects.max_100'),
                ],
            ])
            ->add('title', TextType::class, [
                'label' => 'objects.object_title',
                'constraints' => [
                    new NotBlank(message: 'objects.required_title'),
                    new Length(max: 255, maxMessage: 'objects.max_255'),
                ],
            ])
            ->add('creator', TextType::class, [
                'label' => 'objects.creator',
                'required' => false,
                'constraints' => [
                    new Length(max: 255, maxMessage: 'objects.max_255'),
                ],
            ])
            ->add('publisher', TextType::class, [
                'label' => 'objects.publisher',
                'required' => false,
                'constraints' => [
                    new Length(max: 255, maxMessage: 'objects.max_255'),
                ],
            ])
            ->add('objectType', ChoiceType::class, [
                'label' => 'objects.object_type',
                'placeholder' => 'objects.choose_object_type',
                'attr' => [
                    'data-smart-select' => '1',
                    'data-smart-select-custom-value' => ObjectRecord::TYPE_OTHER,
                    'data-smart-select-key' => 'object-type',
                ],
                'choices' => ObjectRecord::objectTypeChoices(),
                'choice_translation_domain' => 'messages',
                'required' => true,
                'constraints' => [
                    new NotBlank(message: 'objects.required_object_type'),
                    new Choice(choices: ObjectRecord::objectTypes(), message: 'objects.invalid_object_type'),
                ],
            ])
            ->add('customObjectType', HiddenType::class, [
                'required' => false,
                'attr' => [
                    'data-smart-select-custom-input' => 'object-type',
                ],
                'constraints' => [
                    new Length(max: 100, maxMessage: 'objects.max_100'),
                ],
            ])
            ->add('currentLocation', TextType::class, [
                'label' => 'objects.current_location',
                'required' => false,
                'constraints' => [
                    new Length(max: 255, maxMessage: 'objects.max_255'),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'objects.description',
                'required' => false,
            ])
            ->add('iiifManifestUrl', UrlType::class, [
                'label' => 'objects.iiif_manifest_url',
                'required' => false,
                'mapped' => false,
                'data' => $options['manifest_url'],
                'constraints' => [
                    new Url(message: 'objects.invalid_url'),
                    new Length(max: 255, maxMessage: 'objects.max_255'),
                ],
            ])
            ->add('iiifThumbnailUrl', UrlType::class, [
                'label' => 'objects.iiif_thumbnail_url',
                'required' => false,
                'mapped' => false,
                'data' => $options['thumbnail_url'],
                'constraints' => [
                    new Url(message: 'objects.invalid_url'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ObjectRecord::class,
            'translation_domain' => 'messages',
            'manifest_url' => '',
            'thumbnail_url' => '',
        ]);

        $resolver->setAllowedTypes('manifest_url', 'string');
        $resolver->setAllowedTypes('thumbnail_url', 'string');
    }
}
