<?php

namespace Grasmash\Artifice\Tests;

use Composer\IO\BufferIO;
use Exception;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Webmozart\PathUtil\Path;

class GenerateArtifactCommandTest extends CommandTestBase
{

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

    public function testComposerCommandAvailable()
    {
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
     * Test that a dirty repo does no throw a "dirty repo" error when allowed.
     */
    public function testDirtyRepoAllowed()
    {
        $this->fs->touch([
            Path::canonicalize($this->sandbox . "/dirt.bag")
        ]);
        $args = [
            '--allow-dirty' => true,
            '--dry-run' => true,
        ];
        $options = [ 'interactive' => false ];
        $this->commandTester->execute($args, $options);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    /**
     * Test that a clean repo does not throw a "dirty repo" error.
     */
    public function testCleanRepo()
    {
        $args = [ '--dry-run' => true ];
        $options = [ 'interactive' => false ];
        $this->commandTester->execute($args, $options);
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    /**
     * Test that default git commit message is correct.
     */
    public function testGetLastCommitMessage()
    {
        $expected = $this->getDefaultCommitMessage();
        $this->application->setIo(new BufferIO());
        $actual = $this->command->getLastCommitMessage();
        $this->assertEquals($expected, $actual);
    }

    /**
     * Test that user is prompted to create tag when no args are provided.
     */
    public function testCreateTagQuestion()
    {
        try {
            $this->commandTester->execute([]);
        } catch (Exception $e) {
            // An "abort" RuntimeException will be throw by the QuestionHelper
            // when a question goes unanswered. Ignore it, we just want to
            // assert that the question was asked.
        }
        $this->assertContains("Would you like to create a tag", $this->commandTester->getDisplay(true));
    }
}
