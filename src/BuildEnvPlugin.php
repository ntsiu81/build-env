<?php

namespace BuildEnv\Composer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;

class BuildEnvPlugin implements  Capable
{
    public function activate(Composer $composer, IOInterface $io)
    {
    }
    
    public function getCapabilities()
    {
        return ['Composer\Plugin\Capability\CommandProvider' => 'BuildEnv\Composer\BuildEnvProvider'];
    }
}
