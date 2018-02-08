<?php

namespace Grasmash\Artifice\Tests;

use Composer\IO\BufferIO;
use Exception;
use Gitonomy\Git\Repository;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
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

    /**
     * Test that a missing git repo throws exception.
     */
    public function testMissingRepo()
    {
        $this->fs->remove([ Path::canonicalize($this->sandbox . "/.git") ]);
        try {
            $this->commandTester->execute([]);
            $this->assertImpossible();
        } catch (RuntimeException $e) {
            $this->assertContains("Unable to determine if local git repository is dirty.", $e->getMessage());
        }
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
            $this->assertImpossible();
        } catch (RuntimeException $e) {
            $this->assertContains("There are uncommitted changes", $e->getMessage());
        }
    }

    /**
     * Test that a dirty repo does not throw a "dirty repo" error when allowed.
     */
    public function testDirtyRepoAllowed()
    {
        $this->fs->touch([
            Path::canonicalize($this->sandbox . "/dirt.bag")
        ]);
        $args = [
            '--allow_dirty' => true,
            '--create_branch' => true,
            '--create_tag' => false
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
        $options = [ 'interactive' => false ];
        $args = [
            '--create_branch' => true,
            '--create_tag' => false,
        ];
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
     * Test behavior when .git is missing from project root.
     */
    public function testNoCommitMessage()
    {
        $this->fs->remove([ Path::canonicalize($this->sandbox . "/.git") ]);
        $this->application->setIo(new BufferIO());
        try {
            $this->command->getLastCommitMessage();
            $this->assertImpossible();
        } catch (RuntimeException $e) {
            $this->assertContains("Unable to find any git history!", $e->getMessage());
        }
    }

    /**
     * Test that --commit_msg sets commit message correctly.
     */
    public function testSetCommitMessage()
    {
        $this->application->setIo(new BufferIO());
        $commit_msg = 'Test commit message.';
        $input = new ArrayInput(
            ['--commit_msg' => $commit_msg],
            $this->command->getDefinition()
        );
        $this->command->setCommitMessage($input);
        $this->assertEquals($commit_msg, $this->command->getCommitMessage());
    }

    public function testInteractiveDefaults()
    {
        $args = ['--create_tag' => 'mytag'];
        $options = ['interactive' => true];
        $enter = "\r\n";
        $this->commandTester->setInputs([
            // Do you want to create a branch, tag, or both?
            //$enter,
            // Would you like to push the resulting Tag to one of your remotes?
            $enter,
            // Would you like to save the generated artifact directory?
            $enter,
            // Enter a valid commit message
            $enter,
        ]);
        $this->commandTester->execute($args, $options);
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    /**
     * Test the default behavior:
     *   - artifact directory should not be present
     *   - Should be a tag with commit hash in local repo
     *   - Should not push to any remotes
     *   - Should not be a new branch
     */
    public function testDefault()
    {
        $repo = new Repository(Path::canonicalize($this->sandbox));
        $tagName = 'artifact-' . trim($repo->run('rev-parse', ['HEAD']));

        $options = [ 'interactive' => false ];
        // For some reason, ConsoleIO::select has trouble specifically when
        // run during tests. So I'm passing the default options here for now.
        $args = ['--create_tag' => $tagName];
        $this->commandTester->execute($args, $options);
        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertFalse(
            $this->fs->exists(Path::canonicalize($this->sandbox . '/artifact'))
        );
        $this->assertContains("Artifact generation complete!", $this->commandTester->getDisplay(true));

        $tags = explode("\n", $repo->run('tag'));
        $this->assertArrayHasKey($tagName, array_flip($tags));
    }

    /**
     * Test that the --save_artifact option preserves the build directory.
     */
    public function testSaveArtifact()
    {
        $options = [ 'interactive' => false ];
        $args = [
            '--create_branch' => true,
            '--create_tag' => false,
            '--save_artifact' => true,
        ];
        $this->commandTester->execute($args, $options);
        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertTrue(
            $this->fs->exists(Path::canonicalize($this->sandbox . '/artifact'))
        );
    }

    /**
     * Test that user is prompted for commit message when none is provided.
     */
    public function testAskCommitMessage()
    {
        $args = [];
        $options = [ 'interactive' => true ];
        $commit_msg = 'Test commit message.';
        $this->commandTester->setInputs([
            // Do you want to create a branch, tag, or both?
            0,
            // Would you like to push the resulting Branch to one of your remotes?
            'no',
            // Would you like to cleanup the generated artifact directory?
            'no',
            // By default, the generated Branch will also be saved to the source repository. Would you like to clean up these references?
            'yes',
            // Enter a valid commit message
            $commit_msg,
        ]);
        $this->commandTester->execute($args, $options);
        $this->assertEquals($commit_msg, $this->command->getCommitMessage());
    }

    /**
     * Test that user is prompted to create tag when no args are provided.
     */
    public function testCreateTagQuestion()
    {
        try {
            $this->commandTester->execute([]);
            $this->assertImpossible();
        } catch (Exception $e) {
            // An "abort" RuntimeException will be throw by the QuestionHelper
            // when a question goes unanswered. Ignore it, we just want to
            // assert that the question was asked.
        }
        $this->assertContains("Would you like to create a tag", $this->commandTester->getDisplay(true));
    }

    /**
     * Makes an impossible assertion. Intended to fail.
     */
    protected function assertImpossible()
    {
        $this->assertEquals(
            1,
            2,
            "This assertion should be unreachable. An exception should have been thrown."
        );
    }

    /**
     * Test that using --tag deploys tag.
     */
    public function testDeployTagOption()
    {
        $args = [ '--tag' => '1.0.0' ];
        $options = [ 'interactive' => false ];
        $this->command->setSimulate(true);
        $this->commandTester->execute($args, $options);
        $this->assertContains("Deploying to tag!", $this->commandTester->getDisplay());
    }

    /**
     * Test that using --tag deploys branch.
     */
    public function testDeployBranchOption()
    {
        $args = [ '--branch' => 'test' ];
        $options = [ 'interactive' => false ];
        $this->command->setSimulate(true);
        $this->commandTester->execute($args, $options);
        $this->assertContains("Deploying to branch!", $this->commandTester->getDisplay());
    }

    /**
     * Test that using --tag deploys tag.
     */
    public function testDeployTagAndBranchOptions()
    {
        $args = [
            '--tag' => '1.0.0',
            '--branch' => 'test',
        ];
        $options = [ 'interactive' => false ];
        $this->command->setSimulate(true);
        $this->commandTester->execute($args, $options);
        $this->assertContains("Deploying to tag!", $this->commandTester->getDisplay());
    }

    /**
     *
     */
    public function testDetermineOutputRefs()
    {
        $args = [];
        $options = [ 'interactive' => false ];
        $this->commandTester->execute($args, $options);
    }

    // @todo Write tests:
    // test git missing
    // test git < 2
}
