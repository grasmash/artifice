<?php

namespace Grasmash\Arifice\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;

class Plugin implements PluginInterface, Capable, EventSubscriberInterface
{
  protected $composer;
  protected $io;

  /**
   * Returns an array of event names this subscriber wants to listen to.
   */
  public static function getSubscribedEvents() {
    return array(
      PackageEvents::POST_PACKAGE_INSTALL => "hi",
      PackageEvents::POST_PACKAGE_UPDATE => "hi",
    );
  }

  public function activate(Composer $composer, IOInterface $io)
  {
    $this->composer = $composer;
    $this->io = $io;
  }

  public function getCapabilities()
  {
    return array(
      'Composer\Plugin\Capability\CommandProvider' => 'Grasmash\Artifice\Composer\CommandProvider',
    );
  }

  public function hi() {
    $this->io->output->writeln('HI');
  }
}