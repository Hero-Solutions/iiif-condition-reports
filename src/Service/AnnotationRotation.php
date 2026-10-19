<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\Exception\FileException;

final class AnnotationRotation
{
    /** Rotate image coordinates 90 degrees clockwise, including erased polygon holes. */
    public function clockwise(array $target, int $imageWidth, int $imageHeight): array
    {
        $dimensions = $target['sourceDimensions'] ?? ['width' => $imageWidth, 'height' => $imageHeight];
        $width = $this->number($dimensions['width'] ?? null);
        $height = $this->number($dimensions['height'] ?? null);
        if ($width <= 0 || $height <= 0) {
            throw new FileException('The annotation dimensions are invalid.');
        }

        $selector = $target['selector'] ?? [];
        $geometry = $selector['geometry'] ?? [];
        switch ($selector['type'] ?? '') {
            case 'ELLIPSE':
                [$geometry['cx'], $geometry['cy']] = [
                    $height - $this->number($geometry['cy'] ?? null),
                    $this->number($geometry['cx'] ?? null),
                ];
                [$geometry['rx'], $geometry['ry']] = [
                    $this->number($geometry['ry'] ?? null),
                    $this->number($geometry['rx'] ?? null),
                ];
                $geometry['bounds'] = [
                    'minX' => $geometry['cx'] - $geometry['rx'],
                    'minY' => $geometry['cy'] - $geometry['ry'],
                    'maxX' => $geometry['cx'] + $geometry['rx'],
                    'maxY' => $geometry['cy'] + $geometry['ry'],
                ];
                break;

            case 'POLYGON':
                $geometry['points'] = $this->points($geometry['points'] ?? [], $height);
                $geometry['bounds'] = $this->bounds($geometry['points']);
                break;

            case 'MULTIPOLYGON':
                $allPoints = [];
                foreach ($geometry['polygons'] ?? [] as $polygonIndex => $polygon) {
                    $polygonPoints = [];
                    foreach ($polygon['rings'] ?? [] as $ringIndex => $ring) {
                        $ring['points'] = $this->points($ring['points'] ?? [], $height);
                        if (isset($ring['bounds'])) {
                            $ring['bounds'] = $this->bounds($ring['points']);
                        }
                        $polygon['rings'][$ringIndex] = $ring;
                        array_push($polygonPoints, ...$ring['points']);
                    }
                    $polygon['bounds'] = $this->bounds($polygonPoints);
                    $geometry['polygons'][$polygonIndex] = $polygon;
                    array_push($allPoints, ...$polygonPoints);
                }
                $geometry['bounds'] = $this->bounds($allPoints);
                break;

            default:
                throw new FileException('The image could not be rotated because an annotation shape is unsupported.');
        }

        $target['selector']['geometry'] = $geometry;
        $target['sourceDimensions'] = ['width' => $height, 'height' => $width];

        return $target;
    }

    private function points(array $points, float $height): array
    {
        return array_map(fn (array $point): array => [
            $height - $this->number($point[1] ?? null),
            $this->number($point[0] ?? null),
        ], $points);
    }

    private function bounds(array $points): array
    {
        if ($points === []) {
            throw new FileException('The annotation has no coordinates.');
        }

        return [
            'minX' => min(array_column($points, 0)),
            'minY' => min(array_column($points, 1)),
            'maxX' => max(array_column($points, 0)),
            'maxY' => max(array_column($points, 1)),
        ];
    }

    private function number(mixed $value): float
    {
        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw new FileException('The annotation coordinates are invalid.');
        }

        return (float) $value;
    }
}
