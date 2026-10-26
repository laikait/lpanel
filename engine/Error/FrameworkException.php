<?php

declare(strict_types=1);

namespace App\Engine\Error;

/**
 * Base class for every exception the engine raises on its own behalf.
 *
 * Catching this catches "the framework said no" without also catching
 * application errors. Engine exceptions that must implement a PSR interface
 * (the container ones) are the documented exceptions to this rule.
 *
 * Each one also says whether its message was written entirely by this
 * framework. Nearly all of them were -- "Command name X is already registered
 * by Y" contains nothing but names somebody typed -- and those are worth
 * showing to an operator at a terminal even in production, because the
 * alternative is a person staring at "Internal Server Error" with no way
 * forward.
 *
 * A few wrap a message from somewhere else: PDO's connection failure carries
 * the DSN, a template engine's compile error carries a path. Those call
 * withheld() and are replaced outside debug mode. Nothing here is ever shown to
 * a web client regardless -- see ErrorContext for why the audience decides.
 */
class FrameworkException extends \RuntimeException
{
    private bool $disclosesMessage = true;

    /**
     * Whether this message is safe to repeat outside debug mode.
     *
     * "Safe" means the framework wrote all of it. It does not mean harmless to
     * everybody: a framework-authored message may still name a file or a module,
     * which is why only the console audience acts on this.
     */
    public function disclosesMessage(): bool
    {
        return $this->disclosesMessage;
    }

    /**
     * Mark this message as one that must not be repeated.
     *
     * Called by any factory that interpolates something it did not write --
     * most often $previous->getMessage(). An architecture test checks that
     * every factory doing so calls this, because the cost of forgetting is a
     * credential in a log file rather than a failing test.
     *
     * It mutates rather than returning a copy, which is the one place this
     * codebase does that. A PHP exception cannot be cloned at all -- clone on a
     * Throwable is a fatal Error -- and the alternative, a constructor argument
     * threaded through every factory in thirteen classes, would be easy to
     * forget in exactly the case that matters. The object is still under
     * construction when this is called and nobody else has seen it.
     */
    public function withheld(): static
    {
        $this->disclosesMessage = false;

        return $this;
    }
}
