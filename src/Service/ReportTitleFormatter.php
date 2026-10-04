<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Report;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ReportTitleFormatter
{
    public function __construct(
        private readonly ReportFormDefinition $formDefinition,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function format(Report $report): string
    {
        $reason = $this->reason($report) ?? $this->translator->trans('reports.default_title');
        $date = $report->getStartedAt() ?? $report->getCreatedAt();

        return $reason . ' — ' . $date->format('d/m/Y');
    }

    public function reason(Report $report): ?string
    {
        if ($report->getReason() === 'other') {
            return $report->getCustomReason();
        }

        $label = $this->formDefinition->reasonLabel($report->getReason());

        return $label !== null ? $this->translator->trans($label) : null;
    }
}
