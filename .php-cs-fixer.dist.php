<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/engine',
        __DIR__ . '/tests',
    ])
    ->append([__FILE__]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setRules([
        '@PER-CS2.0' => true,
        'declare_strict_types' => true,
        // @internal means "defined in the PHP running the fixer", so a function
        // from an extension or SAPI that is missing on one machine gets its
        // backslash added on one and stripped on the other. The functions below
        // are only ever called behind function_exists(), and naming them here
        // makes them always prefixed, wherever the fixer runs (opcache is on in
        // CI and off under XAMPP, for one).
        'native_function_invocation' => [
            'include' => ['@internal', 'fastcgi_finish_request', 'getallheaders', 'opcache_get_status', 'posix_geteuid', 'posix_getgrnam', 'posix_getpwnam', 'posix_getpwuid', 'posix_kill'],
            'scope' => 'namespaced',
            'strict' => true,
        ],
        'global_namespace_import' => [
            'import_classes' => false,
            'import_constants' => false,
            'import_functions' => false,
        ],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'void_return' => true,
    ]);
