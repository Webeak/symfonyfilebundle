<?php
namespace Webeak\Bundle\FileBundle\Storage;

use Doctrine\ORM\EntityManager;
use Webeak\Bundle\ErrorTrackerBundle\ErrorTrackerInterface;
use Webeak\Bundle\EssentialBundle\Exception\RuntimeException;
use Webeak\Bundle\FileBundle\Bridge\Doctrine\Orm\Entity\AbstractFile;
use Webeak\Bundle\FileBundle\Bridge\Doctrine\Orm\Entity\FileEntityInterface;
use Webeak\Bundle\FileBundle\Exception\FileNotFoundException;
use Webeak\Bundle\FileBundle\Configuration;
use Webeak\Bundle\FileBundle\File;
use Webeak\Bundle\FileBundle\FileSystem;
use Webeak\Bundle\FileBundle\ManagedFile;
use Webeak\Bundle\FileBundle\PublicFile;
use Doctrine\Common\Persistence\ManagerRegistry;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Store files in the database
 */
class DoctrineStorage implements StorageInterface
{
    /** @var ContainerInterface */
    protected $container;

    /** @var ManagerRegistry */
    protected $doctrine;

    /** @var ErrorTrackerInterface */
    protected $errorTracker;

    /** @var FileSystem */
    protected $filesystem;

    /** @var array */
    protected $configuration;

    /** @var FileEntityInterface[] */
    private $knownEntities;

    /** @var array */
    private $knownManagedFiles;

    /** @var ManagedFile[] */
    private $waitingForPersist;

    /** @var ManagedFile[] */
    private $waitingForRemove;

    /** @var ManagedFile[] */
    private $waitingForFlush;

    public function __construct(ContainerInterface $container,
                                ManagerRegistry $doctrine,
                                ErrorTrackerInterface $errorTracker,
                                FileSystem $filesystem,
                                array $configuration)
    {
        $this->container = $container;
        $this->doctrine = $doctrine;
        $this->errorTracker = $errorTracker;
        $this->filesystem = $filesystem;
        $this->configuration = $configuration;
        $this->knownEntities = [];
        $this->knownManagedFiles = [$this->configuration['entity_id_attr'] => [], 'hash' => []];
        $this->waitingForPersist = [];
        $this->waitingForRemove = [];
        $this->waitingForFlush = [];
    }

