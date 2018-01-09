<?php

namespace Grasmash\Artifice\Commands;

/**
 * @file
 *   Set up local Drush configuration.
 */

use Consolidation\AnnotatedCommand\CommandData;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Input\InputOption;

class GenerateArtifactCommand extends DrushCommands
{
  /**
   * Generate a deployment artifact.
   *
   * @command generate:artifact
   * @aliases ga
   */
  public function generateArtifact($options = [
    'branch' => InputOption::VALUE_REQUIRED,
    'tag' => InputOption::VALUE_REQUIRED,
    'commit-msg' => InputOption::VALUE_REQUIRED,
    'ignore-dirty' => FALSE,
    'dry-run' => FALSE,
  ]) {
    $this->say("Hello world!");
  }

  /**
   * @hook validate generate:artifact
   */
  public function validateGitRequirements(CommandData $commandData)
  {
    if (!$this->commandExists('git')) {
      throw new \Exception("git is missing from your system!");
    }
    if (!$this->isGitMinimumVersionSatisfied('2.0')) {
      $this->logger->error("Please update git to 2.0 or newer.");
    }
  }

  /**
   * Checks if a given command exists on the system.
   *
   * @param string $command
   *   The command binary only. E.g., "drush" or "php".
   *
   * @return bool
   *   TRUE if the command exists, otherwise FALSE.
   */
  public function commandExists($command) {
    exec("command -v $command >/dev/null 2>&1", $output, $exit_code);
    return $exit_code == 0;
  }

  /**
   * Verifies that installed minimum git version is met.
   *
   * @param string $minimum_version
   *   The minimum git version that is required.
   *
   * @return bool
   *   TRUE if minimum version is satisfied.
   */
  public function isGitMinimumVersionSatisfied($minimum_version) {
    exec("git --version | cut -d' ' -f3", $output, $exit_code);
    if (version_compare($output[0], $minimum_version, '>=')) {
      return TRUE;
    }
    return FALSE;
  }

}
