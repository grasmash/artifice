## Contributing


Add to `~/.composer/composer.json`
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

Execute `composer global update`.

You should now see `generate-artifact` in `composer list` commands.