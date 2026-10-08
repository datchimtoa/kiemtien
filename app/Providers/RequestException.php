<?php
declare(strict_types=1);

namespace App\Providers;

/** Only application-authored messages: never upstream bodies, URLs or credentials. */
final class RequestException extends \RuntimeException
{
}