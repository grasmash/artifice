<?php

namespace Grasmash\Artifice\Tests;

use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Webmozart\PathUtil\Path;

abstract class CommandTestBase extends \PHPUnit_Framework_TestCase
{

    /** @var \Grasmash\Artifice\Tests\Application */
    protected $application;

    /** @var \Grasmash\Artifice\Tests\TestableGenerateArtifactCommand $command */
    protected $command;

    /** @var CommandTester */
    protected $commandTester;

    /** @var Filesystem */
    protected $fs;

    /** @var string */
    protected $artificePath;

    /** @var string */
    protected $sandbox;


    /**
     * {@inheritdoc}
     *
     * @see https://symfony.com/doc/current/console.html#testing-commands
     */
    public function setUp()
    {
        parent::setUp();
        $this->application = new Application();
        $this->fs = new Filesystem();
        $this->artificePath = dirname(dirname(dirname(__DIR__)));
        $this->sandbox = $this->makeSandbox();
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
        $process = new Process(
            'composer install' .
            ' && git init' .
            ' && git add -A' .
            ' && git commit -m "' . $this->getDefaultCommitMessage() . '"'
        );
        $process->run();

        return $sandbox;
    }

    protected function getDefaultCommitMessage()
    {
        return "Initial commit.";
    }
}
