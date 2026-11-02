<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling;

enum ToolOutcome: string
{
    /** The tool ran and its result was returned to the model. */
    case Succeeded = 'succeeded';

    /** The model asked for a tool that is not registered. */
    case UnknownTool = 'unknown_tool';

    /** The tool exists but the actor may not use it. It was not run. */
    case Forbidden = 'forbidden';

    /** The arguments were not a valid JSON object or missed required fields. It was not run. */
    case InvalidArguments = 'invalid_arguments';

    /** The tool ran and threw. */
    case Failed = 'failed';
}
