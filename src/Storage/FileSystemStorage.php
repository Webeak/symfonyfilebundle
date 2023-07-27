<?php
namespace Webeak\Bundle\FileBundle\Storage;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Webeak\Bundle\EssentialBundle\Exception\IOException;
use Webeak\Bundle\EssentialBundle\Exception\UsageException;
use Webeak\Bundle\EssentialBundle\SharedStorage\LockInterface;
use Webeak\Bundle\EssentialBundle\SharedStorage\SharedStorageInterface;
use Webeak\Bundle\FileBundle\Entity\FileEntityInterface;
use Webeak\Bundle\FileBundle\Exception\FileNotFoundException;
use Webeak\Bundle\FileBundle\Configuration;
use Webeak\Bundle\FileBundle\File;
use Webeak\Bundle\FileBundle\FileSystem\FileSystemInterface;
use Webeak\Bundle\FileBundle\ManagedFile;
use Webeak\Bundle\FileBundle\PublicFile;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
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
    private ContainerInterface $container;

    /** @var SharedStorageInterface */
    private SharedStorageInterface $sharedStorage;

    /** @var FileSystemInterface */
    private FileSystemInterface $filesystem;

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /** @var FileEntityInterface[] */
    private array $knownEntities;

    /** @var array */
    private array $knownManagedFiles;

    /** @var ManagedFile[] */
    private array $waitingForPersist;

    /** @var ManagedFile[] */
    private array $waitingForRemove;

    /** @var array */
    private array $waitingForFlush;

    /** @var array */
    private ?array $expiringFiles;

    /** @var LockInterface */
    private ?LockInterface $expiringFilesLock;

    /** @var string */
    private string $metadataRootDir;

    /** @var boolean */
    private bool $changed;

    public function __construct(ContainerInterface $container,
                                KernelInterface $kernel,
                                LoggerInterface $logger,
                                FileSystemInterface $filesystem)
    {
        $this->container = $container;
        $this->filesystem = $filesystem;
        $this->logger = $logger;
        $this->knownEntities = [];
        $this->knownManagedFiles = ['id' => [], 'hash' => []];
        $this->waitingForPersist = [];
        $this->waitingForRemove = [];
        $this->waitingForFlush = [];
        $this->expiringFiles = null;
        $this->expiringFilesLock = null;
        $this->changed = false;
        $this->metadataRootDir = $kernel->getProjectDir() . '/var/storage/wb-files/metadata';
    }

    /**
     * Shared storage is optional.
     * The container will set it through this method if available.
     */
    public function setSharedStorage(SharedStorageInterface $sharedStorage): void
    {
        $this->sharedStorage = $sharedStorage;
    }

    /**
     * {@inheritdoc}
     */
    public function load(string $identifier): mixed
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
        throw new FileNotFoundException(sprintf(
            'No file id "%s" has been found. It may have been removed.',
            $identifier
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
    public function persist(ManagedFile $file): ManagedFile
    {
        $identifier = $file->getIdentifier();
        if (strlen($identifier) < 4) {
            throw new UsageException('Invalid file identifier. Identifiers must be at least 4 characters long.');
        }
        $this->waitingForFlush[$identifier] = [
            'path' => $this->getMetadataPathForIdentifier($identifier),
            'data' => $this->serializeManagedFile($file),
            'file' => $file
        ];
        $this->changed = true;
        return $file;
    }

    /**
     * {@inheritdoc}
     */
    public function remove($file, bool $force = false): void
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
            if ($file->getUsageCount() <= 0 || $force) {
                $this->waitingForRemove[] = $file;

                // Ensure the file have not been persisted before, we want to remove it now.
                if (array_key_exists($identifier, $this->waitingForFlush)) {
                    unset($this->waitingForFlush[$identifier]);
                }
            } else {
                $this->waitingForFlush[$identifier] = [
                    'path' => $this->getMetadataPathForIdentifier($identifier),
                    'file' => $file
                ];
            }
            $this->changed = true;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function removeVersion($file, $version): void
    {
        $file = $this->ensureManagedFile($file);
        $version = is_array($version) ? $version : [$version];
        for ($i = 0, $ii = count($version); $i < $ii; ++$i) {
            if ($file->hasVersion($version[$i])) {
                $file->removeVersion($version[$i]);
                $this->persist($file);
                $this->changed = true;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function removeExpirationDate($file): void
    {
        $file = $this->ensureManagedFile($file);
        $file->getConfiguration()->setExpirationDate(null);
        $this->persist($file);
        $this->changed = true;
    }

    /**
     * {@inheritdoc}
     */
    public function flush(): void
    {
        if (!$this->changed) {
            return ;
        }
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
                throw new IOException('Failed to write metadata files.', 0, 500, null, ['paths' => $writesFailed]);
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
     */
    private function removeOldHashMetadata(ManagedFile $file): void
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
     *
     * @throws
     */
    public function clearExpiredFiles(OutputInterface $output = null): void
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
            } catch (\Throwable $e) {
                $this->logger->error($e->getMessage(), ['exception' => $e, 'identifier' => $identifier, 'dateStr' => $dateStr]);
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
            } catch (\Throwable $e) {
                $this->logger->error($e->getMessage(), ['exception' => $e, 'identifier' => $identifier]);
                if ($output !== null) {
                    $output->writeln('<error>'.$e->getMessage().'</error>');
                }
            }
        }
        $this->saveTimeLimitedFiles();
        $this->flush();
    }

    /**
     * List files matching certain criteria.
     */
    public function find(int $offset, array $filters = [], int $maxResults = 20): array
    {
        return [];
    }

    /**
     * Utility method to ensure a ManagedFile instance is returned.
     *
     * @throws
     */
    private function ensureManagedFile(PublicFile|ManagedFile|string $file): ManagedFile
    {
        if ($file instanceof ManagedFile) {
            return $file;
        }
        if ($file instanceof PublicFile) {
            $file = $file->identifier;
        }
        if (!is_string($file)) {
            throw new UsageException('Argument should be a string or a ManagedFile instance.', 0, null, ['input' => $file]);
        }
        return $this->load($file);
    }

    /**
     * Convert a ManagedFile instance to a string.
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
     * @throws
     */
    private function createManagedFileFromMetadataPath(string $path): ManagedFile
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new IOException(sprintf('Failed to read file at "%s".', $path));
        }
        return $this->unserializeManagedFile($content);
    }

    /**
     * Try to create a ManagedFile instance from a serialized export.
     *
     * @throws
     */
    private function unserializeManagedFile(string $data): ManagedFile
    {
        $decoded = @json_decode($data, true);
        if (!is_array($decoded)) {
            throw new UsageException('Invalid metadata.');
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
     */
    private function loadTimeLimitedFiles(): array
    {
        if ($this->expiringFiles !== null) {
            return $this->expiringFiles;
        }
        try {
            $this->expiringFiles = ArrayUtils::ensureArray($this->sharedStorage->getAndLockUntilNextSet(
                self::EXPIRATION_DATES_STORAGE_KEY,
                'wb:file-bundle',
                5,
                10,
                $this->expiringFilesLock
            ));
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            $this->expiringFiles = [];
        }
        return $this->expiringFiles;
    }

    /**
     * Save the list of files that have a limited time to live.
     */
    public function saveTimeLimitedFiles(): void
    {
        try {
            $this->sharedStorage->set(self::EXPIRATION_DATES_STORAGE_KEY, $this->expiringFiles, 'wb:file-bundle');
            if ($this->expiringFilesLock) {
                $this->expiringFilesLock->release();
                $this->expiringFilesLock = null;
            }
            $this->expiringFiles = null;
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Gets the absolute path to the metadata file corresponding to an identifier.
     */
    private function getMetadataPathForIdentifier(string $identifier): string
    {
        return  $this->metadataRootDir . '/' . self::METADATA_DIR . '/' . $identifier[0] . '/' . $identifier[1] . '/' . $identifier[2] . '/' . $identifier;
    }

    /**
     * Gets the absolute path to the metadata file corresponding to a file hash.
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
