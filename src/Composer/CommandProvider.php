<?php

namespace Grasmash\Artifice\Composer;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

/**
 * @codeCoverageIgnore
 */
class CommandProvider implements CommandProviderCapability
{
    public function getCommands()
    {
        return array(new GenerateArtifactCommand());
    }
}
