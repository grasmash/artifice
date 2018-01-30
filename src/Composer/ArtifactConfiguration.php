<?php

namespace Grasmash\Artifice\Composer;

use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class ArtifactConfiguration implements ConfigurationInterface {

    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('artifact');
        $rootNode->children()
            ->booleanNode('createBranch')
                ->defaultFalse()
                ->info('Whether or not the resulting artifact should be saved as a Git branch.')
            ->end()
            ->booleanNode('CreateTag')
                ->defaultTrue()
                ->info('Whether or not the resulting artifact should be asaved as a Git tag.')
            ->end()
            ->arrayNode('remote')
                ->children()
                    ->booleanNode('push')
                        ->defaultFalse()
                        ->info('Whether or not the saved artifact references should be pushed to a remote.')
                    ->end()
                ->children()
                    ->variableNode('remoteName')
                    ->defaultValue(null)
                    ->info('The name of the remote to which references should be pushed.')
                ->end()
            ->booleanNode('cleanupLocal')
                ->defaultTrue()
                ->info('Whether or not to remove the references from the local repo when finished.')
            ->end()
            ->booleanNode('cleanupDeply')
                ->defaultTrue()
                ->info('Whether or not to remove the deploy sub-directory when finished.')
            ->end()
        ->end();

        return $treeBuilder;
    }

}