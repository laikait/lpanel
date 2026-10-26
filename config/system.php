<?php

declare(strict_types=1);

/*
 * engine/System is off for LPanel (design/adr/0005-lpanel-disables-engine-system.md).
 *
 * The panel never changes the machine itself: every system mutation is an
 * operation LPA performs, and root's password is checked by lpanel-authd. With
 * this off, every System manager refuses to be built, so a bug in a request
 * cannot reach a ready-made command runner. tests/Feature/SystemDisabledTest
 * keeps it that way.
 */
return [
    'enabled' => false,
];