    /**
     * {@inheritdoc}
     */
    public function load(string $identifier)
    {
        return $this->loadBy($this->configuration['entity_id_attr'], $identifier, [
            $this->configuration['entity_id_attr'] => $identifier
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function loadByHash(string $hash)
    {
        return $this->loadBy('hash', $hash, [
            'hash' => $hash,
            'expirationDate' => null
        ], false);
    }

    /**
     * {@inheritdoc}
     */
    public function persist(ManagedFile $file)
    {
        $entity = null;
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

        // Update versions
        $removedVersions = $file->getRemovedVersions();
        foreach ($removedVersions as $name => $version) {
            $this->filesystem->removeWithParentIfEmpty($version, 2);
        }
        $entity->setVersions([]);
        $versions = $file->getVersions();
        foreach ($versions as $name => $version) {
            $version = $this->filesystem->persist($version);
            $entity->addVersion($name, $version->getRealPath());
            $file->setVersion($name, $version);
        }
        $this->waitingForFlush[$entity->getIdentifier()] = $entity;
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
        if (array_key_exists($file->getIdentifier(), $this->knownEntities)) {
            $entity = $this->knownEntities[$file->getIdentifier()];
            $file->decrementUsageCount();
            if ($file->getUsageCount() <= 0) {
                $versions = $entity->getVersions();
                foreach ($versions as $name => $version) {
                    $this->filesystem->removeWithParentIfEmpty($version, 2);
                }
                if (!in_array($file, $this->waitingForRemove, true)) {
                    $this->waitingForRemove[] = $entity;
                }
            } else {
                $entity->setUsageCount($file->getUsageCount());
            }
            $this->waitingForFlush[$entity->getIdentifier()] = $entity;
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
        $waitingForFlush = array_values($this->waitingForFlush);
        $waitingForRemove = array_values($this->waitingForRemove);
        $this->waitingForFlush = [];
        $this->waitingForRemove = [];
        if (count($waitingForRemove) > 0) {
            // TODO: Can be VERY slow, check why and fix it before uncommenting it.
            // $this->filesystem->clearEmptyDirectories();
        }
        if (count($waitingForFlush) > 0) {
            /** @var EntityManager $em */
            $em = $this->doctrine->getManagerForClass($this->configuration['entity_class']);
            if ($em->isOpen()) {
                foreach ($waitingForFlush as $entity) {
                    $em->persist($entity);
                    if (in_array($entity, $waitingForRemove, true)) {
                        $em->remove($entity);
                    }
                }
                $em->flush($waitingForFlush);
            } else {
                $this->errorTracker->track(
                    new RuntimeException('Cannot flush changes on files because the entity manager is closed.')
                );
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function clearExpiredFiles(OutputInterface $output = null)
    {
        if ($output !== null) { $output->writeln('Searching expired files..'); }
        /** @var EntityManager $em */
        $em = $this->doctrine->getManagerForClass($this->configuration['entity_class']);
        $repository = $em->getRepository($this->configuration['entity_class']);
        $builder = $repository->createQueryBuilder('e');
        $builder->where('e.expirationDate is not null');
        $builder->andWhere('e.expirationDate <= ?1');
        $builder->setParameter(1, new \DateTime);
        try {
            $files = $builder->getQuery()->getResult();
            if ($output !== null) { $output->writeln('<info>'.count($files).'</info> file(s) found.'); }
            for ($i = 0, $ii = count($files); $i < $ii; ++$i) {
                /** @var ManagedFile $file */
                $file = $files[$i];
                $versions = $file->getVersions();
                foreach ($versions as $name => $path) {
                    if ($output !== null) { $output->write('Removing <info>'.$path.'</info>..'); }
                    if ($this->filesystem->removeWithParentIfEmpty($path, 2) && $output !== null) {
                        $output->writeln('<info>success</info>.');
                    } else if ($output !== null) {
                        $output->writeln('<error>failed</error>.');
                    }
                }
                $em->remove($file);
            }
            $em->flush();
        } catch (\Exception $e) {
            if ($output !== null) {
                $output->writeln('<error>'.$e->getMessage().'</error>');
            }
        }
    }

    /**
     * Convert a FileEntityInterface into a ManagedFile object.
     *
     * @param FileEntityInterface $entity
     *
     * @return ManagedFile
     */
    private function createFileFromEntity(FileEntityInterface $entity)
    {
        $this->knownEntities[$entity->getIdentifier()] = $entity;
        $configuration = Configuration::createFromGenericRepresenation($this->container, $entity->getConfiguration());

        $file = $this->container->get(ManagedFile::class);
        $file->setIdentifier($entity->getIdentifier());
        $file->setConfiguration($configuration);
        $file->setUsageCount($entity->getUsageCount());

        $versions = $entity->getVersions();
        foreach ($versions as $name => $path) {
            $version = new File($path);
            $version->setIdentifier($entity->getIdentifier());
            $version->setVersionName($name);
            $version->setVirtualName($entity->getName());
            $version->isPublic($file->getConfiguration()->isPublic());
            $file->addVersion($version, $name);
        }
        $this->knownManagedFiles[$this->configuration['entity_id_attr']][$entity->getIdentifier()] = $file;
        return $file;
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
     * Try to load a file.
     *
     * @param string  $type     name of the attr to use when querying the database
     * @param string  $search   what to search for
     * @param array   $criteria additional filters to apply
     * @param boolean $throw    (optional, default: true) throw an exception on error. If false, null is returned or error.
     *
     * @return ManagedFile|null
     *
     * @throws
     */
    private function loadBy($type, $search, $criteria, $throw = true)
    {
        if (array_key_exists($search, $this->knownManagedFiles[$type])) {
            return $this->knownManagedFiles[$type][$search];
        }
        $em = $this->doctrine->getManagerForClass($this->configuration['entity_class']);
        $entity = $em->getRepository($this->configuration['entity_class'])->findOneBy($criteria);
        if ($entity instanceof FileEntityInterface) {
            $managedFile = $this->createFileFromEntity($entity);
            if (!$managedFile->hasExpired()) {
                return $managedFile;
            }
        }
        if ($throw) {
            $this->errorTracker->trackAndThrow(new FileNotFoundException(
                sprintf('No file id "%s" has been found. It may have been removed.', $search)
            ));
        }
        return null;
    }
}
