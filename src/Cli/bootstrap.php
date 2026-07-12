<?php

/**
 * Auto-registers Muster's WP-CLI commands.
 *
 * Loaded via Composer's `files` autoload so that requiring the package is
 * all a theme needs — `wp capstan muster` and `wp capstan seed` appear with
 * zero wiring. Each register() no-ops outside a WP-CLI context.
 */

\PressGang\Muster\Cli\MusterCommand::register();
\PressGang\Muster\Cli\SeedCommand::register();
