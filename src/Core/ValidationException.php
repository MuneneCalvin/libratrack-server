<?php

declare(strict_types=1);

namespace LibraTrack\Core;

final class ValidationException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode = 400)
    {
        parent::__construct($message);
    }
}
