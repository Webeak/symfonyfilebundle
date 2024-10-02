<?php
namespace Webeak\Bundle\FileBundle\Storage;

use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Webeak\Bundle\EssentialBundle\Exception\IOException;
use Webeak\Bundle\EssentialBundle\Exception\UsageException;
use Webeak\Bundle\FileBundle\Bridge\Doctrine\Orm\Entity\AbstractFile;
use Webeak\Bundle\FileBundle\Bridge\Doctrine\Orm\Entity\File;
use Webeak\Bundle\FileBundle\Bridge\Doctrine\Orm\Entity\FileEntityInterface;
use Webeak\Bundle\FileBundle\Configuration;
use Webeak\Bundle\FileBundle\Exception\FileNotFoundException;
use Webeak\Bundle\FileBundle\ManagedFile;
use Webeak\Bundle\FileBundle\PublicFile;
use Webeak\Bundle\FileBundle\VirtualFile;
use Webeak\Component\Utils\VarNormalizer;

/**
 * Store files in the database
 */
class DoctrineStorage implements StorageInterface
{
    /** @var FileEntityInterface[] */
    private array $knownEntities;

    /** @var array */
    private array $knownManagedFiles;

    /** @var ManagedFile[] */
    private array $waitingForRemove;

    /** @var ManagedFile[] */
    private array $waitingForFlush;

    /** @var boolean */
    private bool $changed;

    public function __construct(private readonly ContainerInterface  $container,
                                private readonly ManagerRegistry     $doctrine,
                                private readonly array               $configuration)
    {
        $this->knownEntities = [];
        $this->knownManagedFiles = [$this->configuration['entity_id_attr'] => [], 'hash' => []];
        $this->waitingForRemove = [];
        $this->waitingForFlush = [];
        $this->changed = false;
    }

    public function getConnection(): object
    {
        return $this->doctrine->getConnection($this->configuration['entity_manager']);
    }

    /**
     * {@inheritdoc}
     */
    public function load(string $identifier): ?ManagedFile
    {
        return $this->loadBy($this->configuration['entity_id_attr'], $identifier, [
            $this->configuration['entity_id_attr'] => $identifier
        ]);
    }

    /**
     * {@inheritdoc}
     *
     * @throws
     */
    public function loadByHash(string $hash): ?ManagedFile
    {
        return $this->loadBy('hash', $hash, [
            'hash' => $hash,
            'expirationDate' => null
        ], false);
    }

