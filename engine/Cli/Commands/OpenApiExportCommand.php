<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\OpenApi\OpenApiException;
use App\Engine\OpenApi\OpenApiGenerator;

/**
 * The OpenAPI description of every route that asked for one.
 *
 *     php laika openapi:export > openapi.json
 *     php laika openapi:export --file=docs/openapi.json
 *
 * The document goes to stdout and nothing else does, so the redirect above is
 * a valid file; a meta mistake goes to stderr with exit 1, which is what makes
 * this usable as a CI step. Open the file in any OpenAPI viewer -- Swagger
 * Editor, Postman, Insomnia, Scalar -- or hand it to a client generator.
 */
final class OpenApiExportCommand
{
    public function __construct(private readonly OpenApiGenerator $generator) {}

    public function __invoke(Output $output, ?string $file = null, bool $compact = false): int
    {
        try {
            // json() turns a JSON encoding failure into an OpenApiException too.
            $json = $this->generator->json(pretty: !$compact);
        } catch (OpenApiException $e) {
            $output->error($e->getMessage());

            return 1;
        }

        if ($file === null) {
            $output->write($json);

            return 0;
        }

        if (@\file_put_contents($file, $json) === false) {
            $output->error(\sprintf('%s could not be written.', $file));

            return 1;
        }

        $output->success(\sprintf('Wrote %s.', $file));

        return 0;
    }
}
