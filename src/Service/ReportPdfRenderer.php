<?php

declare(strict_types=1);

namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

final class ReportPdfRenderer
{
    public function __construct(
        private readonly Environment $twig,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(array $context): string
    {
        $options = new Options();
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setIsHtml5ParserEnabled(true);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($this->twig->render('reports/pdf.html.twig', $context), 'UTF-8');
        $dompdf->render();

        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        $dompdf->getCanvas()->page_text(520, 806, '{PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.35, 0.35, 0.35]);

        return $dompdf->output();
    }
}
