<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ObjectRecord;
use App\Entity\Project;

final class ProjectReportDefaults
{
    private const SUPPORTED_OBJECT_TYPES = [
        ObjectRecord::TYPE_PAINTING,
        ObjectRecord::TYPE_WORK_ON_PAPER,
        ObjectRecord::TYPE_SCULPTURE,
    ];

    private const ACCLIMATIZATION_CUSTOM_FIELD = '__custom_recommendations_environmental_and_climatic_conditions_during_storage_transport_and_presentations_how_long_to_acclimatize_before_unpacking_6';

    /**
     * @return array<string, string>
     */
    public function for(Project $project, ObjectRecord $object): array
    {
        $objectType = $object->getObjectType();

        if ($project->getType() !== Project::TYPE_LOAN || !in_array($objectType, self::SUPPORTED_OBJECT_TYPES, true)) {
            return [];
        }

        $conditions = $project->getEnvironmentalConditions();
        $prefix = 'recommendations_' . $objectType . '_environmental_climatic_conditions_';
        $data = [];
        $fields = [
            Project::ENVIRONMENT_TEMPERATURE => 'temp',
            Project::ENVIRONMENT_RELATIVE_HUMIDITY => 'rh',
            Project::ENVIRONMENT_LIGHT => 'lux',
            Project::ENVIRONMENT_UV => 'uv',
            Project::ENVIRONMENT_EXHIBITION_DURATION => 'exhibition_duration',
        ];

        foreach ($fields as $conditionKey => $fieldSuffix) {
            $value = $conditions[$conditionKey] ?? '';

            if ($value !== '') {
                $data[$prefix . $fieldSuffix] = $value;
            }
        }

        $acclimatization = $conditions[Project::ENVIRONMENT_ACCLIMATIZATION] ?? '';

        if ($acclimatization !== '') {
            $normalized = mb_strtolower(preg_replace('/\s+/', ' ', trim($acclimatization)) ?? '');

            if (in_array($normalized, ['24h', '24 h'], true)) {
                $data[$prefix . 'acclimatize_24h'] = '1';
            } else {
                $data[self::ACCLIMATIZATION_CUSTOM_FIELD] = $acclimatization;
            }
        }

        return $data;
    }
}
