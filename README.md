## Install

`$ composer require grasmash/artifice`

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

## Debugging

Composer disables xDebug by default. To force xDebug usage when debugging the `generate-arifact` command, run `COMPOSER_ALLOW_XDEBUG=1 composer generate-artifact`.