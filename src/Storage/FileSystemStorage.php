<?php
namespace Webeak\Bundle\FileBundle\Storage;

use Symfony\Component\HttpKernel\KernelInterface;
use Webeak\Bundle\ErrorTrackerBundle\ErrorTrackerInterface;
use Webeak\Bundle\EssentialBundle\Exception\InvalidArgumentException;
use Webeak\Bundle\EssentialBundle\Exception\InvalidConfigurationException;
use Webeak\Bundle\EssentialBundle\Exception\IOException;
use Webeak\Bundle\EssentialBundle\Exception\RuntimeException;
use Webeak\Bundle\FileBundle\Bridge\Doctrine\Orm\Entity\FileEntityInterface;
use Webeak\Bundle\FileBundle\Exception\FileNotFoundException;
use Webeak\Bundle\FileBundle\Configuration;
use Webeak\Bundle\FileBundle\File;
use Webeak\Bundle\FileBundle\FileSystem;
use Webeak\Bundle\FileBundle\ManagedFile;
use Webeak\Bundle\FileBundle\PublicFile;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Webeak\Bundle\SharedStorageBundle\LockInterface;
use Webeak\Bundle\SharedStorageBundle\SharedStorageInterface;
use Webeak\Component\Utils\ArrayUtils;

/**
 * Store files metadata in the filesystem.
 */
class FileSystemStorage implements StorageInterface
{
    private const METADATA_DIR = 'm';
    private const HASHES_DIR = 'h';
    private const EXPIRATION_DATES_STORAGE_KEY = 'file-bundle:expiring_files';

    /** @var ContainerInterface */
    private $container;

    /** @var SharedStorageInterface */
    private $sharedStorage;
    
    /** @var ErrorTrackerInterface */
    private $errorTracker;

    /** @var FileSystem */
    private $filesystem;

    /** @var FileEntityInterface[] */
    private $knownEntities;

    /** @var array */
    private $knownManagedFiles;

    /** @var ManagedFile[] */
    private $waitingForPersist;

    /** @var ManagedFile[] */
    private $waitingForRemove;

    /** @var array */
    private $waitingForFlush;

    /** @var array */
    private $expiringFiles;

    /** @var LockInterface */
    private $expiringFilesLock;

    /** @var string */
    private $metadataRootDir;

    public function __construct(ContainerInterface $container,
                                KernelInterface $kernel,
                                ErrorTrackerInterface $errorTracker,
                                FileSystem $filesystem)
    {
        $this->container = $container;
        $this->errorTracker = $errorTracker;
        $this->filesystem = $filesystem;
        $this->knownEntities = [];
        $this->knownManagedFiles = ['id' => [], 'hash' => []];
        $this->waitingForPersist = [];
        $this->waitingForRemove = [];
        $this->waitingForFlush = [];
        $this->expiringFiles = null;
        $this->expiringFilesLock = null;
        $this->sharedStorage = null;
        $this->metadataRootDir = $kernel->getProjectDir() . '/var/storage/wb-files/metadata';
    }

    /**
     * Shared storage is optional.
     * The container will set it through this method if available.
     *
     * @param SharedStorageInterface $sharedStorage
     */
    public function setSharedStorage(SharedStorageInterface $sharedStorage)
    {
        $this->sharedStorage = $sharedStorage;
    }

