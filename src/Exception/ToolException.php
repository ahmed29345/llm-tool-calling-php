<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling\Exception;

use RuntimeException;

/**
 * Thrown by a tool for an error the model should see, such as "no student with that id".
 * The message is sent to the model, so keep it free of internal details.
 */
class ToolException extends RuntimeException
{
}
