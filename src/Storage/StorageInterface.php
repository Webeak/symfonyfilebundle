<?php
namespace Webeak\Bundle\FileBundle\Storage;

use Symfony\Component\Console\Output\OutputInterface;
use Webeak\Bundle\FileBundle\ManagedFile;
use Webeak\Bundle\FileBundle\PublicFile;

/**
 * Base interface all storages must implement.
 */
interface StorageInterface
{
    /**
     * Clear files for which the expiration date has been reached.
     *
     * @param OutputInterface $output
     */
    public function clearExpiredFiles(OutputInterface $output = null);

    /**
     * Load a file from the storage
     *
     * @param string $identifier
     *
     * @return ManagedFile
     *
     * @throws
     */
    public function load(string $identifier);

    /**
     * Load a file from the storage using its unique hash.
     * \! WARNING !/ Files with an expiration date will be ignored.
     *
     * @param string $hash
     *
     * @return ManagedFile|null
     */
    public function loadByHash(string $hash);

    /**
     * Persist a file in the storage to be saved.
     *
     * @param ManagedFile $file
     *
     * @return ManagedFile
     *
     * @throws
     */
    public function persist(ManagedFile $file);

    /**
     * Process all scheduled operations.
     *
     * @throws
     */
    public function flush();

    /**
     * Remove a file.
     *
     * @param ManagedFile|PublicFile|string $file file instance or identifier
     * @param boolean $force if `true`, ignore the usage count
     *
     * @throws
     */
    public function remove($file, bool $force = false);

    /**
     * Remove a version of a file.
     *
     * @param ManagedFile|PublicFile|string $file
     * @param string|array                  $version
     *
     * @throws
     */
    public function removeVersion($file, $version);

    /**
     * Remove the expiration date of a file.
     *
     * @param ManagedFile|PublicFile|string $file
     *
     * @throws
     */
    public function removeExpirationDate($file);

    /**
     * List files matching certain criteria.
     */
    public function find(int $offset, array $filters = [], int $maxResults = 20): array;
}
