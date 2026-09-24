<?php

namespace App\Exceptions;

use RuntimeException;

class AccountingPeriodException extends RuntimeException
{
    public static function noOpenPeriod(): self
    {
        return new self('No open accounting period exists for the invoice date.');
    }
}