    /**
     * {@inheritdoc}
     */
    public function persist(ManagedFile $file): ManagedFile
    {
        if (array_key_exists($file->getIdentifier(), $this->knownEntities)) {
            $entity = $this->knownEntities[$file->getIdentifier()];
        } else {
            $fqcn = $this->configuration['entity_class'];
            /** @var AbstractFile $entity */
            $entity = new $fqcn();
            $entity->setHash($file->getHash());
            $entity->setSourceFileHash($file->getSourceFilesHash());
        }
        $entity->setIdentifier($file->getIdentifier());
        $entity->setName($file->getFilename());
        $entity->setMimeType($file->getMimeType());
        $entity->setConfiguration($file->getConfiguration()->exportGenericRepresentation());
        $entity->setExpirationDate($file->getConfiguration()->getExpirationDate()); // Stored in a separate field to allow SQL filtering.
        $entity->setExtra($file->getExtra());
        $entity->setPublicExtra($file->getPublicExtra());
        $entity->setUsageCount($file->getUsageCount());
        $entity->setFileSystemType($file->getFileSystemType());

        // Update versions
        $removedFiles = $file->getRemovedVersions();
        foreach ($removedFiles as $removedFile) {
            $removedFile->dispose();
        }
        $entity->setVersions([]);
        $versions = $file->getVersions();
        foreach ($versions as $name => $version) {
            $entity->addVersion($name, $version->getIdentifier());
        }
        $this->waitingForFlush[$entity->getIdentifier()] = $entity;
        $this->knownManagedFiles[$this->configuration['entity_id_attr']][$entity->getIdentifier()] = $file;
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
            return;
        }
        if (array_key_exists($file->getIdentifier(), $this->knownEntities)) {
            $entity = $this->knownEntities[$file->getIdentifier()];
            $file->decrementUsageCount();
            $entity->setUsageCount($file->getUsageCount());

            // If other entities are using this file, we can't remove it, except if we force it.
            if ($file->getUsageCount() > 0 && !$force) {
                $this->waitingForFlush[$entity->getIdentifier()] = $entity;
                $this->changed = true;
                return ;
            }

            // Otherwise, remove the metadata entity.
            if (!in_array($file, $this->waitingForRemove, true)) {
                $this->waitingForRemove[] = $entity;
                $this->changed = true;
            }
        }
        if (in_array($file, $this->waitingForFlush, true)) {
            $this->waitingForFlush = array_filter($this->waitingForFlush, fn($f) => $f !== $file);
        }
        // In all cases, if we're here, remove the physical file.
        $file->dispose();
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
    }

    /**
     * {@inheritdoc}
     */
    public function flush(): void
    {
        if (!$this->changed) {
            return;
        }
        $waitingForFlush = array_values($this->waitingForFlush);
        $waitingForRemove = array_values($this->waitingForRemove);
        $this->waitingForFlush = [];
        $this->waitingForRemove = [];
        $em = $this->doctrine->getManager($this->configuration['entity_manager']);
        if (count($waitingForFlush) > 0 || count($waitingForRemove) > 0) {
            if ($em->isOpen()) {
                foreach ($waitingForFlush as $entity) {
                    if (!in_array($entity, $waitingForRemove, true)) {
                        $em->persist($entity);
                    }
                }
                foreach ($waitingForRemove as $entity) {
                    $em->remove($entity);
                }
                $em->flush();
            } else {
                throw new IOException('Cannot flush changes on files because the entity manager is closed.');
            }
        }
        $this->changed = false;
    }

    /**
     * {@inheritdoc}
     */
    public function clearExpiredFiles(OutputInterface $output = null): bool
    {
        $output?->writeln(sprintf('Searching expired files for manager "%s"..', $this->configuration['entity_manager']));

        /** @var EntityManager $em */
        $em = $this->doctrine->getManager($this->configuration['entity_manager']);
        $repository = $em->getRepository($this->configuration['entity_class']);
        $builder = $repository->createQueryBuilder('e');
        $builder->where('e.expirationDate is not null');
        $builder->andWhere('e.expirationDate <= :date');
        $builder->setParameter('date', gmdate('Y-m-d H:i:s'));
        try {
            $files = $builder->getQuery()->getResult();
            $output?->writeln('<info>' . count($files) . '</info> file(s) found.');
            for ($i = 0, $ii = count($files); $i < $ii; ++$i) {
                $fileEntity = $files[$i];
                $managedFile = $this->createFileFromEntity($fileEntity);
                $output?->write('Removing <info>' . $managedFile->getFilename() . ' (' . $managedFile->getIdentifier() . ')' . '</info>..');
                try {
                    $managedFile->dispose();
                    $output?->writeln('<info>success</info>.');
                } catch (\Throwable $e) {
                    $output?->writeln('<error>failed: ' . $e->getMessage() .'</error>.');
                }
                $em->remove($fileEntity);
            }
            $em->flush();
        } catch (\Exception $e) {
            $output?->writeln('<error>' . $e->getMessage() . '</error>');
            return false;
        }
        return true;
    }

    /**
     * List files matching certain criteria.
     */
    public function find(?int $offset, array $filters = [], int $maxResults = 20): array
    {
        $qb = $this->doctrine->getRepository(File::class)->createQueryBuilder('f');

        foreach ($filters as $name => $value) {
            if (!$value || $value === 'null') {
                continue;
            }
            if ($name === 'search') {
                $qb->andWhere($qb->expr()->orX(
                    $qb->expr()->like('f.name', ':search'),
                    $qb->expr()->like('f.mimeType', ':search'),
                    $qb->expr()->like('f.ref', ':search'),
                    $qb->expr()->like('f.extra', ':search'),
                ));
                $qb->setParameter('search', '%' . $value . '%');
            } else if ($name === 'usage') {
                $qb->andWhere($qb->expr()->gte('f.usageCount', ':usage'));
                $qb->setParameter('usage', '%' . $value . '%');
            }
        }
        if ($offset !== null && $offset > 0) {
            $qb->andWhere($qb->expr()->lt('f.id', ':offset'));
            $qb->setParameter('offset', $offset);
        }
        $qb->orderBy('f.id', 'desc');
        $qb->setMaxResults($maxResults);
        $output = [];
        $results = $qb->getQuery()->getResult();
        foreach ($results as $result) {
            $managedFile = $this->createFileFromEntity($result);
            $publicFile = $managedFile->getPublicFile();
            $normalized = VarNormalizer::Normalize($result);
            $normalized['versions'] = $publicFile->versions;
            $output[] = $normalized;
        }
        return $output;
    }

    /**
     * Convert a FileEntityInterface into a ManagedFile object.
     */
    private function createFileFromEntity(FileEntityInterface $entity): ManagedFile
    {
        $this->knownEntities[$entity->getIdentifier()] = $entity;
        $configuration = Configuration::createFromGenericRepresenation($this->container, $entity->getConfiguration());
        $file = $this->container->get(ManagedFile::class);
        $file->setIdentifier($entity->getIdentifier());
        $file->setConfiguration($configuration);
        $file->setUsageCount($entity->getUsageCount());
        $file->setFileSystemType($entity->getFileSystemType());
        $versions = $entity->getVersions();
        foreach ($versions as $name => $identifier) {
            $version = new VirtualFile();
            $version->setIdentifier($identifier);
            $version->setVersionName($name);
            $version->setVirtualName($entity->getName());
            $version->isPublic($file->getConfiguration()->isPublic());
            $version->setFileSystem($file->getFileSystem());
            $file->addVersion($version, $name);
        }
        $this->knownManagedFiles[$this->configuration['entity_id_attr']][$entity->getIdentifier()] = $file;
        return $file;
    }

    /**
     * Utility method to ensure a ManagedFile instance is returned.
     *
     * @throws
     */
    private function ensureManagedFile(mixed $file): ?ManagedFile
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
     * Try to load a file.
     *
     * @param string $type name of the attr to use when querying the database
     * @param string $search what to search for
     * @param array $criteria additional filters to apply
     * @param boolean $throw (optional, default: true) throw an exception on error. If false, null is returned or error.
     *
     * @return ManagedFile|null
     *
     * @throws
     */
    private function loadBy(string $type, string $search, array $criteria, bool $throw = true): ?ManagedFile
    {
        if (array_key_exists($search, $this->knownManagedFiles[$type])) {
            return $this->knownManagedFiles[$type][$search];
        };
        $entity = $this->doctrine->getManager($this->configuration['entity_manager'])->getRepository($this->configuration['entity_class'])->findOneBy($criteria);
        if ($entity instanceof FileEntityInterface) {
            $managedFile = $this->createFileFromEntity($entity);
            if (!$managedFile->hasExpired()) {
                return $managedFile;
            }
        }
        if ($throw) {
            throw new FileNotFoundException(sprintf(
                'No file id "%s" has been found. It may have been removed.',
                $search
            ));
        }
        return null;
    }
}
