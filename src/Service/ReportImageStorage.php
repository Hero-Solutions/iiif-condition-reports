<?php

declare(strict_types=1);

namespace App\Service;

final class ReportImageStorage extends ObjectImageStorage
{
    public function __construct(
        string $uploadDirectory,
        string $projectDirectory,
        ?string $caFile = null,
    ) {
        parent::__construct(
            $uploadDirectory,
            $projectDirectory,
            $caFile,
            '/uploads/reports/images/',
            52_428_800,
        );
    }
}
