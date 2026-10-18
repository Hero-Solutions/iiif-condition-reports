<?php

declare(strict_types=1);

namespace App\Exception;

/** A controlled API diagnostic, safe to show without response bodies or credentials. */
final class TmsApiException extends \RuntimeException
{
}
