<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ObjectRecord;

final class ReportMeasurementDefaults
{
    /** @return array<string, mixed> */
    public function for(ObjectRecord $object): array
    {
        $data = [];
        foreach ($object->getSourceData()['measurements'] ?? [] as $extent => $measurements) {
            $extent = mb_strtolower((string) $extent);
            $suffixes = match ($object->getObjectType()) {
                ObjectRecord::TYPE_PAINTING => match ($extent) {
                    'inclusief lijst' => ['_full', '_with_frame'],
                    'volledig' => ['_without_frame'],
                    default => [],
                },
                ObjectRecord::TYPE_WORK_ON_PAPER => match ($extent) {
                    'inclusief lijst' => ['_with_frame'],
                    'volledig' => ['_without_frame'],
                    default => [],
                },
                default => in_array($extent, ['', 'volledig'], true) ? ['_full', ''] : [],
            };

            foreach ($suffixes as $suffix) {
                foreach ($measurements as $axis => $value) {
                    if (!in_array($axis, ['height', 'width', 'depth', 'weight'], true)) {
                        continue;
                    }
                    if ($object->getObjectType() === ObjectRecord::TYPE_WORK_ON_PAPER && $suffix === '_without_frame' && !in_array($axis, ['height', 'width'], true)) {
                        continue;
                    }

                    if ($suffix === '_full') {
                        $data[$axis . $suffix] = $value;
                    } else {
                        $data['measurement_parts'][1][$axis . $suffix] = $value;
                    }
                }
            }
        }

        if ($data !== []) {
            $data['number_of_parts'] = '1';
        }

        return $data;
    }
}
