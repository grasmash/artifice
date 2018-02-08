<?php

namespace Grasmash\Artifice\Composer;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

class ArtifactParameters extends ParameterBag {

    public function __construct($parameters = [])
    {
        $parameters = [
            'create_branch' => false,
            'create_tag' => false,
            'remote' => null,
            'cleanup' => [
                'local' => false,
                'artifact' => true,
            ],
            'commit_message' => null,
            'artifact_dir' => 'artifact',
            'refs_noun' => null,
        ];

        parent::__construct($parameters);
    }



}
