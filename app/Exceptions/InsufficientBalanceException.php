<?php

namespace App\Exceptions;

use Exception;

class InsufficientBalanceException extends Exception
{
    /**
     * Create a new exception instance.
     *
     * @param string $message
     * @param int $code
     * @param Exception|null $previous
     * @return void
     */
    public function __construct($message = "Insufficient wallet balance to perform this transaction.", $code = 402, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
