<?php

declare(strict_types=1);

namespace App\Value;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class Pagination
{
    public const PER_PAGE = 50;

    public int $page;
    public int $pages;
    public int $offset;
    public int $first;
    public int $last;

    public function __construct(public int $total, int $page = 1)
    {
        $this->pages = max(1, (int) ceil($total / self::PER_PAGE));
        $this->page = max(1, min($page, $this->pages));
        $this->offset = ($this->page - 1) * self::PER_PAGE;
        $this->first = $total === 0 ? 0 : $this->offset + 1;
        $this->last = min($this->offset + self::PER_PAGE, $total);
    }
}
