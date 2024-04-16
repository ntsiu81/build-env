<?php

namespace BuildEnv\Composer;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

class BuildEnvProvider implements CommandProviderCapability
{
    public function getCommands()
    {
        return [new BuildEnvCommand];
    }
}
