<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'report_main_images')]
class ReportMainImage
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Report $report;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ObjectImageVersion $imageVersion;

    public function __construct(Report $report, ObjectImageVersion $imageVersion)
    {
        if ($report->getObjectRecord() !== $imageVersion->getObjectRecord()) {
            throw new \InvalidArgumentException('The image must belong to the report object.');
        }

        $this->report = $report;
        $this->imageVersion = $imageVersion;
    }

    public function getId(): ?int { return $this->id; }
    public function getReport(): Report { return $this->report; }
    public function getImageVersion(): ObjectImageVersion { return $this->imageVersion; }
}
