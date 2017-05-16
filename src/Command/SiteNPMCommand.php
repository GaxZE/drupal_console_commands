<?php

/**
 * @file
 * Contains \DennisDigital\Drupal\Console\Command\SiteNPMCommand.
 *
 * Runs NPM.
 */

namespace DennisDigital\Drupal\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use DennisDigital\Drupal\Console\Exception\SiteCommandException;

/**
 * Class SiteNPMCommand
 *
 * @package DennisDigital\Drupal\Console\Command
 */
class SiteNPMCommand extends SiteBaseCommand {

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    parent::configure();

    $this->setName('site:npm')
      ->setDescription('Runs NPM.');
  }

  /**
   * {@inheritdoc}
   */
  protected function interact(InputInterface $input, OutputInterface $output) {
    parent::interact($input, $output);

  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    parent::execute($input, $output);

    $this->io->comment(sprintf('Running NPM on %s',
      $this->destination
    ));

    $command = sprintf(
      'cd %sweb && ' .
      'find . -type d \( -name node_modules -o -name contrib -o -path ./core \) -prune -o -name package.json -execdir sh -c "npm install" \;',
      $this->shellPath($this->destination)
    );

    // Run.
    $shellProcess = $this->getShellProcess();

    if ($shellProcess->exec($command, TRUE)) {
      $this->io->writeln($shellProcess->getOutput());
      $this->io->success('NPM job completed');
    }
    else {
      throw new SiteCommandException($shellProcess->getOutput());
    }
  }

}
