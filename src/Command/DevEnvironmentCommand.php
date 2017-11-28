<?php

namespace Drupal\lightning\Command;

use Drupal\Console\Annotations\DrupalCommand;
use Drupal\Console\Command\Shared\ConfirmationTrait;
use Drupal\Console\Core\Command\Shared\CommandTrait;
use Drupal\Console\Core\Style\DrupalStyle;
use Drupal\Console\Utils\TranslatorManager;
use Drupal\Core\Extension\Extension;
use Drupal\lightning\ComponentDiscovery;
use Drupal\lightning_core\Element;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Creates an environment suitable for development by symlink-ing Lightning
 * components to existing local copies of their respective repos. This script
 * requires that you have set up each of Lightning's components in some parent
 * directory of this repo.
 *
 * @DrupalCommand
 */
class DevEnvironmentCommand extends Command {

  use CommandTrait;
  use ConfirmationTrait;

  /**
   * The Lightning component discovery helper.
   *
   * @var \Drupal\lightning\ComponentDiscovery
   */
  protected $componentDiscovery;

  /**
   * The Drupal application root.
   *
   * @var string
   */
  protected $appRoot;

  /**
   * The directory that contains the external components.
   *
   * @var string
   */
  protected $externalComponentDir;

  /**
   * Home many directory levels up the components are from the app root. Usually
   * two.
   *
   * @var int
   */
  protected $levelsUp;

  /**
   * The main, top-level components of Lightning.
   *
   * @var Extension[]
   */
  protected $mainComponents;

  /**
   * The symfony filesystem.
   *
   * @var Filesystem
   */
  protected $fs;

  /**
   * The expected git branch for the external projects.
   *
   * @var string
   */
  protected $expected_branch;

  /**
   * The expected prefix to append to external directory names when searching
   * for them.
   *
   * @var string
   */
  protected $prefix = '';

  /**
   * Components which have been successfully symlinked.
   *
   * $var string[]
   */
  protected $successfullySymlinked = [];

  /**
   * DevEnvironmantCommand constructor.
   *
   * @param string $app_root
   *   The Drupal application root.
   * @param \Drupal\Console\Utils\TranslatorManager $translator
   *   (optional) The translator manager.
   */
  public function __construct(
    $app_root,
    TranslatorManager $translator = NULL
  ) {
    parent::__construct('lightning:devenv');

    $this->componentDiscovery = new ComponentDiscovery($app_root);
    $this->appRoot = $app_root;

    $this->fs = new Filesystem();
    $this->mainComponents = $this->componentDiscovery->getMainComponents();
  }

