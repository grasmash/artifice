<?php

namespace Grasmash\Artifice\Tests;

class PluginTest extends CommandTestBase
{

    /**
     * {@inheritdoc}
     *
     * @see https://symfony.com/doc/current/console.html#testing-commands
     */
    public function setUp()
    {
        parent::setUp();
    }

    /**
     * Tests that all expected commands are available in the application.
     *
     * @dataProvider getValueProvider
     */
    public function testComposerCommandsAvailable($expected)
    {
        chdir($this->sandbox);
        // Allow xdebug so that code coverage is tracked.
        $output = shell_exec("COMPOSER_ALLOW_XDEBUG=1 composer list");
        $this->assertContains($expected, $output);
    }
    /**
     * Provides values to testComposerCommandsAvailable().
     *
     * @return array
     *   An array of values to test.
     */
    public function getValueProvider()
    {
        return [
            ['generate-artifact'],
        ];
    }
}
