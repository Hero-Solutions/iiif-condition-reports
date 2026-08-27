<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Report;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ReportDocumentBuilder
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return list<array{title: string, blocks: list<array{title: string, rows: list<array{label: string, values: list<string>}>>}>
     */
    public function sections(Report $report, array $definition): array
    {
        $data = $report->getData();
        $sections = [];
        $measurements = $this->measurementSection($definition, $data);

        if ($measurements !== null) {
            $sections[] = $measurements;
        }

        foreach ([
            'materials' => 'reports.section_materials',
            'condition' => 'reports.section_condition',
            'recommendations' => 'reports.section_recommendations',
        ] as $key => $title) {
            $section = $definition['sections'][$key] ?? null;

            if (!is_array($section)) {
                continue;
            }

            $blocks = $this->blocks($section, $data);

            if ($blocks !== []) {
                $sections[] = [
                    'title' => $this->translator->trans($title),
                    'blocks' => $blocks,
                ];
            }
        }

        return $sections;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $data
     *
     * @return array{title: string, blocks: list<array{title: string, rows: list<array{label: string, values: list<string>}>>}|null
     */
    private function measurementSection(array $definition, array $data): ?array
    {
        $rows = [];
        $numberOfParts = $data['number_of_parts'] ?? null;

        if ($this->hasValue($numberOfParts)) {
            $rows[] = $this->row('reports.number_of_parts', [(string) $numberOfParts]);
        }

        $measurements = $definition['measurements'] ?? [];

        if (is_array($measurements)) {
            foreach ($measurements['items'] ?? [] as $name => $label) {
                if ($this->hasValue($data[$name] ?? null)) {
                    $rows[] = $this->row($label, [(string) $data[$name]]);
                }
            }

            foreach ($measurements['repeat'] ?? [] as $group) {
                if (!is_array($group)) {
                    continue;
                }

                foreach ($group['items'] ?? [] as $name => $label) {
                    if ($this->hasValue($data[$name] ?? null)) {
                        $rows[] = $this->row($label, [(string) $data[$name]]);
                    }
                }
            }
        }

        if ($rows === []) {
            return null;
        }

        return [
            'title' => $this->translator->trans('reports.tab_measurements'),
            'blocks' => [[
                'title' => '',
                'rows' => $rows,
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $section
     * @param array<string, mixed> $data
     *
     * @return list<array{title: string, rows: list<array{label: string, values: list<string>}>>
     */
    private function blocks(array $section, array $data): array
    {
        $blocks = [];

        foreach ($section['blocks'] ?? [] as $block) {
            if (!is_array($block)) {
                continue;
            }

            $rows = [];
            $presence = $this->presence($block, $data);

            if ($presence !== null) {
                $rows[] = $this->row('reports.presence', [$this->translator->trans('reports.' . $presence)]);
            }

            if ($presence !== 'absent') {
                foreach ($block['groups'] ?? [] as $group) {
                    if (!is_array($group) || ($group['heading'] ?? false) === true) {
                        continue;
                    }

                    $values = $this->groupValues($group, $data);

                    if ($values !== []) {
                        $rows[] = $this->row($group['title'] ?? 'reports.section', $values);
                    }
                }
            }

            $note = $data['__note_block_' . ($block['id'] ?? '')] ?? null;

            if ($this->hasValue($note)) {
                $rows[] = $this->row('reports.remarks', [(string) $note]);
            }

            if ($rows !== []) {
                $blocks[] = [
                    'title' => $this->translation($block['title'] ?? ''),
                    'rows' => $rows,
                ];
            }
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $data
     */
    private function presence(array $block, array $data): ?string
    {
        $presence = $block['presence'] ?? null;

        if (!is_array($presence)) {
            return null;
        }

        foreach (['present', 'absent'] as $state) {
            $field = $presence[$state] ?? null;

            if (is_array($field) && $this->fieldSelected($field, $data)) {
                return $state;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $group
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function groupValues(array $group, array $data): array
    {
        $directInput = $group['direct_input'] ?? null;

        if (is_array($directInput)) {
            $value = $data[$directInput['name'] ?? ''] ?? null;

            return $this->hasValue($value) ? [(string) $value] : [];
        }

        $values = [];

        if (($group['condition'] ?? false) === true) {
            $values = array_merge(
                $values,
                $this->selectedValues($group['rating_fields'] ?? [], $data),
            );
        }

        $treatment = $group['treatment'] ?? null;

        if (is_array($treatment)) {
            $values = array_merge($values, $this->selectedValues($treatment['status_fields'] ?? [], $data));
            $date = $data[$treatment['date_name'] ?? ''] ?? null;
            $hours = $data[$treatment['hours_name'] ?? ''] ?? null;
            $details = $data[$treatment['detail_name'] ?? ''] ?? null;

            if ($this->hasValue($date)) {
                $values[] = $this->translator->trans('reports.treatment_date') . ': ' . $date;
            }

            if ($this->hasValue($hours)) {
                $values[] = $this->translator->trans('reports.number_of_hours') . ': ' . $hours;
            }

            if ($this->hasValue($details)) {
                $values[] = $this->translator->trans('reports.further_explanation') . ': ' . $details;
            }

            return $values;
        }

        return array_merge($values, $this->selectedValues($group['fields'] ?? [], $data));
    }

    /**
     * @param mixed                $fields
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function selectedValues(mixed $fields, array $data): array
    {
        if (!is_array($fields)) {
            return [];
        }

        $values = [];

        foreach ($fields as $field) {
            if (!is_array($field) || !$this->fieldSelected($field, $data)) {
                continue;
            }

            $name = (string) ($field['name'] ?? '');
            $customValue = ($field['type'] ?? '') === 'checkbox_custom'
                ? ($data[$name . '_text'] ?? null)
                : ($data[$name] ?? null);

            if (($field['open_custom'] ?? false) === true && $this->hasValue($customValue)) {
                $values[] = (string) $customValue;
                continue;
            }

            $label = $this->translation($field['label'] ?? '');

            if ($label !== '') {
                $values[] = $label;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $data
     */
    private function fieldSelected(array $field, array $data): bool
    {
        $name = (string) ($field['name'] ?? '');

        return $name !== '' && (
            $this->hasValue($data[$name] ?? null)
            || $this->hasValue($data['__enabled_' . $name] ?? null)
            || $this->hasValue($data[$name . '_text'] ?? null)
        );
    }

    /**
     * @param mixed $label
     * @param list<string> $values
     *
     * @return array{label: string, values: list<string>}
     */
    private function row(mixed $label, array $values): array
    {
        return [
            'label' => $this->translation($label),
            'values' => $values,
        ];
    }

    private function translation(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $this->translator->trans($value) : '';
    }

    private function hasValue(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }

        return $value !== null && $value !== false && $value !== '';
    }
}
