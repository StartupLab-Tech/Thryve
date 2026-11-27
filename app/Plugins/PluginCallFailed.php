<?php

namespace App\Plugins;

/** The outside app did not answer properly. The message is short and safe to show. */
final class PluginCallFailed extends \RuntimeException {}