    /**
     * {@inheritdoc}
     */
    public function load(string $identifier)
    {
        if (array_key_exists($identifier, $this->knownManagedFiles['id'])) {
            return $this->knownManagedFiles['id'][$identifier];
        }
        // Persist will prevent to store files with less than 4 characters as identifiers.
        if (strlen($identifier) >= 4) {
            $path = $this->getMetadataPathForIdentifier($identifier);
            if (file_exists($path)) {
                $managedFile = $this->createManagedFileFromMetadataPath($path);
                if ($managedFile instanceof ManagedFile) {
                    $this->knownManagedFiles['id'][$identifier] = $managedFile;
                    $this->knownManagedFiles['hash'][$managedFile->getHash()] = $managedFile;
                    return $managedFile;
                }
            }
        }
        $this->errorTracker->trackAndThrow(new FileNotFoundException(
            sprintf('No file id "%s" has been found. It may have been removed.', $identifier)
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function loadByHash(string $hash)
    {
        if (array_key_exists($hash, $this->knownManagedFiles['hash'])) {
            return $this->knownManagedFiles['hash'][$hash];
        }
        if (strlen($hash) > 9) {
            $path = $this->getMetadataPathForHash($hash);
            if (file_exists($path)) {
                $identifier = @file_get_contents($path);
                if ($identifier) {
                    $managedFile = $this->load($identifier);
                    if ($managedFile instanceof ManagedFile) {
                        $this->knownManagedFiles['id'][$identifier] = $managedFile;
                        $this->knownManagedFiles['hash'][$managedFile->getHash()] = $managedFile;
                        if (!$managedFile->getExpirationDate()) {
                            return $managedFile;
                        }
                    }
                }
            }
        }
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function persist(ManagedFile $file)
    {
        $identifier = $file->getIdentifier();
        if (strlen($identifier) < 4) {
            $this->errorTracker->trackAndThrow(new InvalidArgumentException('Invalid file identifier. Identifiers must be at least 4 characters long.'));
        }
        $this->waitingForFlush[$identifier] = [
            'path' => $this->getMetadataPathForIdentifier($identifier),
            'data' => $this->serializeManagedFile($file),
            'file' => $file
        ];
        return $file;
    }

    /**
     * {@inheritdoc}
     */
    public function remove($file)
    {
        try {
            $file = $this->ensureManagedFile($file);
        } catch (FileNotFoundException $e) {
            // Noting to do as we wanted to remove it anyway.
            return ;
        }
        $identifier = $file->getIdentifier();
        if (array_key_exists($identifier, $this->knownManagedFiles['id'])) {
            $file->decrementUsageCount();
            if ($file->getUsageCount() <= 0) {
                $this->waitingForRemove[] = $file;

                // Ensure the file have not been persisted before, we want to remove it now.
                for ($i = 0, $c = count($this->waitingForFlush); $i < $c; ++$i) {
                    /** @var ManagedFile $waitingForFlush */
                    $waitingForFlush = $this->waitingForFlush[$i];
                    if ($waitingForFlush->getIdentifier() === $identifier) {
                        array_splice($this->waitingForFlush, $i--, 1);
                        --$c;
                    }
                }
            } else {
                $this->waitingForFlush[$identifier] = [
                    'path' => $this->getMetadataPathForIdentifier($identifier),
                    'file' => $file
                ];
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function removeVersion($file, $version)
    {
        $file = $this->ensureManagedFile($file);
        $version = is_array($version) ? $version : [$version];
        for ($i = 0, $ii = count($version); $i < $ii; ++$i) {
            if ($file->hasVersion($version[$i])) {
                $file->removeVersion($version[$i]);
                $this->persist($file);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function removeExpirationDate($file)
    {
        $file = $this->ensureManagedFile($file);
        $file->getConfiguration()->setExpirationDate(null);
        $this->persist($file);
    }

    /**
     * {@inheritdoc}
     */
    public function flush()
    {
        $this->loadTimeLimitedFiles();
        $this->filesystem->ensurePathExists($this->metadataRootDir . '/' . self::METADATA_DIR);
        $this->filesystem->ensurePathExists($this->metadataRootDir . '/' . self::HASHES_DIR);

        $waitingForFlush = array_values($this->waitingForFlush);
        $waitingForRemove = array_values($this->waitingForRemove);
        $this->waitingForFlush = [];
        $this->waitingForRemove = [];
        $expiringFilesChanged = false;

        if (count($waitingForFlush) > 0) {
            $writesFailed = [];
            foreach ($waitingForFlush as $data) {
                /** @var ManagedFile $file */
                $file = $data['file'];
                $identifier = $file->getIdentifier();
                $oldHash = $file->getHash();
                $this->removeOldHashMetadata($file);

                // Write versions
                $versions = $file->getVersions();
                foreach ($versions as $name => $version) {
                    $version = $this->filesystem->persist($version);
                    $file->setVersion($name, $version);
                }

                // Remove versions
                $removedVersions = $file->getRemovedVersions();
                foreach ($removedVersions as $name => $version) {
                    $this->filesystem->removeWithParentIfEmpty($version, 2);
                }

                // Write metadata
                $this->filesystem->ensurePathExists(dirname($data['path']));
                if (@file_put_contents($data['path'], $this->serializeManagedFile($file)) === false) {
                    $writesFailed[] = $data['path'];
                }

                // Write hash ref
                $hashFilePath = $this->getMetadataPathForHash($file->getHash());
                $this->filesystem->ensurePathExists(dirname($hashFilePath));
                if (@file_put_contents($hashFilePath, $identifier) === false) {
                    $writesFailed[] = $hashFilePath;
                }

                // Update expiring files
                $expirationDate = $file->getExpirationDate();
                $inExpiringFiles = array_key_exists($identifier, $this->expiringFiles);
                if ($expirationDate) {
                    $expirationDateStr = $expirationDate->format('Y-m-d H:i:s');
                    $expiringFilesChanged = $expiringFilesChanged || !$inExpiringFiles || $this->expiringFiles[$identifier] !== $expirationDateStr;
                    $this->expiringFiles[$identifier] = $expirationDateStr;
                } else if ($inExpiringFiles) {
                    unset($this->expiringFiles[$identifier]);
                    $expiringFilesChanged = true;
                }
                if (array_key_exists($oldHash, $this->knownManagedFiles['hash'])) {
                    unset($this->knownManagedFiles['hash'][$oldHash]);
                }
                $this->knownManagedFiles['id'][$identifier] = $file;
                $this->knownManagedFiles['hash'][$file->getHash()] = $file;
            }
            if (count($writesFailed) > 0) {
                $this->errorTracker->track(new IOException('Failed to write metadata files.'), ['paths' => $writesFailed]);
            }
        }
        if (count($waitingForRemove) > 0) {
            foreach ($waitingForRemove as $file) {
                /** @var ManagedFile $file */
                $hash = $file->getHash();
                $identifier = $file->getIdentifier();
                $versions = $file->getVersions();
                foreach ($versions as $name => $version) {
                    $this->filesystem->removeWithParentIfEmpty($version, 2);
                }
                $metadataPath = $this->getMetadataPathForIdentifier($file->getIdentifier());
                $hashFilePath = $this->getMetadataPathForHash($hash);
                $this->filesystem->removeWithParentIfEmpty($metadataPath, 3);
                $this->filesystem->removeWithParentIfEmpty($hashFilePath, 3);
                if (array_key_exists($identifier, $this->expiringFiles)) {
                    unset($this->expiringFiles[$identifier]);
                    $expiringFilesChanged = true;
                }
            }
        }
        if ($expiringFilesChanged) {
            $this->saveTimeLimitedFiles();
        } else if ($this->expiringFilesLock) {
            $this->expiringFilesLock->release();
            $this->expiringFilesLock = null;
            $this->expiringFiles = null;
        }
    }

    /**
     * Check if the file in parameter is already known with a different hash and remove the hash file if so.
     *
     * @param ManagedFile $file
     */
    private function removeOldHashMetadata(ManagedFile $file)
    {
        $identifier = $file->getIdentifier();
        if (!array_key_exists($identifier, $this->knownManagedFiles['id'])) {
            return ;
        }
        foreach ($this->knownManagedFiles['hash'] as $hash => $item) {
            /** @var ManagedFile $item */
            if ($item->getIdentifier() === $identifier) {
                $this->filesystem->removeWithParentIfEmpty($this->getMetadataPathForHash($hash), 3);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function clearExpiredFiles(OutputInterface $output = null)
    {
        if ($output !== null) { $output->writeln('Searching expired files..'); }
        $this->loadTimeLimitedFiles();
        $now = new \DateTime();
        $toRemove = [];
        foreach ($this->expiringFiles as $identifier => $dateStr) {
            try {
                $expirationDate = \DateTime::createFromFormat('Y-m-d H:i:s', $dateStr);
                if ($now >= $expirationDate) {
                    $toRemove[] = $identifier;
                }
            } catch (\Exception $e) {
                $this->errorTracker->track($e, ['identifier' => $identifier, 'dateStr' => $dateStr]);
                if ($output !== null) {
                    $output->writeln('<error>'.$e->getMessage().'</error>');
                }
            }
        }
        if ($output !== null) { $output->writeln('<info>'.count($toRemove).'</info> file(s) found.'); }
        foreach ($toRemove as $identifier) {
            try {
                // A load is done and not simply a remove($identifier) only to have a better console output.

                /** @var ManagedFile $file */
                $file = $this->load($identifier);
                $versions = $file->getVersions();
                foreach ($versions as $name => $path) {
                    if ($output !== null) { $output->write('Removing <info>'.$path.'</info>..'); }
                    if ($this->filesystem->removeWithParentIfEmpty($path, 2) && $output !== null) {
                        $output->writeln('<info>success</info>.');
                    } else if ($output !== null) {
                        $output->writeln('<error>failed</error>.');
                    }
                }
                $this->removeOldHashMetadata($file);
                $this->remove($file);
                unset($this->expiringFiles[$identifier]);
            } catch (FileNotFoundException $e) {
                // That's good, we wanted to remove it anyway.
                // Remove the entry in expiringFiles to avoid having this error indefinitely.
                unset($this->expiringFiles[$identifier]);
            } catch (\Exception $e) {
                $this->errorTracker->track($e, ['identifier' => $identifier]);
                if ($output !== null) {
                    $output->writeln('<error>'.$e->getMessage().'</error>');
                }
            }
        }
        $this->saveTimeLimitedFiles();
        $this->flush();
    }

    /**
     * Utility method to ensure a ManagedFile instance is returned.
     *
     * @param ManagedFile|PublicFile|string $file
     *
     * @return ManagedFile
     *
     * @throws
     */
    private function ensureManagedFile($file)
    {
        if ($file instanceof ManagedFile) {
            return $file;
        }
        if ($file instanceof PublicFile) {
            $file = $file->identifier;
        }
        if (!is_string($file)) {
            $this->errorTracker->trackAndThrow(new \InvalidArgumentException('Argument should be a string or a ManagedFile instance.'), ['input' => $file]);
        }
        return $this->load($file);
    }

    /**
     * Convert a ManagedFile instance to a string.
     *
     * @param ManagedFile $file
     *
     * @return string
     */
    private function serializeManagedFile(ManagedFile $file): string
    {
        $normalizedVersions = [];
        $versions = $file->getVersions();
        foreach ($versions as $name => $version) {
            /** @var File $version */
            $normalizedVersions[$name] = $version->getRealPath();
        }
        $expirationDate = $file->getExpirationDate();
        return json_encode([
            $file->getIdentifier(), // 0
            $file->getFilename(), // 1
            $file->getConfiguration()->exportGenericRepresentation(), // 2
            $file->getExtra(), // 3
            $file->getPublicExtra(), // 4
            $file->getUsageCount(), // 5
            $normalizedVersions, // 6
            $expirationDate instanceof \DateTime ? $expirationDate->format('Y-m-d H:i:s') : null // 7
        ]);
    }

    /**
     * Try to create a managed file from a metadata file.
     *
     * @param string $path
     *
     * @return ManagedFile
     *
     * @throws
     */
    private function createManagedFileFromMetadataPath(string $path): ManagedFile
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            $this->errorTracker->trackAndThrow(new IOException(sprintf('Failed to read file at "%s".', $path)));
        }
        return $this->unserializeManagedFile($content);
    }

    /**
     * Try to create a ManagedFile instance from a serialized export.
     *
     * @param string $data
     *
     * @return ManagedFile
     *
     * @throws
     */
    private function unserializeManagedFile(string $data): ManagedFile
    {
        $decoded = @json_decode($data, true);
        if (!is_array($decoded)) {
            $this->errorTracker->trackAndThrow(new RuntimeException('Invalid metadata.'));
        }
        $file = $this->container->get(ManagedFile::class);
        $file->setIdentifier($decoded[0]);
        $file->setConfiguration(Configuration::createFromGenericRepresenation($this->container, $decoded[2]));
        $file->setExtra($decoded[3]);
        $file->setPublicExtra($decoded[4]);
        $file->setUsageCount($decoded[5]);
        if ($decoded[7]) {
            $file->getConfiguration()->setExpirationDate(\DateTime::createFromFormat('Y-m-d H:i:s', $decoded[7]));
        }
        $versions = $decoded[6];
        foreach ($versions as $name => $path) {
            $version = new File($path);
            $version->setIdentifier($decoded[0]);
            $version->setVersionName($name);
            $version->setVirtualName($decoded[1]);
            $version->isPublic($file->getConfiguration()->isPublic());
            $file->addVersion($version, $name);
        }
        return $file;
    }

    /**
     * Load the list of files that have a limited time to live.
     *
     * @return array
     *
     * @throws
     */
    private function loadTimeLimitedFiles(): array
    {
        if ($this->expiringFiles !== null) {
            return $this->expiringFiles;
        }
        if (!$this->sharedStorage) {
            $this->expiringFiles = [];
            return $this->expiringFiles;
        }
        try {
            $this->expiringFiles = ArrayUtils::ensureArray($this->sharedStorage->getAndLockUntilNextSet(
                self::EXPIRATION_DATES_STORAGE_KEY,
                'wb',
                5000,
                $this->expiringFilesLock
            ));
        } catch (\Exception | \Throwable $e) {
            $this->expiringFiles = [];
        }
        return $this->expiringFiles;
    }

    /**
     * Save the list of files that have a limited time to live.
     *
     * @throws
     */
    public function saveTimeLimitedFiles(): void
    {
        if (!count($this->expiringFiles) > 0) {
            return ;
        }
        if (!$this->sharedStorage) {
            $this->errorTracker->trackAndThrow(new InvalidConfigurationException(
                'You must install "webeak/shared-storage-bundle" in order to set an expiration date to a file.'
            ));
        }
        try {
            $this->sharedStorage->set(self::EXPIRATION_DATES_STORAGE_KEY, $this->expiringFiles, 'wb:file-bundle');
            if ($this->expiringFilesLock) {
                $this->expiringFilesLock->release();
                $this->expiringFilesLock = null;
            }
            $this->expiringFiles = null;
        } catch (\Exception | \Throwable $e) {
            $this->errorTracker->track($e);
        }
    }

    /**
     * Gets the absolute path to the metadata file corresponding to an identifier.
     *
     * @param string $identifier
     *
     * @return string
     */
    private function getMetadataPathForIdentifier(string $identifier): string
    {
        return  $this->metadataRootDir . '/' . self::METADATA_DIR . '/' . $identifier[0] . '/' . $identifier[1] . '/' . $identifier[2] . '/' . $identifier;
    }

    /**
     * Gets the absolute path to the metadata file corresponding to a file hash.
     *
     * @param string $hash
     *
     * @return string
     */
    private function getMetadataPathForHash(string $hash): string
    {
        return $this->metadataRootDir
            . '/' . self::HASHES_DIR
            . '/' . substr($hash, 0, 3)
            . '/' . substr($hash, 3, 3)
            . '/' . substr($hash, 6, 3)
            . '/' . $hash;
    }
}
