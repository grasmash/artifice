<?php

namespace Grasmash\Artifice\Composer;

use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Command\BaseCommand;

class GenerateArtifactCommand extends BaseCommand
{

    protected $commitMessage;

    public function configure()
    {
        $this->setName('generate-artifact');
        $this->setDescription("Generate an deployment-ready artifact for a Drupal application.");
        $this->setAliases(['ga']);
        $this->addOption(
            'branch',
            null,
            InputOption::VALUE_REQUIRED,
            "The name of the git branch for the artifact. The tag is cut from this branch."
        );
        $this->addOption(
            'tag',
            null,
            InputOption::VALUE_REQUIRED,
            "The name of the git tag for the artifact. E.g., 1.0.0."
        );
        $this->addOption(
            'commit-msg',
            null,
            InputOption::VALUE_REQUIRED,
            "The git commit message for the artifact commit."
        );
        $this->addOption(
            'allow-dirty',
            null,
            InputOption::VALUE_NONE,
            "Allow artifact to be generated despite uncommitted changes in local git repository."
        );
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            "Generate artifact without pushing it upstream."
        );
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return int
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->prerequisitesAreMet();
        $this->checkDirty($input->getOption('allow-dirty'));

        $create_tag = $this->determineCreateTag($input);
        $this->setCommitMessage($input);

        if ($create_tag) {
            $this->deployToTag($input);
        } else {
            $this->deployToBranch($input);
        }

        return 0;
    }

    /**
     * Creates artifact, cuts new tag, and pushes.
     */
    protected function deployToTag($input)
    {
        $this->say("Deploying to tag!");
    }

    /**
     * Creates artifact on branch and pushes.
     */
    protected function deployToBranch($input)
    {
        $this->say("Deploying to branch!");
    }

    /**
     * Checks if a given command exists on the system.
     *
     * @param string $command
     *   The command binary only. E.g., "drush" or "php".
     *
     * @return bool
     *   TRUE if the command exists, otherwise FALSE.
     */
    public function commandExists($command)
    {
        $process = new ProcessExecutor($this->getIO());
        $exit_code = $process->execute("command -v $command >/dev/null 2>&1", $output);

        return $exit_code == 0;
    }

    /**
     * Verifies that installed minimum git version is met.
     *
     * @param string $minimum_version
     *   The minimum git version that is required.
     *
     * @return bool
     *   TRUE if minimum version is satisfied.
     */
    public function isGitMinimumVersionSatisfied($minimum_version)
    {
        $process = new ProcessExecutor($this->getIO());
        $exit_code = $process->execute("git --version | cut -d' ' -f3", $output);
        if (version_compare($output, $minimum_version, '>=')) {
            return true;
        }
        return false;
    }

    /**
     * @throws \RuntimeException
     */
    protected function prerequisitesAreMet()
    {
        if (!$this->commandExists('git')) {
            throw new RuntimeException("Git is not installed!");
        }
        if (!$this->isGitMinimumVersionSatisfied('2.0')) {
            throw new RuntimeException("Git is too old! Please update git to 2.0 or newer.");
        }
    }

    /**
     * Checks to see if current git branch has uncommitted changes.
     *
     * @param bool $allow_dirty
     *   If a dirty repo is permitted for artifact generation.
     *
     * @throws \RuntimeException
     *   Thrown if there are uncommitted changes.
     */
    protected function checkDirty($allow_dirty)
    {
        $process = new ProcessExecutor($this->getIO());
        $exit_code = $process->execute("git status --porcelain", $output);

        if (!$allow_dirty && $exit_code !== 0) {
            throw new RuntimeException("Unable to determine if local git repository is dirty.");
        }

        $dirty = (bool) $output;
        if ($dirty) {
            if ($allow_dirty) {
                $this->warn("There are uncommitted changes in your local git repository. Continuing anyway...");
            } else {
                throw new RuntimeException("There are uncommitted changes in your local git repository! Commit or stash these changes before generating artifact. Use --allow-dirty option to disable this check.");
            }
        }
    }

    /**
     * Sets the commit message to be used for committing deployment artifact.
     *
     * Defaults to the last commit message on the source branch.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     */
    protected function setCommitMessage(InputInterface $input)
    {
        $commit_msg_option = $input->getOption('commit-msg');
        if ($commit_msg_option) {
            $this->say("Commit message is set to <comment>$commit_msg_option</comment>.");
            $this->commitMessage = $commit_msg_option;
        } else {
            $git_last_commit_message = $this->getLastCommitMessage();
            $this->commitMessage = $this->getIO()->ask(
                "Enter a valid commit message [<comment>$git_last_commit_message</comment>]",
                $git_last_commit_message
            );
        }
    }

    protected function say($message)
    {
        $this->getIO()->writeError($message);
    }
    protected function warn($message)
    {
        $this->getIO()->writeError("<warning>$message</warning>");
    }

    /**
     * @return bool
     */
    protected function askCreateTag($default)
    {
        $this->say("Typically, you would only create a tag if you currently have a tag checked out on your source repository.");
        $default_label = $default ? 'yes' : 'no';
        return $this->getIO()->askConfirmation(
            "Would you like to create a tag [<comment>$default_label</comment>]? ",
            $default
        );
    }

    /**
     * @return string
     */
    public function getLastCommitMessage()
    {
        $process = new ProcessExecutor($this->getIO());
        // @todo Handle failure.
        $exit_code = $process->execute("git log --oneline -1", $output, $this->getRepoRoot());
        $log = explode(' ', $output, 2);
        $git_last_commit_message = trim($log[1]);

        return $git_last_commit_message;
    }

    /**
     * Returns the repo root's filepath, assumed to be one dir above vendor dir.
     *
     * @return string
     *   The file path of the repository root.
     */
    public function getRepoRoot()
    {
        return dirname($this->getVendorPath());
    }

    /**
     * Get the path to the 'vendor' directory.
     *
     * @return string
     */
    public function getVendorPath()
    {
        $config = $this->getComposer()->getConfig();
        $filesystem = new Filesystem();
        $filesystem->ensureDirectoryExists($config->get('vendor-dir'));
        $vendorPath = $filesystem->normalizePath(realpath($config->get('vendor-dir')));

        return $vendorPath;
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     *
     * @return bool
     */
    protected function determineCreateTag(InputInterface $input)
    {
        // Create tag when --tag is set, even if --branch is also set.
        if ($input->getOption('tag')) {
            return true;
        }

        // Do not create tag if only --branch is set.
        if ($input->getOption('branch')) {
            return false;
        }

        // If the user has not specified a tag or a branch, ask what to do.
        // Defaults to TRUE if --no-interaction.
        return $this->askCreateTag(true);
    }
}
