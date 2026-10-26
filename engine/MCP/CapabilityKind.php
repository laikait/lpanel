<?php

declare(strict_types=1);

namespace App\Engine\MCP;

/** The three things an MCP server offers, as the protocol names them. */
enum CapabilityKind: string
{
    case Tool = 'tool';

    case Resource = 'resource';

    case Prompt = 'prompt';
}
