<?php

namespace Grasmash\Artifice\Tests;

use Composer\IO\NullIO;
use function dirname;
use Grasmash\Artifice\Composer\GenerateArtifactCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Webmozart\PathUtil\Path;

abstract class CommandTestBase extends \PHPUnit_Framework_TestCase
{

  /** @var Application */
    protected $application;

  /** @var GenerateArtifactCommand $command */
    protected $command;

  /** @var CommandTester */
    protected $commandTester;

  /** @var Filesystem */
    protected $fs;

  /** @var String */
    protected $artificePath;

  /**
   * {@inheritdoc}
   *
   * @see https://symfony.com/doc/current/console.html#testing-commands
   */
    public function setUp()
    {
        parent::setUp();
        $this->application = new Application();
        $this->application->add(new GenerateArtifactCommand());
        $io = new NullIO();
        $this->application->setIo($io);
        $this->command = $this->application->find('generate-artifact');
        $this->commandTester = new CommandTester($this->command);
        $this->fs = new Filesystem();
        $this->artificePath = dirname(dirname(dirname(__DIR__)));
    }

  /**
   * Destroy and re-create sandbox directory for testing.
   *
   * Sandbox is a mirror of tests/fixtures/sandbox, located in a temp dir.
   *
   * @return bool|string
   */
    protected function makeSandbox()
    {
        $tmp = getenv('ARTIFICE_TMP') ?: sys_get_temp_dir();
        $sandbox = Path::canonicalize($tmp . "/artifice-sandbox");
        $this->fs->remove([$sandbox]);
        $this->fs->mkdir([$sandbox]);
        $sandbox = realpath($sandbox);
        $sandbox_master = Path::canonicalize($this->artificePath . "/tests/fixtures/sandbox");
        $this->fs->mirror($sandbox_master, $sandbox);
        $composer_json = json_decode(file_get_contents($sandbox . "/composer.json"));
        $composer_json->repositories->artifice->url = $this->artificePath;
        $this->fs->dumpFile($sandbox . "/composer.json", json_encode($composer_json));
        chdir($sandbox);
        $process = new Process('git init && git add -A && git commit -m "Initial commit."');
        $process->run();

        return $sandbox;
    }
}
