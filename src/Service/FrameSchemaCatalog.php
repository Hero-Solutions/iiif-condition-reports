<?php

declare(strict_types=1);

namespace App\Service;

final class FrameSchemaCatalog
{
    /** @var array<string, array{side: string, label: string, path: string}> */
    private const SCHEMAS = [
        'rectangle_portrait_front' => ['side' => 'front', 'label' => 'reports.annotation_frame_rectangle_portrait', 'path' => '/annotation_images/rectangle_portrait_front.svg'],
        'rectangle_landscape_front' => ['side' => 'front', 'label' => 'reports.annotation_frame_rectangle_landscape', 'path' => '/annotation_images/rectangle_landscape_front.svg'],
        'square_front' => ['side' => 'front', 'label' => 'reports.annotation_frame_square', 'path' => '/annotation_images/square_front.svg'],
        'oval_portrait_front' => ['side' => 'front', 'label' => 'reports.annotation_frame_oval_portrait', 'path' => '/annotation_images/oval_portrait_front.svg'],
        'oval_landscape_front' => ['side' => 'front', 'label' => 'reports.annotation_frame_oval_landscape', 'path' => '/annotation_images/oval_landscape_front.svg'],
        'circle_front' => ['side' => 'front', 'label' => 'reports.annotation_frame_circle', 'path' => '/annotation_images/circle_front.svg'],
        'diamond_front' => ['side' => 'front', 'label' => 'reports.annotation_frame_diamond', 'path' => '/annotation_images/diamond_front.svg'],
        'rectangle_portrait_back' => ['side' => 'back', 'label' => 'reports.annotation_frame_rectangle_portrait', 'path' => '/annotation_images/rectangle_portrait_back.svg'],
        'rectangle_landscape_back' => ['side' => 'back', 'label' => 'reports.annotation_frame_rectangle_landscape', 'path' => '/annotation_images/rectangle_landscape_back.svg'],
        'square_back' => ['side' => 'back', 'label' => 'reports.annotation_frame_square', 'path' => '/annotation_images/square_back.svg'],
        'oval_portrait_back' => ['side' => 'back', 'label' => 'reports.annotation_frame_oval_portrait', 'path' => '/annotation_images/oval_portrait_back.svg'],
        'oval_landscape_back' => ['side' => 'back', 'label' => 'reports.annotation_frame_oval_landscape', 'path' => '/annotation_images/oval_landscape_back.svg'],
        'circle_back' => ['side' => 'back', 'label' => 'reports.annotation_frame_circle', 'path' => '/annotation_images/circle_back.svg'],
        'diamond_back' => ['side' => 'back', 'label' => 'reports.annotation_frame_diamond', 'path' => '/annotation_images/diamond_back.svg'],
    ];

    public function __construct(
        private readonly string $projectDirectory,
    ) {
    }

    /** @return array<string, array{side: string, label: string, path: string}> */
    public function all(): array
    {
        return self::SCHEMAS;
    }

    /** @return array{side: string, label: string, path: string}|null */
    public function get(string $key): ?array
    {
        return self::SCHEMAS[$key] ?? null;
    }

    /** @return array{key: string, side: string, label: string, path: string}|null */
    public function findByPath(string $path): ?array
    {
        foreach (self::SCHEMAS as $key => $schema) {
            if ($schema['path'] === $path) {
                return ['key' => $key, ...$schema];
            }
        }

        return null;
    }

    public function dataUri(string $path): ?string
    {
        if ($this->findByPath($path) === null) {
            return null;
        }

        $file = $this->projectDirectory . DIRECTORY_SEPARATOR . 'public'
            . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $contents = is_file($file) ? file_get_contents($file) : false;

        return $contents === false
            ? null
            : 'data:image/svg+xml;base64,' . base64_encode($contents);
    }
}
