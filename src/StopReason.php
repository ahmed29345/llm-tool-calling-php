<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling;

enum StopReason: string
{
    /** The model replied without requesting tools. */
    case Completed = 'completed';

    /** The step limit was reached while the model was still calling tools. */
    case MaxStepsReached = 'max_steps_reached';
}