  /**
   * {@inheritdoc}
   */
  protected function initialize(InputInterface $input, OutputInterface $output) {
    parent::initialize($input, $output);
    $options = $input->getOptions();

    if ($options['levels_up']) {
      $this->validateLevelsUp($options['levels_up']);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $this
      ->setDescription($this->trans('commands.lightning.devenv.description'))
      ->addOption(
        'levels_up',
        NULL,
        InputOption::VALUE_REQUIRED
      )
      ->addOption(
        'branch',
        NULL,
        InputOption::VALUE_REQUIRED
      );

    // Use argument for Prefix to make it easy to exclude.
    $this->addArgument(
      'prefix',
      InputArgument::OPTIONAL
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function interact(InputInterface $input, OutputInterface $output) {
    $io = new DrupalStyle($input, $output);
    $options = $input->getOptions();

    // Get the number of levels up the external components are from docroot from
    // the --levels_up option, or ask if none was specified.
    $env = $options['levels_up'] ?: $io->ask(
        $this->trans('commands.lightning.devenv.questions.levels_up'),
        2,
        [$this, 'validateLevelsUp']
    );
    $input->setOption('levels_up', $env);

    // Get the expected branch name for the external components from the
    // --branch option or ask if none was specified.
    $env = $options['branch'] ?: $io->ask(
      $this->trans('commands.lightning.devenv.questions.branch'),
      '8.x-1.x'
    );
    $input->setOption('branch', $env);

    $this->setExternalExternsionDirectory($input->getOption('levels_up'));
    $this->expected_branch = $input->getOption('branch');
    if ($input->hasArgument('prefix')) {
      $this->prefix = $input->getArgument('prefix');
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $io = new DrupalStyle($input, $output);

    if ($this->confirmGeneration($io)) {
      $this->confirmAllExternalComponentsExist();
      $this->symlinkAllExternalComponents();
      $git_problems = $this->getComponentsGitStatus();
      if (isset($git_problems['dirty'])) {
        $io->caution(
          sprintf(
            $this->trans('commands.lightning.devenv.caution.dirty-branch'),
            Element::oxford($git_problems['dirty'])
          )
        );
        // @todo Add option to reset and clean repo?
      }
      if (isset($git_problems['branch'])) {
        $io->caution(
          sprintf(
            $this->trans('commands.lightning.devenv.caution.wrong-branch'),
            $this->expected_branch,
            Element::oxford($git_problems['branch'])
          )
        );
        // @todo Add option to checkout expected branch?
      }

      $io->success(
        sprintf(
          $this->trans('commands.lightning.devenv.success'),
          count($this->successfullySymlinked),
          count($this->mainComponents),
          Element::oxford($this->successfullySymlinked)
        )
      );
    }
  }

  /**
   * Removes existing component directories and symlinks out to external
   * components.
   */
  protected function symlinkAllExternalComponents() {
    foreach ($this->mainComponents as $extension) {
      $this->fs->remove($this->appRoot . '/modules/contrib/' . $extension->getName());
      $this->fs->symlink($this->getExternalExtensionPath($extension), $this->appRoot . '/modules/contrib/' . $extension->getName());
      $this->successfullySymlinked[] = $extension->getName();
    }
  }

  /**
   * Populates and returns ArrayCollection with any problems in the external
   * extension's git repos.
   *
   * @return array
   *   An array containing up to two top-level keys, "dirty" and "branch" with
   *   components that have those problems contained within.
   */
  protected function getComponentsGitStatus() {
    $problems = [];
    foreach ($this->mainComponents as $extension) {
      if ($this->runCommand('git status --porcelain', $this->getExternalExtensionPath($extension))) {
        // Add the extension to the "dirty" list if git status returns anything.
        $problems['dirty'][] = $extension->getName();
      }
      $current_branch = $this->runCommand('git rev-parse --abbrev-ref HEAD', $this->getExternalExtensionPath($extension));
      if ($current_branch != $this->expected_branch) {
        // Add the extension to "branch" list if it doesn't have the expected
        // branch checked out.
        $problems['branch'][] = $extension->getName();
      }
    }

    return $problems;
  }

  /**
   * Confirms that all external components exist in the expected place.
   */
  protected function confirmAllExternalComponentsExist() {
    foreach ($this->mainComponents as $extension) {
      $this->confirmExisting($extension);
    }
  }

  /**
   * Confirms that a given extension exists in the expected external extension
   * directory.
   *
   * @param Extension $extension
   *   The extension to check.
   *
   * @throws \IOException
   *    If the extension is not found in the expected directory.
   */
  protected function confirmExisting(Extension $extension) {
    if (!$this->fs->exists($this->getExternalExtensionPath($extension))) {
      throw new IOException(
        sprintf(
          $this->trans('commands.lightning.devenv.exception.extension-not-found'),
          $extension->getName(),
          $this->getExternalExtensionPath($extension)
        )
      );
    }
  }

  /**
   * Run a command through symfony Process.
   *
   * @param string $command
   *   The command to run.
   * @param string $working_directory
   *   The directory in which to run the command.
   *
   * @return string
   *   Output from the command, if any.
   */
  protected function runCommand($command, $working_directory) {
    $process = new Process($command);
    $process
      ->setWorkingDirectory($working_directory)
      ->run();
    return trim($process->getOutput());
  }

  /**
   * Gets the external path of a Lightning extension.
   *
   * @param \Drupal\Core\Extension\Extension $extension
   *   The extension you want the external path for.
   *
   * @return string
   *   The absolute path to the external extension.
   */
  protected function getExternalExtensionPath(Extension $extension) {
    return $this->externalComponentDir . '/' . $this->prefix . $extension->getName();
  }

  /**
   * Sets the expected external components directory based on how many levels up
   * from docroot the user said they were.
   *
   * @param int $levels_up
   */
  protected function setExternalExternsionDirectory($levels_up) {
    $app_root_dirs = explode('/', $this->appRoot);
    for ($count = 0; $count < $levels_up; $count++) {
      array_pop($app_root_dirs);
    }
    $this->externalComponentDir = implode('/', $app_root_dirs);
  }

  /**
   * {@inheritdoc}
   */
  public function confirmGeneration(DrupalStyle $io, $yes = false) {
    if ($yes) {
      return $yes;
    }

    $confirmation = $io->confirm(
      $this->trans('commands.lightning.devenv.questions.confirm'),
      true
    );

    if (!$confirmation) {
      $io->warning($this->trans('commands.common.messages.canceled'));
    }

    return $confirmation;
  }

  /**
   * Levels up is used to determine which directory the external extensions
   * should be found in. It must be an integer and greater than or equal to two.
   *
   * @param mixed $value
   *
   * @return int
   */
  public function validateLevelsUp($value) {
    $value = (int)$value;
    if ($value < 2) {
      throw new InvalidOptionException(
        $this->trans('commands.lightning.devenv.exception.levels-up')
      );
    }
    return $value;
  }

}
