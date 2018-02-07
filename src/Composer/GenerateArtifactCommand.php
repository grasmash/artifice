<?php

namespace Grasmash\Artifice\Composer;

use Composer\Command\BaseCommand;
use Composer\Util\ProcessExecutor;
use Gitonomy\Git\Repository;
use Gitonomy\Git\Reference\Branch;
use Gitonomy\Git\Reference\Tag;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\FrozenParameterBag;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Psr\Log\InvalidArgumentException;

class GenerateArtifactCommand extends BaseCommand
{

    /**
     * A collection of parameters needed to generate the artifact.
     *
     * @var $artifactParams \Grasmash\Artifice\Composer\ArtifactParameters
     */
    protected $artifactParams;

    /**
     * Symfony Filesystem used to interact with directories and files.
     *
     * @var $fs \Symfony\Component\Filesystem\Filesystem
     */
    protected $fs;

    /**
     * The clone of the repo in $this->deployDir.
     *
     * @var $repo \Gitonomy\Git\Repository
     */
    protected $repo;

    public function __construct($name = NULL)
    {
        $this->fs = new Filesystem();
        parent::__construct($name);
    }

    /**
     * {@inheritDoc}
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        $this->artifactParams = new ArtifactParameters([]);
    }

    public function configure()
    {
        $this->setName('generate-artifact');
        $this->setDescription("Generate an deployment-ready artifact for a Drupal application.");
        $this->setAliases(['ga']);
        $this->addOption(
            'create_branch',
            null,
            InputOption::VALUE_NONE,
            'Whether or not the resulting artifact should be saved as a Git branch.'
        );
        $this->addOption(
            'create_tag',
            null,
            InputOption::VALUE_NONE,
            'Whether or not the resulting artifact should be saved as a Git tag.'
        );
        $this->addOption(
            'remote',
            null,
            InputOption::VALUE_REQUIRED,
            'The name of the git remote to which the generated artifact references should be pushed. References will not be pushed if this is empty.'
        );
        $this->addOption(
            'branch',
            null,
            InputOption::VALUE_REQUIRED,
            "The name of the git branch for the artifact. The tag is cut from this branch."
        );
        $this->addOption(
            'commit_msg',
            null,
            InputOption::VALUE_REQUIRED,
            "The git commit message for the artifact commit."
        );
        $this->addOption(
            'allow_dirty',
            null,
            InputOption::VALUE_NONE,
            "Allow artifact to be generated despite uncommitted changes in local git repository."
        );
        $this->addOption(
            'cleanup_local',
            null,
            InputOption::VALUE_NONE,
            "Whether or not to remove the references from the local repo when finished."
        );
        $this->addOption(
            'cleanup_deploy',
            null,
            InputOption::VALUE_NONE,
            "Whether or not to remove the deploy sub-directory when finished."
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
        $this->cleanupDeploy();
        $this->prerequisitesAreMet();

        $this->gatherOptions($input);

        $this->prepareDeploy();

        $referenceInstructions = new FrozenParameterBag($this->artifactParams->all());
        $this->deployRefs($referenceInstructions);

        return 0;
    }

    protected function gatherOptions(InputInterface $input)
    {
        $this->checkDirty($input->getOption('allow_dirty'));
        $this->determineOutputRefs($input);
        $this->determineRemotes($input);
        $this->determineCleanup($input);
        $this->setCommitMessage($input);
    }

    /**
     * Creates an optimized artifact on a new branch inside a deploy directory
     * with the upstream set to the local checkout.
     */
    protected function prepareDeploy()
    {
        $this->createDirectory();
        $this->intitalizeGit();
        $this->build();
        $this->createBranch();
    }

    /**
     * @param \Symfony\Component\DependencyInjection\ParameterBag\FrozenParameterBag $referenceInstructions
     */
    protected function deployRefs(FrozenParameterBag $referenceInstructions)
    {
        if ($branchName = $referenceInstructions->get('create_branch')) {
            $this->pushLocal($branchName);
        }
        if ($tagName = $referenceInstructions->get('create_tag')) {
            $this->repo->run('tag', [$tagName]);
            $this->pushLocal("tags/$tagName");
        }
        if ($referenceInstructions->get('remote')) {
            $this->pushRemote();
        }

        $cleanup = $referenceInstructions->get('cleanup');
        if ($cleanup['deploy']) {
            $this->cleanupDeploy();
        }

        if ($cleanup['local']) {
            $this->cleanupLocal();
        }
    }

    /**
     * Removes the generated deploy directory.
     */
    protected function cleanupDeploy()
    {
        $this->fs->remove($this->artifactParams->get('deploy_dir'));
    }

    /**
     * Removes the generated references from the local repo.
     */
    protected function cleanupLocal()
    {
        if ($deleteBranch = $this->artifactParams->get('create_branch')) {
            $this->runCommand("git branch -D $deleteBranch");
        }
        if ($deleteTag = $this->artifactParams->get('create_tag')) {
            $this->runCommand("git tag -d $deleteTag");
        }
    }

