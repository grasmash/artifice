<?php

namespace Grasmash\Artifice\Tests;

use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Webmozart\PathUtil\Path;

class GenerateArtifactCommandTest extends CommandTestBase
{

    protected $sandbox;

    /**
     * {@inheritdoc}
     *
     * @see https://symfony.com/doc/current/console.html#testing-commands
     */
    public function setUp()
    {
        parent::setUp();
        $this->application->add(new TestableGenerateArtifactCommand());
        $this->command = $this->application->find('generate-artifact');
        $this->commandTester = new CommandTester($this->command);
    }

    /**
     * Test that a dirty repo throws a "dirty repo" error.
     */
    public function testDirtyRepo()
    {
        $this->fs->touch([
            Path::canonicalize($this->sandbox . "/dirt.bag")
        ]);
        try {
            $this->commandTester->execute([]);
        } catch (RuntimeException $e) {
            $this->assertContains("There are uncommitted changes", $e->getMessage());
        }

    }

    /**
     * Test that a dirty repo throws a "dirty repo" error.
     */
    public function testDirtyRepoAllowed()
    {
        $this->fs->touch([
            Path::canonicalize($this->sandbox . "/dirt.bag")
        ]);
        $this->commandTester->execute([
            '--allow-dirty' => true,
            '--dry-run' => true,
        ],[
            'interactive' => false,
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    /**
     * Test that a clean repo does not throw a "dirty repo" error.
     */
    public function testCleanRepo()
    {
        $this->makeSandbox();
        // @todo Make this a dry run and as low impact as possible.
        $this->commandTester->execute([
            '--dry-run' => true,
        ], [
            'interactive' => false,
        ]);
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testGetLastCommitMessage() {

    }

    public function testCreateTagQuestion() {
        $this->makeSandbox();
        $this->commandTester->execute([]);
        $this->assertContains("Would you like to create a tag", $this->commandTester->getDisplay(TRUE));
    }
}
