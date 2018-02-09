<?php

namespace Grasmash\Artifice\Composer;

use Composer\Command\BaseCommand;
use Composer\Util\ProcessExecutor;
use Gitonomy\Git\Repository;
use RuntimeException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\FrozenParameterBag;
use Symfony\Component\Filesystem\Filesystem;
use Psr\Log\InvalidArgumentException;

class GenerateArtifactCommand extends BaseCommand
{

    /**
     * A collection of parameters needed to deploy and cleanup the artifact.
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
     * The clone of the repo in the artifact directory.
     *
     * @var $repo \Gitonomy\Git\Repository
     */
    protected $repo;

    /**
     * A progress bar to present.
     *
     * @var $progress \Symfony\Component\Console\Helper\ProgressBar
     */
    protected $progress;

    public function __construct($name = NULL)
    {
        $this->fs = new Filesystem();
        $this->artifactParams = new ArtifactParameters([]);
        parent::__construct($name);
    }

    /**
     * {@inheritDoc}
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->progress = new ProgressBar($output);
        $this->progress->setFormatDefinition('custom', "\n %current%/%max% |%bar%| \n\n%message% \n\n");
        $this->progress->setFormat('custom');
        $this->progress->setBarCharacter('■');
        $this->progress->setProgressCharacter('▶');
        $this->progress->setEmptyBarCharacter('▬');
        parent::initialize($input, $output);
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
            InputOption::VALUE_REQUIRED,
            'Whether or not the resulting artifact should be saved as a Git tag.'
        );
        $this->addOption(
            'remote',
            null,
            InputOption::VALUE_REQUIRED,
            'The name of the git remote to which the generated artifact references should be pushed. References will not be pushed if this is empty.'
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
            "Whether or not to remove the created references from the local repo when finished."
        );
        $this->addOption(
            'save_artifact',
            null,
            InputOption::VALUE_NONE,
            "Whether or not to save the artifact sub-directory when finished."
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
        $this->cleanupArtifactDir();
        $this->prerequisitesAreMet();

        /* @var $instructions FrozenParameterBag */
        $instructions = $this->gatherOptions($input);

        $this->progress->start(9);

        $this->build($instructions);
        $this->deploy($instructions);
        $this->cleanup($instructions);
        $this->summarize($instructions);

        $this->progress->finish();

