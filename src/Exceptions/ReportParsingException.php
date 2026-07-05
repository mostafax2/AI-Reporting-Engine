<?php

declare(strict_types=1);

namespace Mostafax\AiReportingEngine\Exceptions;

use RuntimeException;

/**
 * Thrown when no parser (regex or OpenAI) can turn the prompt into a valid query.
 */
final class ReportParsingException extends RuntimeException
{
    public static function unrecognized(?string $message = null): self
    {
        return new self($message ?? 'Unable to understand the request.');
    }
}
