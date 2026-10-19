<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\Exception\FileException;

final class AnnotationRotation
{
    /** Rotate image coordinates by a quarter turn, including erased polygon holes. */
    public function rotate(array $target, int $imageWidth, int $imageHeight, int $degrees = 90): array
    {
        if (!in_array($degrees, [-90, 90], true)) {
            throw new FileException('The annotation rotation is not supported.');
        }
        $dimensions = $target['sourceDimensions'] ?? ['width' => $imageWidth, 'height' => $imageHeight];
        $width = $this->number($dimensions['width'] ?? null);
        $height = $this->number($dimensions['height'] ?? null);
        if ($width <= 0 || $height <= 0) {
            throw new FileException('The annotation dimensions are invalid.');
        }
        $clockwise = $degrees === 90;
        $extent = $clockwise ? $height : $width;

        $selector = $target['selector'] ?? [];
        $geometry = $selector['geometry'] ?? [];
        switch ($selector['type'] ?? '') {
            case 'ELLIPSE':
                [$geometry['cx'], $geometry['cy']] = $this->point(
                    [$geometry['cx'] ?? null, $geometry['cy'] ?? null], $extent, $clockwise,
                );
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
                $geometry['points'] = $this->points($geometry['points'] ?? [], $extent, $clockwise);
                $geometry['bounds'] = $this->bounds($geometry['points']);
                break;

            case 'MULTIPOLYGON':
                $allPoints = [];
                foreach ($geometry['polygons'] ?? [] as $polygonIndex => $polygon) {
                    $polygonPoints = [];
                    foreach ($polygon['rings'] ?? [] as $ringIndex => $ring) {
                        $ring['points'] = $this->points($ring['points'] ?? [], $extent, $clockwise);
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

    private function points(array $points, float $extent, bool $clockwise): array
    {
        return array_map(fn (array $point): array => $this->point($point, $extent, $clockwise), $points);
    }

    private function point(array $point, float $extent, bool $clockwise): array
    {
        $x = $this->number($point[0] ?? null);
        $y = $this->number($point[1] ?? null);

        return $clockwise ? [$extent - $y, $x] : [$y, $extent - $x];
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
