<?php

namespace Grasmash\Artifice\Tests;

use RuntimeException;
use Webmozart\PathUtil\Path;

class GenerateArtifactCommandTest extends CommandTestBase
{

  /**
   * {@inheritdoc}
   *
   * @see https://symfony.com/doc/current/console.html#testing-commands
   */
  public function setUp() {
    parent::setUp();
  }

  /**
   * Test that a dirty repo throws a "dirty repo" error.
   */
    public function testDirtyRepoFailure() {
      $sandbox = $this->makeSandbox();
      $this->fs->touch([
        Path::canonicalize($sandbox . "/dirt.bag")
      ]);
      try {
        $this->commandTester->execute([]);
      }
      catch (RuntimeException $e) {
        $this->assertContains("There are uncommitted changes", $e->getMessage());
      }
    }

  /**
   * Test that a clean repo does not throw a "dirty repo" error.
   */
    public function testCleanRepo() {
      $this->makeSandbox();
      $this->commandTester->execute([]);
      $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

}
