<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling\Chat;

use Ahmed29345\LlmToolCalling\Exception\ProviderException;
use Ahmed29345\LlmToolCalling\Tool;

/**
 * Sends one chat request to a model and returns its reply.
 */
interface ChatProvider
{
    /**
     * @param list<Message> $messages The conversation so far
     * @param list<Tool>    $tools    Tools the model may call; already filtered for the actor
     *
     * @throws ProviderException
     */
    public function chat(array $messages, array $tools): ChatResponse;
}
