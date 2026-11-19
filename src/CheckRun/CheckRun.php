<?php

namespace DrupalUpdater\CheckRun;

use DrupalUpdater\ComposerState;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Resolves full and stepped locks without installing or invoking Drupal/Git.
 */
final class CheckRun {

  /**
   * Creates a check using the updater's Composer command runner.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   Console output.
   * @param \Closure(string, string): void $composer
   *   Runs Composer arguments in the given directory; throws on failure.
   * @param \Closure(string): bool $isDrupalExtension
   *   The normal updater's classification, querying the current stepped lock.
   */
  public function __construct(
    private OutputInterface $output,
    private \Closure $composer,
    private \Closure $isDrupalExtension,
  ) {}

  /**
   * Checks the selected sequence against an unrestricted full-update target.
   *
   * @param \DrupalUpdater\CheckRun\Workspace $workspace
   *   Isolated full and stepped inputs.
   * @param string[] $packages
   *   Selected package arguments in their normal execution order.
   * @param int|null $limit
   *   Successful changed-lock steps to process, or NULL/0 for no limit.
   *
   * @return int
   *   0: no risk detected; 1: incomplete; 2: potentially unsafe trajectory.
   */
  public function run(Workspace $workspace, array $packages, ?int $limit): int {
    $full = $workspace->path('full');
    $stepped = $workspace->path('stepped');
    $initial = Lockfile::packages($stepped);
    $this->output->writeln('Resolving the unrestricted full-update target (no installation).');
    ($this->composer)('update --no-install --no-progress', $full);
    $report = new Report($initial, Lockfile::packages($full));
    $completed = 0;
    $incomplete = FALSE;
    foreach (array_values($packages) as $offset => $package) {
      if (!empty($limit) && $completed >= $limit) {
        $this->output->writeln(sprintf('Reached limit of %d changed-lock steps.', $limit));
        break;
      }
      $index = $offset + 1;
      $this->output->writeln(sprintf('Resolving step %d: %s', $index, $package));
      $state = new ComposerState($stepped);
      $before_lock = Workspace::read($stepped . '/composer.lock');
      $before = Lockfile::packages($stepped);
      try {
        ($this->composer)('update ' . escapeshellarg($package) . ' --with-dependencies --no-install --no-progress', $stepped);
        $after = Lockfile::packages($stepped);
        $changed = Lockfile::changed($before_lock, Workspace::read($stepped . '/composer.lock'));
        $updb = $changed && ($this->isDrupalExtension)($package);
        $report->step($index, $package, $before, $after, $updb);
        if ($changed) {
          $completed++;
        }
      }
      catch (\Exception $exception) {
        $state->restore();
        $incomplete = TRUE;
        $this->output->writeln(sprintf('[incomplete] Step %d (%s): %s', $index, $package, $exception->getMessage()));
      }
    }
    $report->render($this->output);
    if ($incomplete) {
      $this->output->writeln('Check run incomplete: one or more stepped resolutions could not be checked.');
      return 1;
    }
    return $report->hasFindings() ? 2 : 0;
  }

}
