<?php

namespace Grasmash\Artifice\Composer;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Command\BaseCommand;

class GenerateArtifactCommand extends BaseCommand
{
  public function configure()
  {
    $this->setName('generate-artifact');
    $this->setDescription("Generate an deployment-ready artifact for a Drupal application.");
    $this->addOption(
      'branch',
      null,
      InputOption::VALUE_REQUIRED,
      "The name of the git branch for the artifact. The tag is cut from this branch."
    );
    $this->addOption(
      'tag',
      null,
      InputOption::VALUE_REQUIRED,
      "The name of the git tag for the artifact. E.g., 1.0.0."
    );
    $this->addOption(
      'commit-msg',
      null,
      InputOption::VALUE_REQUIRED,
      "The git commit message for the artifact commit."
    );
    $this->addOption(
      'ignore-dirty',
      null,
      InputOption::VALUE_NONE,
      "Ignore an unclean local git repository with uncommitted changes."
    );
    $this->addOption(
      'dry-run',
      null,
      InputOption::VALUE_NONE,
      "Generate artifact without pushing it upstream."
    );
  }

  public function execute(InputInterface $input, OutputInterface $output)
  {
    $output->writeln('Executing...');
  }
}