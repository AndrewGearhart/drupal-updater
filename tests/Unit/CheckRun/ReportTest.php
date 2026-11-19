<?php

namespace DrupalUpdater\Tests\Unit\CheckRun;

use DrupalUpdater\CheckRun\Report;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests qualified trajectory findings, independent of Composer's solver.
 *
 * @phpstan-import-type LockedPackage from \DrupalUpdater\CheckRun\Lockfile
 */
final class ReportTest extends TestCase {

  /**
   * Tests the Cikard-style intermediate dev version across an updb boundary.
   */
  public function testDevCoreReplacedAfterModuleUpdateIsFlagged(): void {
    $original = ['drupal/core' => $this->package('11.4.12')];
    $dev = ['drupal/core' => $this->package('11.x-dev')];
    $final = ['drupal/core' => $this->package('11.4.13')];
    $report = new Report($original, $final);
    $report->step(1, 'drupal/core-recommended', $original, $dev, FALSE);
    $report->step(2, 'drupal/google_tag', $dev, $dev, TRUE);
    $report->step(3, 'drupal/core*', $dev, $final, FALSE);

    self::assertTrue($report->hasFindings());
    $output = new BufferedOutput();
    $report->render($output);
    $text = $output->fetch();
    self::assertStringContainsString('[potentially unsafe] drupal/core: 11.x-dev -> 11.4.13', $text);
    self::assertStringContainsString('updb would run at step 2 (drupal/google_tag)', $text);
    self::assertStringContainsString('Pending database updates were not inspected', $text);
  }

  /**
   * Tests ordinary upgrades toward a target are informational.
   */
  public function testMonotonicUpdatesAndUntouchedPackagesAreNotFlagged(): void {
    $old = ['example/a' => $this->package('1.0.0'), 'example/b' => $this->package('1.0.0')];
    $middle = ['example/a' => $this->package('1.1.0'), 'example/b' => $this->package('1.0.0')];
    $target = ['example/a' => $this->package('1.2.0'), 'example/b' => $this->package('1.1.0')];
    $report = new Report($old, $target);
    $report->step(1, 'example/a', $old, $middle, TRUE);
    $report->step(2, 'example/a', $middle, $target, TRUE);
    self::assertFalse($report->hasFindings());
  }

  /**
   * Tests an invocation after a replacement does not expose the old version.
   */
  public function testBacktrackWithoutAnEarlierBoundaryIsNotFlagged(): void {
    $old = ['example/a' => $this->package('2.0.0')];
    $new = ['example/a' => $this->package('1.0.0')];
    $report = new Report($old, $new);
    $report->step(1, 'example/a', $old, $new, TRUE);
    self::assertFalse($report->hasFindings());
  }

  /**
   * Tests same-version reference replacements are not lost.
   */
  public function testReferenceReplacementAfterBoundaryIsFlagged(): void {
    $old = ['example/a' => $this->package('dev-main', 'aaaaaaaaaaa')];
    $new = ['example/a' => $this->package('dev-main', 'bbbbbbbbbbb')];
    $report = new Report($old, $new);
    $report->step(1, 'drupal/module', $old, $old, TRUE);
    $report->step(2, 'example/a', $old, $new, FALSE);
    self::assertTrue($report->hasFindings());
    $output = new BufferedOutput();
    $report->render($output);
    self::assertStringContainsString('aaaaaaaaaa', $output->fetch());
  }

  /**
   * Tests removals and reintroductions can cross database-update boundaries.
   */
  public function testRemovalAndReintroductionAreRecorded(): void {
    $original = ['example/a' => $this->package('1.0.0')];
    $report = new Report($original, $original);
    $report->step(1, 'example/remove', $original, [], FALSE);
    $report->step(2, 'drupal/module', [], [], TRUE);
    $report->step(3, 'example/reintroduce', [], $original, FALSE);
    self::assertTrue($report->hasFindings());
  }

  /**
   * Tests an ordinary addition is harmless until replaced across a boundary.
   */
  public function testAddedDependencyRemovedAfterAnInvocationIsFlagged(): void {
    $added = ['example/a' => $this->package('1.0.0')];
    $report = new Report([], []);
    $report->step(1, 'drupal/module', [], $added, TRUE);
    self::assertFalse($report->hasFindings());
    $report->step(2, 'example/remove', $added, [], FALSE);
    self::assertTrue($report->hasFindings());
  }

  /**
   * Creates a locked package identity.
   *
   * @return LockedPackage
   *   Locked package.
   */
  private function package(string $version, ?string $reference = NULL): array {
    return ['version' => $version, 'source' => $reference, 'dist' => NULL, 'type' => 'library'];
  }

}
