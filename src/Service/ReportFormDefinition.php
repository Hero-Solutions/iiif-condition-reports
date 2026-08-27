<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ObjectRecord;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class ReportFormDefinition
{
    private const OBJECT_TYPES = ['painting', 'work_on_paper', 'sculpture'];

    private const SECTION_KEYS = ['materials', 'condition', 'recommendations'];

    public function __construct(
        private readonly ParameterBagInterface $parameters,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function forObject(ObjectRecord $object): array
    {
        $objectType = $this->objectType($object);
        $fields = $this->parameter('report_fields');
        $sectionsByType = $this->parameter('report_compact_sections');
        $configuredSections = $sectionsByType[$objectType] ?? $sectionsByType['default'] ?? [];
        $sections = [];

        foreach (self::SECTION_KEYS as $sectionKey) {
            $sections[$sectionKey] = $this->section(
                $sectionKey,
                $configuredSections[$sectionKey] ?? [],
            );
        }

        return [
            'object_type' => $objectType,
            'number_of_parts' => $this->forType($fields['number_of_parts'] ?? [], $objectType),
            'measurements' => $this->forType($fields['measurements'] ?? [], $objectType),
            'damage_types' => $this->damageTypes($fields['damage_types'] ?? [], $objectType),
            'sections' => $sections,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function reasonChoices(): array
    {
        $choices = [];

        foreach ($this->parameter('report_reasons') as $group) {
            if (!is_array($group)) {
                continue;
            }

            foreach ($group['options'] ?? [] as $value => $label) {
                if (is_string($label) && $label !== '') {
                    $choices[$label] = (string) $value;
                }
            }
        }

        return $choices;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{key: string, blocks: list<array<string, mixed>>}
     */
    private function section(string $key, array $config): array
    {
        $blocks = [];

        foreach ($config['blocks'] ?? [] as $block) {
            if (!is_array($block)) {
                continue;
            }

            $prepared = [
                'id' => (string) ($block['id'] ?? ''),
                'title' => (string) ($block['title'] ?? 'reports.section'),
                'groups' => [],
            ];

            foreach ($block['groups'] ?? [] as $group) {
                if (is_array($group)) {
                    $prepared['groups'][] = $this->group($group);
                }
            }

            if (isset($block['presence']) && is_array($block['presence'])) {
                $prepared['presence'] = [
                    'present' => $this->presenceField(
                        (string) ($block['presence']['present'] ?? ''),
                        'reports.present',
                    ),
                    'absent' => $this->presenceField(
                        (string) ($block['presence']['absent'] ?? ''),
                        'reports.absent',
                    ),
                ];
            }

            $blocks[] = $prepared;
        }

        return ['key' => $key, 'blocks' => $blocks];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function group(array $config): array
    {
        $control = (string) ($config['control'] ?? 'search');
        $group = [
            'id' => (string) ($config['id'] ?? ''),
            'title' => (string) ($config['title'] ?? 'reports.section'),
            'heading' => $control === 'heading',
            'quick_select' => in_array($control, ['single', 'multiple'], true),
            'fields' => $this->choiceFields($config),
        ];

        if ($control === 'single') {
            $group['fields'] = $this->withSelectionGroup(
                $group['fields'],
                $group['id'] . '-choice',
            );
        } elseif ($control === 'multiple') {
            $group['fields'] = $this->withConfiguredSelectionGroups(
                $group['fields'],
                (string) ($config['prefix'] ?? ''),
                $config['radio_groups'] ?? [],
                $group['id'],
            );
        }

        if ($control === 'treatment') {
            $prefix = (string) ($config['prefix'] ?? '');
            $baseName = rtrim($prefix, '_');
            $dateName = (string) ($config['date_name'] ?? $baseName . '_happened_radio_happened_date');
            $statusFields = $this->choiceFields([
                'prefix' => $prefix,
                'choices' => [
                    'necessary_necessary' => 'Necessary',
                    'necessary_not_necessary' => 'Not necessary',
                    'happened_happened' => 'Happened',
                    'happened_not_happened_yet' => 'Not happened yet',
                ],
            ]);

            foreach ($statusFields as &$field) {
                $isNecessity = str_starts_with($field['name'], $prefix . 'necessary_');
                $field['selection_group'] = $group['id'] . ($isNecessity ? '-necessity' : '-occurrence');

                if ($field['name'] === $prefix . 'happened_happened') {
                    $field['treatment_happened'] = true;
                    $field['selection_value_name'] = $dateName;
                }
            }
            unset($field);

            $group['treatment'] = [
                'status_fields' => $statusFields,
                'happened_name' => $prefix . 'happened_happened',
                'date_name' => $dateName,
                'hours_name' => (string) ($config['hours_name'] ?? $baseName),
                'detail_name' => (string) ($config['detail_name'] ?? $baseName . '_details'),
            ];
            $group['fields'] = [];
        } elseif ($control === 'condition') {
            $prefix = (string) ($config['prefix'] ?? '');
            $revealIssueNames = array_map(
                static fn (mixed $suffix): string => $prefix . (string) $suffix,
                $config['reveal_issues_after'] ?? [],
            );
            $ratingFields = $this->choiceFields([
                'prefix' => $prefix,
                'choices' => $config['rating'] ?? [],
            ]);
            $ratingGroups = $config['rating_radio_groups'] ?? [
                'rating' => array_keys($config['rating'] ?? []),
            ];
            $ratingFields = $this->withConfiguredSelectionGroups(
                $ratingFields,
                $prefix,
                $ratingGroups,
                $group['id'],
            );

            foreach ($ratingFields as &$field) {
                $field['reveals_issues'] = in_array($field['name'], $revealIssueNames, true);
            }
            unset($field);

            $group['condition'] = true;
            $group['rating_fields'] = $ratingFields;
            $group['issues_conditional'] = $revealIssueNames !== [];
            $group['fields'] = $this->withCustomSlots(
                $this->choiceFields([
                    'prefix' => $prefix,
                    'choices' => $config['issues'] ?? [],
                ]),
                $group['id'],
                max(0, (int) ($config['custom_slots'] ?? 0)),
            );
        } elseif ($control === 'input') {
            $group['fields'] = [];
            $group['direct_input'] = $config['input'] ?? [];
        } elseif ($control === 'search') {
            $group['fields'] = $this->withCustomSlots(
                $group['fields'],
                $group['id'],
                max(0, (int) ($config['custom_slots'] ?? 0)),
            );
        }

        return $group;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<array<string, mixed>>
     */
    private function choiceFields(array $config): array
    {
        $prefix = (string) ($config['prefix'] ?? '');
        $fields = [];

        foreach ($config['choices'] ?? [] as $suffix => $choice) {
            $name = $prefix . (string) $suffix;
            $type = 'checkbox';
            $label = $choice;
            $openCustom = false;

            if (is_array($choice)) {
                $choiceType = (string) ($choice['type'] ?? 'choice');
                $label = $choice['label'] ?? 'reports.other';

                if ($choiceType === 'custom') {
                    $type = 'checkbox_custom';
                    $openCustom = true;
                } elseif ($choiceType === 'detail') {
                    $type = 'checkbox_custom';
                }
            }

            if ($name === '' || !is_string($label) || $label === '') {
                continue;
            }

            $fields[] = [
                'name' => $name,
                'type' => $type,
                'label' => $label,
                'open_custom' => $openCustom,
            ];
        }

        return $fields;
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return list<array<string, mixed>>
     */
    private function withSelectionGroup(array $fields, string $selectionGroup): array
    {
        foreach ($fields as &$field) {
            $field['selection_group'] = $selectionGroup;
        }
        unset($field);

        return $fields;
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed>       $configuredGroups
     *
     * @return list<array<string, mixed>>
     */
    private function withConfiguredSelectionGroups(
        array $fields,
        string $prefix,
        array $configuredGroups,
        string $groupId,
    ): array {
        $selectionGroupsByName = [];

        foreach ($configuredGroups as $key => $suffixes) {
            if (!is_array($suffixes)) {
                continue;
            }

            foreach ($suffixes as $suffix) {
                $selectionGroupsByName[$prefix . (string) $suffix] = $groupId . '-' . (string) $key;
            }
        }

        foreach ($fields as &$field) {
            if (isset($selectionGroupsByName[$field['name']])) {
                $field['selection_group'] = $selectionGroupsByName[$field['name']];
            }
        }
        unset($field);

        return $fields;
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return list<array<string, mixed>>
     */
    private function withCustomSlots(array $fields, string $groupId, int $slotCount): array
    {
        $openCount = count(array_filter(
            $fields,
            static fn (array $field): bool => ($field['open_custom'] ?? false) === true,
        ));
        $slotIndex = 1;

        while ($openCount < $slotCount) {
            $name = '__custom_' . $groupId . ($slotIndex === 1 ? '' : '_' . $slotIndex);
            ++$slotIndex;

            if (array_any($fields, static fn (array $field): bool => $field['name'] === $name)) {
                continue;
            }

            $fields[] = [
                'name' => $name,
                'type' => 'custom',
                'label' => 'reports.other',
                'open_custom' => true,
            ];
            ++$openCount;
        }

        return $fields;
    }

    /**
     * @return array{name: string, type: string, label: string, open_custom: false}
     */
    private function presenceField(string $name, string $label): array
    {
        return [
            'name' => $name,
            'type' => 'checkbox',
            'label' => $label,
            'open_custom' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parameter(string $name): array
    {
        $value = $this->parameters->get($name);

        if (!is_array($value)) {
            throw new \RuntimeException(sprintf('Rapportconfiguratie "%s" ontbreekt.', $name));
        }

        return $value;
    }

    private function objectType(ObjectRecord $object): string
    {
        $objectType = $object->getObjectType();

        return in_array($objectType, self::OBJECT_TYPES, true) ? $objectType : 'default';
    }

    private function forType(array $definition, string $objectType): mixed
    {
        return $definition[$objectType] ?? $definition['default'] ?? [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function damageTypes(array $damageTypes, string $objectType): array
    {
        if ($objectType === 'default') {
            return $damageTypes;
        }

        return array_filter(
            $damageTypes,
            static fn (array $damageType): bool => in_array($objectType, $damageType['applies_to'] ?? [], true),
        );
    }
}
