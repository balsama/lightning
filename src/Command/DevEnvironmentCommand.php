<?php

namespace Drupal\lightning\Command;

use Composer\Util\Git;
use Doctrine\Common\Collections\ArrayCollection;
use Drupal\Console\Command\Shared\ConfirmationTrait;
use Drupal\Console\Core\Command\Shared\CommandTrait;
use Drupal\Console\Core\Generator\Generator;
use Drupal\Console\Core\Style\DrupalStyle;
use Drupal\Console\Core\Utils\StringConverter;
use Drupal\Console\Core\Utils\TwigRenderer;
use Drupal\Console\Utils\TranslatorManager;
use Drupal\Console\Utils\Validator;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\InfoParserInterface;
use Drupal\Core\Utility\ProjectInfo;
use Drupal\Driver\Exception\Exception;
use Drupal\lightning\ComponentDiscovery;
use Drupal\lightning_core\Element;
use Robo\Robo;
use Robo\Task\Vcs\GitStack;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Creates an environment suitable for development by symlink-ing Lightning
 * components to existing local copies of their respective repos. This script
 * requires that you have set up each of Lightning's components in sibling
 * directories.
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
  protected $levelsUp = 2;

  /**
   * The string converter.
   *
   * @var \Drupal\Console\Core\Utils\StringConverter
   */
  protected $stringConverter;

  /**
   * The validation service.
   *
   * @var \Drupal\Console\Utils\Validator
   */
  protected $validator;

  /**
   * The info file parser.
   *
   * @var \Drupal\Core\Extension\InfoParserInterface
   */
  protected $infoParser;

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
  protected $expected_branch = '8.x-1.x';

  /**
   * Components which have been successfully symlinked.
   *
   * $var string[]
   */
  protected $successfullySymlinked = [];

  /**
   * DevEnvironmantCommand constructor.
   *
   * @param \Drupal\Console\Core\Utils\StringConverter $string_converter
   *   The string converter.
   * @param \Drupal\Console\Utils\Validator $validator
   *   The validation service.
   * @param string $app_root
   *   The Drupal application root.
   * @param \Drupal\Core\Extension\InfoParserInterface $info_parser
   *   The info file parser.
   * @param \Drupal\Console\Utils\TranslatorManager $translator
   *   (optional) The translator manager.
   */
  public function __construct(
    StringConverter $string_converter,
    Validator $validator,
    $app_root,
    InfoParserInterface $info_parser,
    TranslatorManager $translator = NULL
  ) {
    parent::__construct('lightning:devenv');

    $this->componentDiscovery = new ComponentDiscovery($app_root);
    $this->infoParser = $info_parser;

    $this->stringConverter = $string_converter;
    $this->validator = $validator;
    $this->appRoot = $app_root;

    $app_root_dirs = explode('/', $app_root);
    for ($count = 0; $count < $this->levelsUp; $count++) {
      array_pop($app_root_dirs);
    }
    $this->externalComponentDir = implode('/', $app_root_dirs);

    $this->fs = new Filesystem();

    // For reasons I can't yet figure out, adding the DrupalCommand annotation
    // to this class, which would allow translations to be loaded automatically,
    // causes the command to be unrecognized by Drupal Console. Which is
    // disturbing...but we can work around it here.
    if ($translator) {
      $translator->addResourceTranslationsByExtension('lightning', 'module');
    }

    $this->mainComponents = $this->componentDiscovery->getMainComponents();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $this->setDescription($this->trans('commands.lightning.devenv.description'));
  }

  /**
   * {@inheritdoc}
   */
  protected function initialize(InputInterface $input, OutputInterface $output) {
    parent::initialize($input, $output);
  }

  /**
   * {@inheritdoc}
   */
  protected function interact(InputInterface $input, OutputInterface $output) {
    $io = new DrupalStyle($input, $output);

  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $io = new DrupalStyle($input, $output);

    if ($this->confirmGeneration($io)) {
      $this->confirmAllExternalComponentsExist();
      $this->symlinkAllExternalComponents();
      $problems = $this->getComponentsGitStatus();
      if (isset($problems['dirty'])) {
        $io->caution('The following components have uncommitted changes: ' .  Element::oxford($problems['dirty']) . ' You should commit or stash the changes before continuing.');
        // @todo Add option to reset and clean repo.
      }
      if (isset($problems['branch'])) {
        $io->caution('The following components are not on the expected branch ' . $this->expected_branch . ': ' . Element::oxford($problems['branch']));
        // @todo Add option to checkout expected branch.
      }

      $io->success('Successfully symlinked ' . count($this->successfullySymlinked) . ' of ' . count($this->mainComponents) . ' Lightning components: ' . Element::oxford($this->successfullySymlinked));
    }
  }

  /**
   * Confirms that all external components exist in the expected place.
   */
  protected function confirmAllExternalComponentsExist() {
    foreach ($this->mainComponents as $component) {
      $this->confirmExisting($component);
    }
  }

  /**
   * Removes existing component directories and symlinks out to external
   * components.
   */
  protected function symlinkAllExternalComponents() {
    foreach ($this->mainComponents as $component) {
      $this->fs->remove($this->appRoot . '/modules/contrib/' . $component->getName());
      $this->fs->symlink($this->externalComponentDir . '/' . $component->getName(), $this->appRoot . '/modules/contrib/' . $component->getName());
      $this->successfullySymlinked[] = $component->getName();
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
    foreach ($this->mainComponents as $component) {
      if ($this->runCommand('git status --porcelain', $this->externalComponentDir . '/' . $component->getName())) {
        // Add the component to the "dirty" list if git status returns anything.
        $problems['dirty'][] = $component->getName();
      }
      $current_branch = $this->runCommand('git rev-parse --abbrev-ref HEAD', $this->externalComponentDir . '/' . $component->getName());
      if ($current_branch != $this->expected_branch) {
        // Add the component to "branch" list if it doesn't have the expected
        // branch checked out.
        $problems['branch'][] = $component->getName();
      }
    }

    return $problems;
  }

  /**
   * Confirms that a given extension exists in a sibling directory of the same
   * name.
   *
   * @param Extension $component
   *   The extension to check.
   *
   * @return boolean
   *   False if the components doesn't exist as a sibling to this repo.
   */
  protected function confirmExisting(Extension $component) {
    if (!$this->fs->exists($this->externalComponentDir . '/' . $component->getName())) {
      throw new IOException($component->getName() . ' not found as a sibling. Expected at ' . $this->externalComponentDir . '/' . $component->getName());
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
   * {@inheritdoc}
   */
  public function confirmGeneration(DrupalStyle $io, $yes = false)
  {
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

}