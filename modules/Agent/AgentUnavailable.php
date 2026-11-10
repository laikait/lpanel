<?php

declare(strict_types=1);

namespace App\Modules\Agent;

/**
 * The agent could not be asked: no socket, no key, no answer. Different from
 * the agent answering "no", which is an AgentReply with an error code.
 */
final class AgentUnavailable extends \RuntimeException {}
