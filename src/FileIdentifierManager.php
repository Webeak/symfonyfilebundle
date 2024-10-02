<?php
namespace Webeak\Bundle\FileBundle;

use Webeak\Bundle\EssentialBundle\Exception\UsageException;
use Webeak\Bundle\EssentialBundle\SharedStorage\LockInterface;
use Webeak\Bundle\EssentialBundle\SharedStorage\SharedStorageInterface;
use Webeak\Bundle\FileBundle\Exception\NoStorageFoundForIdentifierException;
use Webeak\Bundle\FileBundle\Storage\DoctrineStorage;
use Webeak\Bundle\FileBundle\Storage\StorageCollection;
use Webeak\Component\Utils\RandomGenerator;

/**
 * Create / fetch identifiers for files.
 */
class FileIdentifierManager
{
    private const STORAGE_KEY = 'wb:file:identifier:%s';
    private const TABLE_NAME = 'wb_file_identifier';
    private const IDENTIFIER_BASE_LENGTH = 10;
    private const MAX_TRIES = 10;

    public function __construct(private readonly SharedStorageInterface $sharedStorage,
                                private readonly StorageCollection $storageCollection)
    {

    }

    /**
     * Create a new unique identifier for a file.
     *
     * @throws
     */
    public function create(string $storageType): string
    {
        if (!$this->storageCollection->offsetExists($storageType)) {
            throw new UsageException(sprintf('Unknown storage type %s', $storageType));
        }
        $nbTries = 0;
        $currentLength = self::IDENTIFIER_BASE_LENGTH;
        $connection = $this->getConnection($storageType);

        /** @var LockInterface $lock */
        $this->sharedStorage->getAndLock('file-identifier-factory', $lock, 'wb:file');
        try {
            do {
                $generated = RandomGenerator::randomString($currentLength);
                $sql = 'SELECT id FROM `' . self::TABLE_NAME . '` WHERE ref = "' . $generated . '" LIMIT 1';
                $query = $connection->prepare($sql);
                $result = $query->execute();
                if ($result->fetchOne() !== false) {
                    if (++$nbTries >= self::MAX_TRIES) {
                        if ($currentLength - self::IDENTIFIER_BASE_LENGTH < 3) {
                            // Can't find a unique identifier with the current length, try with a longer one.
                            ++$currentLength;
                            $nbTries = 0;
                        } else {
                            throw new \RuntimeException('Failed to generate a new unique identifier.');
                        }
                    }
                    continue;
                }
                $sql = 'INSERT INTO `' . self::TABLE_NAME . '` SET ref = ?, storage_type = ?';
                $query = $connection->prepare($sql);
                $query->execute([$generated, $storageType]);
                $storageKey = $this->getStorageKey($generated);
                $this->sharedStorage->set($storageKey, $storageType);
                return $generated;
            } while (true);
        } finally {
            $lock->release();
        }
    }

    public function remove(string $identifier): void
    {
        foreach ($this->storageCollection as $storage) {
            if (!($storage instanceof DoctrineStorage)) {
                continue;
            }
            try {
                $sql = 'DELETE FROM `' . self::TABLE_NAME . '` WHERE ref = ?';
                $query = $storage->getConnection()->prepare($sql);
                $query->execute([$identifier]);
            } catch (\Throwable $e) {}
        }
        $this->sharedStorage->unset($this->getStorageKey($identifier));
    }

    /**
     * Create a new unique identifier for a file.
     *
     * @throws
     */
    public function getStorageTypeForIdentifier(string $identifier): string
    {
        $storageKey = $this->getStorageKey($identifier);
        $cachedValue = $this->sharedStorage->get($storageKey);
        if ($cachedValue !== null) {
            return $cachedValue;
        }
        foreach ($this->storageCollection as $storage) {
            if (!($storage instanceof DoctrineStorage)) {
                continue;
            }
            $sql = 'SELECT storage_type FROM `' . self::TABLE_NAME . '` WHERE ref = ?';
            $query = $storage->getConnection()->prepare($sql);
            $result = $query->execute([$identifier]);
            $storageType = $result->fetchOne();
            if ($storageType === false) {
                continue ;
            }
            $this->sharedStorage->set($storageKey, $storageType);
            return $storageType;
        }
        throw new NoStorageFoundForIdentifierException('No storage type found for identifier ' . $identifier);
    }

    private function getStorageKey(string $identifier): string
    {
        return sprintf(self::STORAGE_KEY, $identifier);
    }

    private function getConnection(string $storageType): object
    {
        $storage = $this->storageCollection->offsetGet($storageType);
        if (!($storage instanceof DoctrineStorage)) {
            throw new UsageException('Storage type ' . $storageType . ' is not supported by the identifier manager.');
        }
        return $storage->getConnection();
    }
}