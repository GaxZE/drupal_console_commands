<?php

/**
 * @file
 * Contains \VM\Console\Develop\SiteBuildCommand.
 */

namespace VM\Console\Command\Develop;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Validator\Constraints;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Drupal\Console\Command\Shared\CommandTrait;
use Drupal\Console\Style\DrupalStyle;
use Drupal\Console\Config;

/**
 * Class SiteBuildCommand
 *
 * @package VM\Console\Command\Develop
 */
class SiteBuildCommand extends Command {
  use CommandTrait;

  /**
   * IO interface.
   *
   * @var null
   */
  private $io = NULL;

  /**
   * Global location for sites.yml.
   *
   * @var array
   */
  private $configFile = NULL;

  /**
   * Stores the contents of sites.yml.
   *
   * @var array
   */
  private $config = NULL;

  /**
   * Stores the site name.
   *
   * @var string
   */
  private $siteName = NULL;

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $this->setName('site:build')
      // @todo use: ->setDescription($this->trans('commands.site.build.description'))
      ->setDescription('Check out a site and installs composer.json')
      ->addArgument(
        'site-name',
        InputArgument::REQUIRED,
        // @todo use: $this->trans('commands.site.build.site-name.description')
        'The site name that is mapped to a repo in sites.yml'
      )->addOption(
        'destination-directory',
        '-D',
        InputOption::VALUE_OPTIONAL,
        // @todo use: $this->trans('commands.site.build.site-name.options')
        'Specify the destination of the site build if different than the global destination found in sites.yml'
      )->addOption(
        'ignore-changes',
        '',
        InputOption::VALUE_NONE,
        // @todo use: $this->trans('commands.site.build.ignore-changes')
        'Ignore local changes when building the site'
      )->addOption(
        'branch',
        '-B',
        InputOption::VALUE_OPTIONAL,
        // @todo use: $this->trans('commands.site.build.branch')
        'Specify which branch to checkout if different than the global branch found in sites.yml'
      );
  }

  /**
   * {@inheritdoc}
   */
  protected function interact(InputInterface $input, OutputInterface $output) {
    $this->siteName = $input->getArgument('site-name');

    $io = new DrupalStyle($input, $output);
    $ymlFile = new Parser();
    $config = new Config($ymlFile);
    $configFile = $config->getUserHomeDir() . '/.console/sites.yml';

    // Check if configuration file exists.
    if (!file_exists($configFile)) {
      $io->error(sprintf('Could not find any configuration in %s', $configFile));
      exit;
    }
    $this->configFile = $configFile;
    $this->config = $config->getFileContents($configFile);

    if (empty($this->siteName)) {
      $io->writeln(sprintf('Site not found in /.console/sites.yml'));
      $io->writeln(sprintf('Available sites: [%s]', implode(', ',
          array_keys($this->config['sites'])))
      );
      exit;
    }

    // Load site config from sites.yml.
    if (!isset($this->config['sites'][$this->siteName])) {
      $io->error(sprintf('Could not find any configuration for %s in %s',
          $this->siteName,
          $this->configFile)
      );
      exit;
    }

    $siteConfig = $this->config['sites'][$this->siteName];
    $repo = $siteConfig['repo'];

    // Loads default branch settings.
    $branch = NULL;
    if (isset($repo['branch'])) {
      $branch = $repo['branch'];
    }
    // Overrides default branch.
    if ($input->getOption('branch')) {
      $branch = $input->getOption('branch');
    }
    $input->setOption('branch', $branch);

    // Load default destination directory.
    $dir = '/tmp/' . $this->siteName . '/';
    if (isset($this->config['global']['destination-directory'])) {
      $dir = $this->config['global']['destination-directory'] .
        '/' . $this->siteName . '/';
    }
    // Overrides default destination directory.
    if ($input->getOption('destination-directory')) {
      $dir = $input->getOption('destination-directory');
    }
    $input->setOption('destination-directory', $dir);
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {

    $this->io = new DrupalStyle($input, $output);

    $siteConfig = $this->config['sites'][$this->siteName];
    $repo = $siteConfig['repo'];
    $branch = $input->getOption('branch');

    $destination = $input->getOption('destination-directory');
    // Make sure we have a slash at the end.
    if (substr($destination, -1) != '/') {
      $destination .= '/';
    }

    $this->io->writeln(sprintf('Building %s (%s) on %s',
      $this->siteName,
      $branch,
      $destination
    ));

    switch ($repo['type']) {
      case 'git':

        // Check if repo exists and has any changes.
        if (file_exists($destination) && file_exists($destination . '.' . $repo['type'])) {
          if (!$input->getOption('ignore-changes')) {
            $this->repoDiff($destination);
          }
          // Check out branch on existing repo.
          $this->repoCheckout($branch, $destination);
        }
        else {
          // Clone repo.
          $this->repoClone($branch, $repo['url'], $destination);
        }

        // Build site.
        if (!file_exists($destination . 'composer.json')) {
          $this->io->error(sprintf('The file composer.json is missing on %s', $destination));
          exit;
        }
        else {
          // Run composer install.
          $this->composerInstall($destination);
        }
        break;

      default:
        $this->io->error(sprintf('Repo commands for %s not implemented.',
          $repo['type']));
    }
  }

  /**
   * Helper to detect local modifications.
   *
   * @param $directory Directory containing the git folder.
   */
  protected function repoDiff($directory) {
    $command = sprintf(
      'cd %s; git diff-files --name-status -r --ignore-submodules',
      $directory
    );

    $shellProcess = $this->get('shell_process');

    if ($shellProcess->exec($command, TRUE)) {
      if (!empty($shellProcess->getOutput())) {
        $this->io->error(sprintf('You have uncommitted changes on %s' . PHP_EOL .
            'Please commit or revert your changes before building the site.',
            $directory)
        );
        $this->io->writeln('If you want to build the site without committing the changes use --ignore-changes.');
        $this->io->newLine();
        exit;
      }
    }
    else {
      // Show error message.
      $this->io->error($shellProcess->getOutput());

      return FALSE;
    }

    return TRUE;
  }

  /**
   * Helper to do the actual clone command.
   *
   * @param $branch Branch name.
   * @param $repo Repo Url.
   * @param $destination Destination folder.
   *
   * @return bool TRUE or FALSE.
   */
  protected function repoClone($branch, $repo, $destination) {
    $command = sprintf('git clone --branch %s %s %s',
      $branch,
      $repo,
      $destination
    );
    $this->io->commentBlock($command);

    $shellProcess = $this->get('shell_process');

    if ($shellProcess->exec($command, TRUE)) {
      // All good, no output.
    }
    else {
      // Show error message.
      $this->io->error($shellProcess->getOutput());

      return FALSE;
    }

    return TRUE;
  }

  /**
   * Helper to check out a branch.
   *
   * @param $branch Branch name.
   * @param $destination Destination folder.
   *
   * @return bool TRUE or FALSE.
   */
  protected function repoCheckout($branch, $destination) {
    $command = sprintf('cd %s; git checkout -B %s',
      $destination,
      $branch
    );

    $shellProcess = $this->get('shell_process');

    if ($shellProcess->exec($command, TRUE)) {
      // All good, no output.
    }
    else {
      // Show error message.
      $this->io->error($shellProcess->getOutput());

      return FALSE;
    }

    return TRUE;
  }

  /**
   * Helper to run composer install.
   *
   * @param $destination The destination folder.
   *
   * @return bool TRUE or FALSE;
   */
  protected function composerInstall($destination) {
    $command = sprintf('cd %s; composer install',
      $destination
    );

    $shellProcess = $this->get('shell_process');

    if ($shellProcess->exec($command, TRUE)) {
      // All good, no output.
      $this->io->success('Composer install finished');
    }
    else {
      // Show error message.
      $this->io->error($shellProcess->getOutput());

      return FALSE;
    }

    return TRUE;
  }
}
