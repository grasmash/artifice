<?php

namespace Grasmash\Artifice\Tests;

use Composer\IO\ConsoleIO;
use Grasmash\Artifice\Composer\GenerateArtifactCommand;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TestableGenerateArtifactCommand extends GenerateArtifactCommand {

    /**
     *
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        $helperSet = new HelperSet([ new QuestionHelper() ]);

        /** @var \Grasmash\Artifice\Tests\Application $application */
        $application = $this->getApplication();
        $application->setIo(new ConsoleIO($input, $output, $helperSet));
    }

}