    /**
     * Pushes a reference from the deploy directory back into the main local
     * checkout.
     *
     * @param string $reference
     *   The tag or branch name to push.
     */
    protected function pushLocal($reference)
    {
        $this->repo->run('push', ['origin', $reference, '--force']);
    }

    /**
     * Pushes all generated references from the local repo to the set remote.
     */
    protected function pushRemote()
    {
        $remote = $this->artifactParams->get('remote');
        if ($this->artifactParams->get('create_branch')) {
            $this->say("Pushing artifact branch to $remote.");
            $this->runCommand("git push $remote " . $this->artifactParams->get('branch_name'));
        }
        if ($this->artifactParams->get('create_tag')) {
            $this->say("Pushing artifact tag to $remote.");
            $this->runCommand("git push $remote tags/" . $this->artifactParams->get('tag_name'));
        }
    }

    /**
     * Creates a branch for the artifact in the deploy directory.
     */
    protected function createBranch()
    {
        $this->repo->run('checkout', ['-b', 'artifact-' . $this->getCurrentBranch()]);
        $this->fs->remove($this->artifactParams->get('deploy_dir') . '/.gitignore');
        $this->cleanSubmodules('vendor');
        $this->cleanSubmodules('docroot');
        $this->repo->run('add', ['-A']);
        $this->repo->run('commit', ['-m', $this->artifactParams->get('commit_message')]);
    }

    /**
     * Creates the directory in which the artifact will be generated.
     */
    protected function createDirectory()
    {
        $this->say("Preparing artifact directory.");
        $this->fs->mkdir($this->artifactParams->get('deploy_dir'));
    }

    /**
     * Removes all .git directories found in the provided directory.
     *
     * @param $dir
     *   The directory to clean relative to repo root.
     */
    protected function cleanSubmodules($dir) {
        $deployDir = $this->artifactParams->get('deploy_dir');
        $this->runCommand("find '$deployDir/$dir' -type d | grep '\.git' | xargs rm -rf");
    }

    /**
     * Initializes git and does a local checkout.
     */
    protected function intitalizeGit()
    {
        $this->say('Initializing git');
        // Use the local repo as the source for cloning because the current
        // branch isn't necessarily pushed to a remote.
        $source = $this->runCommand('pwd');
        $deployDir = $this->artifactParams->get('deploy_dir');
        $branch = $this->getCurrentBranch();
        $this->runCommand("git clone --branch $branch $source $deployDir");
        $this->repo = new Repository($deployDir);
    }

    /**
     * Builds a production-optimized codebase with Composer.
     */
    protected function build() {
        $this->say('Building production-optimized codebase...');
        $this->say('This may take awhile.');
        $this->runCommand('composer install --no-dev --optimize-autoloader', null, $this->artifactParams->get('deploy_dir'));
    }

    /**
     * An array of configured git remotes.
     *
     * @return array
     *   The name of the configured git remote.
     */
    protected function getGitRemotes()
    {
        return explode("\n", $this->runCommand("git remote"));
    }
    protected function getCurrentBranch()
    {
        return $this->runCommand('git rev-parse --abbrev-ref HEAD');
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
        $process->execute("git --version | cut -d' ' -f3", $output);
        if (version_compare($output, $minimum_version, '>=')) {
            return true;
        }
        return false;
    }

    /**
     * @codeCoverageIgnore
     *
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
                throw new RuntimeException("There are uncommitted changes in your local git repository! Commit or stash these changes before generating artifact. Use --allow_dirty option to disable this check.");
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
    public function setCommitMessage(InputInterface $input)
    {
        $commit_msg_option = $input->getOption('commit_msg');
        if ($commit_msg_option) {
            $this->say("Commit message is set to <comment>$commit_msg_option</comment>.");
            $this->artifactParams->set('commit_message', $commit_msg_option);
        } else {
            $git_last_commit_message = $this->getLastCommitMessage();
            $this->artifactParams->set('commit_message',
                $this->getIO()->ask(
                    "Enter a valid commit message [<comment>$git_last_commit_message</comment>]",
                    $git_last_commit_message
                )
            );
        }
    }

    public function getCommitMessage()
    {
        return $this->artifactParams->get('commit_message');
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
     * @return string
     */
    public function getLastCommitMessage()
    {
        $output = $this->runCommand(
            'git log --oneline -1',
            "Unable to find any git history!",
            $this->getRepoRoot()
        );
        $log = explode(' ', $output, 2);
        $git_last_commit_message = trim($log[1]);

        return $git_last_commit_message;
    }

    /**
     * Wrapper method around Symfony's ProcessExecutor.
     *
     * @param string $command
     * @param string $error_msg
     * @param string $cwd
     *
     * @return string
     *   The output of the command if successful.
     */
    protected function runCommand($command, $error_msg = null, $cwd = null) {
        $process = new ProcessExecutor($this->getIO());
        $exit_code = $process->execute($command, $output, $cwd);
        if ($exit_code !== 0) {
            if (!$error_msg) {
                $error_msg = "Command $command returned a non-zero exit status.";
            }
            throw new RuntimeException($error_msg);
        }
        return trim($output);
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
        $this->fs->exists($config->get('vendor-dir'));
        $vendorPath = $this->fs->makePathRelative(realpath($config->get('vendor-dir')), '.');

        return $vendorPath;
    }