        return 0;
    }

    /**
     * Gathers options for the artifact from command options and user input and
     * returns them in an immutable FrozenParameterBag.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @return \Symfony\Component\DependencyInjection\ParameterBag\FrozenParameterBag
     */
    protected function gatherOptions(InputInterface $input)
    {
        $this->checkDirty($input);
        $this->determineOutputRefs($input);
        $this->determineRemotes($input);
        $this->determineCleanup($input);
        $this->setCommitMessage($input);

        $instructions = new FrozenParameterBag($this->artifactParams->all());
        // Make sure that nothing tries to modify parameters from this point on.
        unset($this->artifactParams);
        return $instructions;
    }

    /**
     * Creates an optimized artifact on a new branch inside the artifact
     * directory with the upstream set to the local checkout.
     */
    protected function build(FrozenParameterBag $instructions)
    {
        $this->createDirectory($instructions);
        $this->intitalizeGit($instructions);
        $this->make($instructions);
        $this->createBranch($instructions);

        $this->progress->advance();
    }

    /**
     * Pushes the artifact references (branch, tag, or both) from the artifact
     * directory to the local git repo and also pushes to a configured remote
     * if one is set in the $instructions.
     *
     * @param \Symfony\Component\DependencyInjection\ParameterBag\FrozenParameterBag $instructions
     */
    protected function deploy(FrozenParameterBag $instructions)
    {
        if ($branchName = $instructions->get('create_branch')) {
            $this->pushLocal($branchName);
        }
        if ($tagName = $instructions->get('create_tag')) {
            $this->repo->run('tag', [$tagName]);
            $this->pushLocal("tags/$tagName");
        }
        if ($instructions->get('remote')) {
            $this->pushRemote($instructions);
        }

        $this->progress->advance();
    }

    /**
     * Deletes the generated artifact directory and removes tag and branch
     * references from the local git repo according to $instructions.
     *
     * @param \Symfony\Component\DependencyInjection\ParameterBag\FrozenParameterBag $instructions
     */
    protected function cleanup(FrozenParameterBag $instructions)
    {
        $this->progress->setMessage('Cleaning up.');

        if (!$instructions->get('save_artifact')) {
            $this->cleanupArtifactDir($instructions->get('artifact_dir'));
        }
        if ($instructions->get('cleanup_local')) {
            $this->cleanupLocal($instructions);
        }

        $this->progress->advance();
    }

    /**
     * Summarizes the action taken and notifies the user.
     *
     * @param \Symfony\Component\DependencyInjection\ParameterBag\FrozenParameterBag $instructions
     */
    protected function summarize(FrozenParameterBag $instructions)
    {
        $this->progress->advance();
        $this->progress->setMessage('Artifact generation complete!');

        $locations = [];
        if ($instructions->get('save_artifact')) {
            $locations['artifact'] = 'locally in the ' . $instructions->get('artifact_dir') . ' directory';
        }
        if (!$instructions->get('cleanup_local')) {
            $locations['local'] = 'locally in your source repo directory';
        }
        if ($remote = $instructions->get('remote')) {
            $locations['remote'] = 'on the ' . $remote . ' remote';
        }

        $refs = [];
        if ($tag = $instructions->get('create_tag')) {
            $refs['tag'] = "tag '<info>$tag</info>'";
        }
        if ($branch = $instructions->get('create_branch')) {
            $refs['branch'] = "branch '<info>$branch</info>'";
        }

        $summary = 'The ' . $this->oxford($refs) . ' ' . $this->plural(count($refs)) . ' available ' . $this->oxford($locations) . '.';

        if (!count($locations)) {
            $summary = '<error>Successfully created ' . $this->oxford($refs) . ' but the provided options mean ' . $this->plural(count($refs), ['singular' => 'it wasn\'t', 'plural' => 'they weren\'t']) . ' saved or pushed anywehere.</error>';
        }

        $this->progress->setMessage($summary);
    }

    protected function determineOutputRefs(InputInterface $input)
    {
        if ($input->getOption('create_branch')) {
            $branchName = 'artifact-' . $this->getCurrentBranch();
            $this->artifactParams->set('create_branch', $branchName);
            $this->artifactParams->set('refs_noun', 'Branch');
        }
        if ($input->getOption('create_tag')) {
            $this->setTagName($input);
            $this->artifactParams->set('refs_noun', 'Tag');
        }
        if (!$input->getOption('create_branch') && !$input->getOption('create_tag')) {
            // If either branch or tag options are passed, we assume that we
            // shouldn't ask about output refs. So we only ask if both are not.
            $this->askOutputRefs($input);
        } elseif ($input->getOption('create_branch') && $input->getOption('create_tag')) {
            $this->artifactParams->set('refs_noun', 'Branch and Tag');
        }
    }

    protected function determineRemotes(InputInterface $input)
    {
        if ($remote = $input->getOption('remote')) {
            $this->validateRemote($remote);
            $this->artifactParams->set('remote', $remote);
            $this->say("Set to push artifacts to <comment>$remote</comment> remote.");
        }
        elseif ($this->askRemote()) {
            if (count($this->getGitRemotes() > 1)) {
                $remotes = $this->getGitRemotes();
                $remote = $this->askWhichRemote($remotes);
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
        if ($input->getOption('save_artifact')) {
            $this->artifactParams->set('save_artifact', true);
        }

        if (!$input->getOption('cleanup_local') && !$input->getOption('save_artifact')) {
            // If either cleanup_local or save_artifact (or both) are passed,
            // we assume that we shouldn't ask about cleanup. So we only ask if
            // both are not.
            $this->askCleanup();
        }
    }

    /**
     * Removes the generated artifact directory.
     */
    protected function cleanupArtifactDir($artifactDir = null)
    {
        if (!$artifactDir) {
            $artifactDir = $this->artifactParams->get('artifact_dir');
        }
        $this->fs->remove($artifactDir);
    }

    /**
     * Removes the generated references from the local repo.
     */
    protected function cleanupLocal(FrozenParameterBag $instructions)
    {
        if ($deleteBranch = $instructions->get('create_branch')) {
            $this->runCommand("git branch -D $deleteBranch");
        }
        if ($deleteTag = $instructions->get('create_tag')) {
            $this->runCommand("git tag -d $deleteTag");
        }
    }

    /**
     * Pushes a reference from the artifact directory back into the main local
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
    protected function pushRemote(FrozenParameterBag $instructions)
    {
        $remote = $instructions->get('remote');
        if ($instructions->get('create_branch')) {
            $this->progress->setMessage("Pushing artifact branch to $remote.");
            $this->runCommand("git push --force $remote " . $instructions->get('create_branch'));
        }
        if ($instructions->get('create_tag')) {
            $this->progress->setMessage("Pushing artifact tag to $remote.");
            $this->runCommand("git push $remote tags/" . $instructions->get('create_tag'));
        }
    }

    /**
     * Creates a branch for the artifact in the artifact directory.
     */
    protected function createBranch(FrozenParameterBag $instructions)
    {
        $this->progress->setMessage('Creating branch to hold the artifact.');

        $this->repo->run('checkout', ['-b', 'artifact-' . $this->getCurrentBranch()]);
        $artifactDir = $instructions->get('artifact_dir');
        $this->fs->remove($artifactDir . '/.gitignore');
        $this->cleanSubmodules($artifactDir,'vendor');
        $this->cleanSubmodules($artifactDir,'docroot');
        $this->repo->run('add', ['-A']);
        $this->repo->run('commit', ['-m', $instructions->get('commit_message')]);

        $this->progress->advance();
    }

    /**
     * Creates the directory in which the artifact will be generated.
     */
    protected function createDirectory(FrozenParameterBag $instructions)
    {
        $this->progress->setMessage('Preparing artifact directory...');
        $this->fs->mkdir($instructions->get('artifact_dir'));
        $this->progress->advance();
    }

    /**
     * Removes all .git directories found in the provided directory.
     *
     * @param $artifactDir
     *   The name of the directory that contains the built artifact
     * @param $cleanDir
     *   The directory to clean relative to repo root.
     */
    protected function cleanSubmodules($artifactDir = 'artifact', $cleanDir) {
        $this->runCommand("find '$artifactDir/$cleanDir' -type d | grep '\.git' | xargs rm -rf");
    }

    /**
     * Initializes git and does a local checkout.
     */
    protected function intitalizeGit(FrozenParameterBag $instructions)
    {
        $this->progress->setMessage('Initializing git.');

        // Use the local repo as the source for cloning because the current
        // branch isn't necessarily pushed to a remote.
        $source = $this->runCommand('pwd');
        $artifactDir = $instructions->get('artifact_dir');
        $branch = $this->getCurrentBranch();

        $this->progress->advance();
        $this->progress->setMessage('Cloning local repo.');

        $this->runCommand("git clone --branch $branch $source $artifactDir");
        $this->repo = new Repository($artifactDir);

        $this->progress->advance();
    }

    /**
     * Builds a production-optimized codebase with Composer.
     */
    protected function make(FrozenParameterBag $instructions)
    {
        $this->progress->setMessage('Building production-optimized codebase.');

        $this->runCommand(
            'composer install --no-dev --optimize-autoloader',
            NULL,
            $instructions->get('artifact_dir')
        );

        $this->progress->advance();

        $this->progress->setMessage('Building frontend assets.');
        $this->makeFrontend();
        $this->progress->advance();
    }

    /**
     * An array of configured git remotes.
     *
     * @return array
     *   The name(s) of the configured git remote.
     */
    protected function getGitRemotes()
    {
        return explode("\n", $this->runCommand("git remote"));
    }

    /**
     * @return string
     *   The name of the current checkout branch in the source repository.
     */
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
     * @throws \RuntimeException
     *   Thrown if there are uncommitted changes.
     */
    protected function checkDirty(InputInterface $input)
    {
        $allow_dirty = $input->getOption('allow_dirty');
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
     * Builds frontend assets.
     */
    protected function makeFrontend()
    {
        // @todo
    }

    /**
     * Sets the commit message to be used for committing deployment artifact.
     * Defaults to the last commit message on the source branch.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     */
    public function setCommitMessage(InputInterface $input)
    {
        $commit_msg_option = $input->getOption('commit_msg');
        $git_last_commit_message = $this->getLastCommitMessage();
        if ($commit_msg_option) {
            $this->say("Commit message is set to <comment>$commit_msg_option</comment>.");
            $this->artifactParams->set('commit_message', $commit_msg_option);
        } elseif (!$this->artifactParams->get('create_branch') && !$this->artifactParams->get('save_artifact')) {
            // No need to ask about the commit message if we're not saving the
            // branch and deleting the artifact directory since it would never
            // be seen.
            $this->artifactParams->set('commit_message', $git_last_commit_message);
        } else {
            $this->artifactParams->set('commit_message',
                $this->getIO()->ask(
                    "Enter a valid commit message [<comment>$git_last_commit_message</comment>]",
                    $git_last_commit_message
                )
            );
        }
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param string $ref
     *   The noun(s) representation of the references to create.
     */
    protected function normalizeRefs(InputInterface $input, $ref)
    {
        switch (strtolower($ref)) {
            case 'branch':
                $branchName = 'artifact-' . $this->getCurrentBranch();
                $this->artifactParams->set('create_branch', $branchName);
                break;
            case 'tag':
                $this->setTagName($input);
                break;
            case 'branch and tag':
                $branchName = 'artifact-' . $this->getCurrentBranch();
                $this->artifactParams->set('create_branch', $branchName);
                $this->setTagName($input);
                break;
            default:
                throw new InvalidArgumentException("$ref is not a valid Reference.");
        }
    }

    /**
     * @param string $tagName
     *   The default if the user doesn't enter a value.
     *
     * @return string
     */
    protected function askTagName($tagName)
    {
        return $this->getIO()->ask(
            "Enter a name for the tag. E.g. <comment>v1.0.0</comment>. Otherwise it will default to the current commit hash. [<comment>$tagName</comment>]",
            $tagName
        );
    }

    protected function askRemote()
    {
        return $this->getIO()->askConfirmation(
            'Would you like to push the resulting ' . $this->artifactParams->get('refs_noun') . ' to a remote repo? [<comment>no</comment>] ',
            false
        );
    }

    /**
     * @param array $remotes
     *   The options to present to the user.
     *
     * @return string
     *   The selected remote.
     */
    protected function askWhichRemote(array $remotes)
    {
        return $this
            ->getIO()
            ->select(
                'Which remote would you like to push the references to? [<comment>' . reset($remotes) . '</comment>] ',
                $remotes,
                reset($remotes)
            );
    }

    protected function askOutputRefs(InputInterface $input)
    {
        $choices = [
            'Branch',
            'Tag',
            'Branch and Tag',
        ];
        $refs = $this->getIO()->select(
            'Do you want to create a branch, tag, or both? [<comment>[<info>1</info>] Tag</comment>]',
            $choices,
            'Tag'
        );
        $this->artifactParams->set('refs_noun', $refs);
        return $this->normalizeRefs($input, $this->artifactParams->get('refs_noun'));
    }

    protected function askCleanup()
    {
        $this->artifactParams->set(
            'save_artifact',
            $this
                ->getIO()
                ->askConfirmation(
                    'Would you like to save the generated artifact directory? [<comment>no</comment>] ',
                    false
                )
        );

        if ($this->determineAskCleanupLocal()) {
            $this->artifactParams->set(
                'cleanup_local',
                $this
                    ->getIO()
                    ->askConfirmation(
                        'By default, the generated ' . $this->artifactParams->get('refs_noun') . ' will also be saved to the source repository. Would you like to clean up these references? [<comment>no</comment>] ',
                        false
                    )
            );
        }
    }

    /**
     * Verifies that the provided remote name is actually configured as a remote
     * in the local repo.
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

    /**
     * Sets the name of the tag to be used when create a tag reference of the
     * artifact. Defaults to the commit hash of the source repo commit.
     *
     * @param InputInterface $input
     */
    public function setTagName(InputInterface $input)
    {
        if ($tag_name_option = $input->getOption('create_tag')) {
            $this->say("Tag name is set to <comment>$tag_name_option</comment>.");
            $this->artifactParams->set('create_tag', $tag_name_option);
        } else {
            $tagName = 'artifact-' . $this->runCommand('git rev-parse --short HEAD');
            $this->artifactParams->set(
                'create_tag',
                $this->askTagName($tagName)
            );
        }
    }

    /**
     * Wrapper method around Symfony's ProcessExecutor.
     *
     * @param string $command
     *   The command to run.
     * @param string $error_msg
     *   A custom error to pass to the exception if the command returns a
     *   non-zero status.
     * @param string $cwd
     *   The directory in which to run the command.
     *
     * @throws \RuntimeException
     *   If the command returns a non-zero exit status.
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
     * The last commit message from the local git repo on the current branch.
     *
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

    public function getCommitMessage()
    {
        return $this->artifactParams->get('commit_message');
    }
    public function getTagName()
    {
        return $this->artifactParams->get('create_tag');
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
     * It only makes sense to ask if the local refs should be cleaned up under
     * certain circumstances. E.g., if we don't push to a remote, and don't save
     * the artifact directory (i.e. row 1 below), then we must want to keep the
     * local refs (otherwise there would be no result).
     *
     *   remote | save artifact | result
     *   ----------------------------------
     *   false  | false         | don't ask
     *   false  | true          | ask
     *   true   | false         | ask
     *   true   | true          | ask
     */
    protected function determineAskCleanupLocal()
    {
        if ($this->artifactParams->get('remote') === null && $this->artifactParams->get('save_artifact') === false) {
            return false;
        }
        return true;
    }

    /**
     * Formats a set of strings with an Oxford comma.
     */
    protected function oxford(array $items, $conjunction = 'and')
    {
        $count = count($items);
        if ($count < 2) {
            return (string) reset($items);
        }
        elseif ($count === 2) {
            return reset($items) . ' ' . $conjunction . ' ' . end($items);
        }
        else {
            $items[] = $conjunction . ' ' . array_pop($items);
            return implode(', ', $items);
        }
    }

    /**
     * Returns plural or singular word based on provided count.
     */
    protected function plural($n, array $form = ['singular' => 'is', 'plural' => 'are'])
    {
        if ($n < 2) {
            return $form['singular'];
        }
        return $form['plural'];
    }

}
