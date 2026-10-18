<?php

declare(strict_types=1);

namespace App\Datahub;

use SimpleXMLElement;

final class LidoMeasurements
{
    private const NS = 'http://www.lido-schema.org';
    private const AXES = ['height', 'width', 'depth'];
    private const TYPES = [
        'hoogte' => 'height', 'height' => 'height',
        'breedte' => 'width', 'width' => 'width',
        'diepte' => 'depth', 'depth' => 'depth',
        'diameter' => 'diameter', 'gewicht' => 'weight', 'weight' => 'weight',
    ];

    /** @return array<string, array<string, string>> Lengths in cm, weights in kg, grouped by source extent. */
    public function extract(SimpleXMLElement $record, string $language): array
    {
        $record->registerXPathNamespace('measurement', self::NS);
        $groups = $provided = $fallbacks = $structured = [];

        foreach ($record->xpath('.//measurement:descriptiveMetadata') ?: [] as $metadata) {
            if ((string) $metadata->attributes('http://www.w3.org/XML/1998/namespace')->lang !== $language) {
                continue;
            }

            $metadata->registerXPathNamespace('measurement', self::NS);
            foreach ($metadata->xpath('measurement:objectIdentificationWrap/measurement:objectMeasurementsWrap/measurement:objectMeasurementsSet') ?: [] as $set) {
                $set = $set->children(self::NS);
                $measurements = $set->objectMeasurements;
                $extent = trim((string) $measurements->extentMeasurements);
                foreach ($measurements->measurementsSet ?? [] as $measurement) {
                    $structured[$extent][] = [
                        'type' => mb_strtolower(trim((string) $measurement->measurementType)),
                        'value' => trim((string) $measurement->measurementValue),
                        'unit' => (string) $measurement->measurementUnit,
                    ];
                }

                $fallbacks[$extent] = ($fallbacks[$extent] ?? []) + $this->displayDimensions((string) $set->displayObjectMeasurements);
            }
        }

        foreach ($structured as $extent => $items) {
            // Some exports put each axis in its own outer XML set.
            $count = count(array_filter($items, static fn (array $item): bool => $item['type'] === '' || in_array(self::TYPES[$item['type']] ?? null, self::AXES, true)));
            $position = 0;
            foreach ($items as $item) {
                $axis = self::TYPES[$item['type']] ?? null;
                if ($item['type'] === '' || in_array($axis, self::AXES, true)) {
                    if ($item['type'] === '' && in_array($count, [2, 3], true)) {
                        $axis = self::AXES[$position];
                    }
                    ++$position;
                }
                if ($axis === null || $item['value'] === '' || isset($provided[$extent][$axis])) {
                    continue;
                }

                // First structured value wins, even when the display text disagrees.
                $provided[$extent][$axis] = true;
                $converted = $this->convert($item['value'], $item['unit'], $axis === 'weight');
                if ($converted !== null) {
                    $groups[$extent][$axis] = $converted;
                }
            }
        }

        foreach ($fallbacks as $extent => $values) {
            foreach ($values as $axis => $value) {
                if (!isset($provided[$extent][$axis])) {
                    $groups[$extent][$axis] = $value;
                }
            }
        }

        return $groups;
    }

    /** @return array<string, string> */
    private function displayDimensions(string $display): array
    {
        $number = '\\d+(?:[.,]\\d+)?';
        // Only a plain H × W (× D) with a stated unit; no guesses about prose or units.
        if (!preg_match('/^\\s*(' . $number . ')\\s*[x×]\\s*(' . $number . ')(?:\\s*[x×]\\s*(' . $number . '))?\\s*([a-z]+)\\s*$/iu', $display, $matches)) {
            return [];
        }

        $values = [];
        foreach (self::AXES as $index => $axis) {
            if ($matches[$index + 1] !== '') {
                $value = $this->convert($matches[$index + 1], $matches[4], false);
                if ($value !== null) {
                    $values[$axis] = $value;
                }
            }
        }

        return $values;
    }

    private function convert(string $value, string $unit, bool $weight): ?string
    {
        $value = str_replace(',', '.', trim($value));
        if (!is_numeric($value)) {
            return null;
        }

        $unit = mb_strtolower(trim($unit));
        $factor = $weight ? match ($unit) {
            'kg', 'kilogram', 'kilograms', 'kilogrammen' => 1,
            'g', 'gram', 'grams', 'grammen' => 0.001,
            default => null,
        } : match ($unit) {
            'mm', 'millimeter', 'millimeters', 'millimetre', 'millimetres' => 0.1,
            'cm', 'centimeter', 'centimeters', 'centimetre', 'centimetres' => 1,
            'dm', 'decimeter', 'decimeters', 'decimetre', 'decimetres' => 10,
            'm', 'meter', 'meters', 'metre', 'metres' => 100,
            'in', 'inch', 'inches' => 2.54,
            'ft', 'foot', 'feet' => 30.48,
            default => null,
        };

        if ($factor === null) {
            return null;
        }

        return (string) ((float) $value * $factor);
    }
}
