<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\ReportTitleFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class ReportExtension extends AbstractExtension
{
    public function __construct(private readonly ReportTitleFormatter $titles)
    {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('report_title', $this->titles->format(...))];
    }
}
