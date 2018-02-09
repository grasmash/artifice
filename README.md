[![Build Status](https://travis-ci.org/grasmash/artifice.svg?branch=master)](https://travis-ci.org/grasmash/artifice) [![Coverage Status](https://coveralls.io/repos/github/grasmash/artifice/badge.svg?branch=master)](https://coveralls.io/github/grasmash/artifice?branch=master)

## Install

`$ composer require grasmash/artifice`

### Examples

Create a tag in the local repo containing the artifact:

    composer generate-artifact --no-interaction

Push a tag named v1.2.3 containing the artifact to a remote named `cloud`:

    composer generate-artifact --create_tag=v1.2.3 --remote=cloud --cleanup-local --no-interaction

Create a local artifact directory with the artifact to use as a dry-run:

    composer generate-artifact --create_branch --cleanup_local --save-artifact --no-interaction

## Options

* `--create_branch` _no value_  
  If passed, a branch containing the artifact will be created.

* `--create_tag` _string required_  
  The name of the tag to create. If this option is not passed, no tag will be
created.

* `--remote` _string required_  
  The name of a remote to which the generated artifact references (Branch, Tag,
  or Both) should be pushed. 

* `--commit_msg` _string required_  
  The commit message to be used if a branch is to be created. If this option is
  not passed, and a branch is created, the last commit message on the current
  branch will be used.

* `--allow_dirty` _no value_
  Proceed with artifact generation even if the current branch has uncommitted
  changes.  

* `--cleanup_local` _no value_  
  Remove the generated references (Branch, Tag, or Both) from the local repo
  during cleanup.  

* `--save_artifact` _no value_
  Don't delete the generated artifact directory during cleanup.

## Contributing

Clone repo:
```
$ git clone git@github.com:grasmash/artifice.git /path/to/artifice
```

Edit `~/.composer/composer.json`:
```
{
    "repositories": {
        "artifice": {
            "type": "path",
            "url": "/path/to/artifice",
            "options": {
                "symlink": true
            }
        }
    },
    "require": {
        "grasmash/artifice": "*@dev"
    }
}
```
Execute:
```
$ composer global update
$ composer list
```

You should now see `generate-artifact` as an available command.

### Testing

```
$ cd /path/to/artifice
$ composer test
```

### Auto-fix code style

```
$ cd /path/to/artifice
$ composer cbf
```

### Debugging

Composer disables xDebug by default. To force xDebug usage when debugging the `generate-arifact` command, run:

```
$ COMPOSER_ALLOW_XDEBUG=1 composer generate-artifact
```
