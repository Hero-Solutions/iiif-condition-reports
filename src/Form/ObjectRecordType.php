<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Actor;
use App\Entity\ObjectRecord;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ObjectRecordType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $publisher = $options['data'] instanceof ObjectRecord ? $options['data']->getPublisher() : null;
        $publisherChoice = $publisher === null ? null : '__custom__';

        foreach ($options['actors'] as $actor) {
            if ($actor->getName() === $publisher) {
                $publisherChoice = $actor;
                break;
            }
        }

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
            ->add('creator', TextareaType::class, [
                'label' => 'objects.creator',
                'required' => false,
                'attr' => [
                    'rows' => 2,
                    'data-auto-grow' => '1',
                ],
            ])
            ->add('publisherChoice', ChoiceType::class, [
                'label' => 'objects.publisher',
                'mapped' => false,
                'required' => false,
                'placeholder' => 'objects.choose_publisher',
                'choices' => [...$options['actors'], '__custom__'],
                'choice_label' => fn (Actor|string $choice): string => $choice instanceof Actor
                    ? $choice->getName()
                    : $this->translator->trans('objects.custom_publisher'),
                'choice_value' => static fn (Actor|string|null $choice): string => $choice instanceof Actor
                    ? 'actor_'.$choice->getId()
                    : ($choice ?? ''),
                'choice_translation_domain' => false,
                'data' => $publisherChoice,
                'attr' => [
                    'data-smart-select' => '1',
                    'data-smart-select-custom-value' => '__custom__',
                    'data-smart-select-key' => 'object-publisher',
                ],
            ])
            ->add('publisher', HiddenType::class, [
                'mapped' => false,
                'data' => $publisher,
                'required' => false,
                'error_bubbling' => false,
                'attr' => [
                    'data-smart-select-custom-input' => 'object-publisher',
                ],
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
            ->add('externalImageUrl', UrlType::class, [
                'label' => 'objects.external_image_url',
                'required' => false,
                'mapped' => false,
                'data' => $options['external_image_url'],
                'constraints' => [
                    new Url(message: 'objects.invalid_url'),
                ],
            ])
            ->add('imageUpload', FileType::class, [
                'label' => 'objects.image_upload',
                'help' => 'objects.image_upload_help',
                'required' => false,
                'mapped' => false,
                'attr' => [
                    'accept' => 'image/jpeg,image/png,image/webp',
                ],
                'constraints' => [
                    new Image(
                        maxSize: '50M',
                        maxPixels: 60_000_000,
                        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                        maxSizeMessage: 'objects.image_too_large',
                        maxPixelsMessage: 'objects.image_too_many_pixels',
                        mimeTypesMessage: 'objects.invalid_image',
                        corruptedMessage: 'objects.invalid_image',
                    ),
                ],
            ]);

        $builder->addEventListener(FormEvents::SUBMIT, static function (FormEvent $event): void {
            $object = $event->getData();
            $publisherChoice = $event->getForm()->get('publisherChoice')->getData();
            $publisher = $publisherChoice instanceof Actor
                ? $publisherChoice->getName()
                : ($publisherChoice === '__custom__' ? $event->getForm()->get('publisher')->getData() : null);

            if ($object instanceof ObjectRecord && $object->getPublisher() !== $publisher) {
                $object->setPublisher($publisher);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ObjectRecord::class,
            'translation_domain' => 'messages',
            'manifest_url' => '',
            'external_image_url' => '',
            'actors' => [],
        ]);

        $resolver->setAllowedTypes('manifest_url', 'string');
        $resolver->setAllowedTypes('external_image_url', 'string');
        $resolver->setAllowedTypes('actors', Actor::class.'[]');
    }
}
