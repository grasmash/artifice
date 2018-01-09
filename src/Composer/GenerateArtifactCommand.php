<?php

namespace Grasmash\Artifice\Composer;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Command\BaseCommand;

class GenerateArtifactCommand extends BaseCommand
{
  public function configure()
  {
    $this->setName('generate-artifact');
  }

  public function execute(InputInterface $input, OutputInterface $output)
  {
    $output->writeln('Executing...');
  }
}