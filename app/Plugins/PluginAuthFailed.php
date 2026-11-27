<?php

namespace App\Plugins;

/** GitHub refused the person's token (revoked or expired): they need to connect again. */
final class PluginAuthFailed extends \RuntimeException {}
