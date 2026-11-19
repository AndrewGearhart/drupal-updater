<?php

namespace DrupalUpdater\CheckRun;

/**
 * Reads package identities without relying on installed package metadata.
 *
 * @phpstan-type LockedPackage array{version: string, source: ?string, dist: ?string, type: string}
 */
final class Lockfile {

  /**
   * Reads production and development packages, including source references.
   *
   * @return array<string, LockedPackage>
   *   Locked packages keyed by name.
   */
  public static function packages(string $directory): array {
    $lock = json_decode(Workspace::read($directory . '/composer.lock'), TRUE, 512, JSON_THROW_ON_ERROR);
    if (!is_array($lock) || !isset($lock['packages']) || !is_array($lock['packages']) || !is_array($lock['packages-dev'] ?? [])) {
      throw new \RuntimeException('Invalid Composer lock file.');
    }
    $packages = [];
    foreach (array_merge($lock['packages'], $lock['packages-dev'] ?? []) as $package) {
      if (!is_array($package) || !isset($package['name'], $package['version']) || !is_string($package['name']) || !is_string($package['version'])) {
        throw new \RuntimeException('Invalid locked package.');
      }
      $source = $package['source']['reference'] ?? NULL;
      $dist = $package['dist']['reference'] ?? NULL;
      $packages[$package['name']] = [
        'version' => $package['version'],
        'source' => is_string($source) ? $source : NULL,
        'dist' => is_string($dist) ? $dist : NULL,
        'type' => isset($package['type']) && is_string($package['type']) ? $package['type'] : 'library',
      ];
    }
    ksort($packages);
    return $packages;
  }

  /**
   * Formats a package, retaining refs when versions cannot distinguish it.
   *
   * @param LockedPackage|null $package
   *   The package, or NULL when absent.
   */
  public static function describe(?array $package): string {
    if ($package === NULL) {
      return '(absent)';
    }
    $references = array_unique(array_filter([$package['source'], $package['dist']]));
    return $package['version'] . ($references === [] ? '' : ' [' . implode(', ', array_map(fn (string $ref) => substr($ref, 0, 10), $references)) . ']');
  }

  /**
   * Whether the lock changed beyond rebasing its manifest's content hash.
   */
  public static function changed(string $before, string $after): bool {
    $old = json_decode($before, TRUE, 512, JSON_THROW_ON_ERROR);
    $new = json_decode($after, TRUE, 512, JSON_THROW_ON_ERROR);
    unset($old['content-hash'], $new['content-hash']);
    return $old !== $new;
  }

}
