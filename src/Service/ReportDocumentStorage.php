<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ReportDocumentStorage
{
    private const PUBLIC_PREFIX = '/uploads/reports/documents/';
    private const MAX_FILE_SIZE = 52_428_800;
    private const ALLOWED_MIME_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/plain' => 'txt',
    ];

    public function __construct(private readonly string $uploadDirectory)
    {
    }

    /** @return array{path: string, mime_type: string, size: int} */
    public function store(UploadedFile $file): array
    {
        $size = (int) ($file->getSize() ?? 0);

        if ($size < 1 || $size > self::MAX_FILE_SIZE) {
            throw new FileException('The document is empty or larger than 50 MB.');
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
        $extension = is_string($mimeType) ? (self::ALLOWED_MIME_TYPES[$mimeType] ?? null) : null;

        if ($extension === null) {
            throw new FileException('This document type is not supported.');
        }

        if (!is_dir($this->uploadDirectory) && !mkdir($this->uploadDirectory, 0775, true) && !is_dir($this->uploadDirectory)) {
            throw new FileException('The document directory could not be created.');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $file->move($this->uploadDirectory, $filename);

        return [
            'path' => self::PUBLIC_PREFIX . $filename,
            'mime_type' => $mimeType,
            'size' => $size,
        ];
    }

    public function remove(string $path): void
    {
        if (!str_starts_with($path, self::PUBLIC_PREFIX)) {
            return;
        }

        $file = $this->uploadDirectory . DIRECTORY_SEPARATOR . basename($path);

        if (is_file($file)) {
            unlink($file);
        }
    }
}
