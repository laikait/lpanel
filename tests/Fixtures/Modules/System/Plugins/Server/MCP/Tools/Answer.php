<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\System\Plugins\Server\MCP\Tools;

use App\Engine\MCP\Tool\ToolResult;
use App\Engine\System\Security\SystemAuthorizationException;
use App\Engine\System\Service\ServiceException;

/**
 * How the server tools turn engine/System's refusals into answers.
 *
 * The same translation ServerEndpoints makes for HTTP: a refusal by the
 * system authorizer or a service policy is the operator's to read, in the
 * framework's own words, so it becomes a tool error the model can see and
 * explain. Anything else is a failure, which ToolRunner reports and answers
 * without detail.
 */
final class Answer
{
    /** @param \Closure(): ToolResult $operation */
    public static function from(\Closure $operation): ToolResult
    {
        try {
            return $operation();
        } catch (SystemAuthorizationException|ServiceException $e) {
            return ToolResult::error($e->getMessage());
        }
    }
}
