<?php
declare(strict_types=1);

/** Fehler eines API-Aufrufs: HTTP-Status und Meldung (für REST direkt, für MCP als Tool-Fehler). */
final class ApiError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message, $status);
    }
}