    protected function determineOutputRefs(InputInterface $input)
    {
        if ($input->getOption('create_branch')) {
            $branchName = 'artifact-' . $this->getCurrentBranch();
            $this->artifactParams->set('create_branch', $branchName);
        }
        if ($input->getOption('create_tag')) {
            $tagName = 'artifact-' . $this->runCommand('git rev-parse HEAD');
            $this->artifactParams->set('create_tag', $tagName);
        }
        if (!$input->getOption('create_branch') && !$input->getOption('create_tag')) {
            // If either branch or tag options are passed, we assume that we
            // shouldn't ask about output refs. So we only ask if both are not.
            $this->askOutputRefs();
        }
    }

    protected function determineRemotes(InputInterface $input)
    {
        if ($input->getOption('remote')) {
            $this->validateRemote($input->getOption('remote'));
            $this->artifactParams->set('remote', $input->getOption('remote'));
        }
        elseif ($this->getIO()->askConfirmation('Would you like to push the resulting ' . $this->artifactParams->get('refs_noun') . ' to one of your remotes? [yes/no]', false)) {
            if (count($this->getGitRemotes() > 1)) {
                $options = $this->getGitRemotes();
                $remote = $this->getIO()->select('Which remote would you like to push the references to?', $options, null);
            }
            else {
                $remote = reset($this->getGitRemotes());
            }
            $this->artifactParams->set('remote', $remote);
        }
    }

    protected function determineCleanup(InputInterface $input)
    {
        if ($input->getOption('cleanup_local')) {
            $this->artifactParams->set('cleanup_local', true);
        }
        if ($input->getOption('cleanup_deploy')) {
            $this->artifactParams->set('cleanup_deploy', true);
        }

        if (!$input->getOption('cleanup_local') && !$input->getOption('cleanup_deploy')) {
            // If either cleanup_local or cleanup_deploy are passed, we assume
            // that we shouldn't ask about cleanup. So we only ask if both are
            // not.
            $this->askCleanup();
        }
    }

    protected function askOutputRefs() {
        $options = [
            0 => 'Branch',
            1 => 'Tag',
            2 => 'Branch and Tag',
        ];
        $this->artifactParams->set('refs_noun',
            $this->getIO()->select(
                'Do you want to create a branch, tag, or both?',
                $options,
                'Branch'
            )
        );
        return self::normalizeRefs($this->artifactParams->get('refs_noun'));
    }

    protected function normalizeRefs($ref)
    {
        switch (strtolower($ref)) {
            case 'branch':
                $branchName = 'artifact-' . $this->getCurrentBranch();
                $this->artifactParams->set('create_branch', $branchName);
                break;
            case 'tag':
                $tagName = 'artifact-' . $this->runCommand('git rev-parse HEAD');
                $this->artifactParams->set('create_tag', $tagName);
                break;
            case 'branch and tag':
                $branchName = 'artifact-' . $this->getCurrentBranch();
                $this->artifactParams->set('create_branch', $branchName);
                $tagName = 'artifact-' . $this->runCommand('git rev-parse HEAD');
                $this->artifactParams->set('create_tag', $tagName);
                break;
            default:
                throw new InvalidArgumentException("$ref is not a valid Reference.");
        }
    }

    /**
     * Verifies that the provided remote name is actually configured as a remote
     * in the main repo.
     *
     * @param string $remote
     *   The name of the remote to validate.
     */
    protected function validateRemote($remote)
    {
        $remotes = $this->getGitRemotes();
        if (!in_array($remote, $remotes)) {
            throw new InvalidArgumentException("You asked to push references to $remote, but no configured remote has that name. You have the following configured remotes: " . implode(', ', $remotes));
        }
    }

    protected function askCleanup()
    {
        $this->artifactParams->set(
            'cleanup_deploy',
            $this
                ->getIO()
                ->askConfirmation(
                    'Would you like to cleanup the generated artifact directory?',
                    true
                )
        );

        if ($this->determineAskCleanupLocal()) {
            $this->artifactParams->set(
                'cleanup_local',
                $this
                    ->getIO()
                    ->askConfirmation(
                        'By default, the generated ' . $this->artifactParams->get('refs_noun') . ' will also be saved to the source repository. Would you like to clean up these references?',
                        true
                    )
            );
        }
    }

    protected function determineAskCleanupLocal() {
        /**
         * It only makes sense to ask if the local refs should be cleaned up
         * under certain circumstances. E.g., if we don't push to a remote, and
         * do cleanup the deploy directory (i.e. row 1 below), then we must want
         * to keep the local refs (otherwise there would be no result).
         *
         *  remote | cleanup deploy | result
         * ---------------------------------
         *  false  | true           | don't ask
         *  false  | false          | ask
         *  true   | true           | ask
         *  true   | false          | ask
         */
        if ($this->artifactParams->get('remote') === null && $this->artifactParams->get('cleanup_deploy')) {
            return false;
        }
        return true;
    }
}
