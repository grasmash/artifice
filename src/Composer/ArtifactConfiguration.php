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
            ->booleanNode('create_branch')
                ->defaultFalse()
                ->info('Whether or not the resulting artifact should be saved as a Git branch.')
            ->end()
            ->booleanNode('create_tag')
                ->defaultTrue()
                ->info('Whether or not the resulting artifact should be saved as a Git tag.')
            ->end()
            ->variableNode('remote')
                ->defaultValue(null)
                ->info('The name of the remote to which references should be pushed. References will not be pushed if this is empty.')
            ->end()
            ->booleanNode('cleanup_local')
                ->defaultFalse()
                ->info('Whether or not to remove the references from the local repo when finished.')
            ->end()
            ->booleanNode('cleanup_deploy')
                ->defaultTrue()
                ->info('Whether or not to remove the deploy sub-directory when finished.')
            ->end()
        ->end();

        return $treeBuilder;
    }

}