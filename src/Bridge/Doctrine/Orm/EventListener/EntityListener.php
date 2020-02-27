<?php
namespace Webeak\Bundle\FileBundle\Bridge\Doctrine\Orm\EventListener;

use Webeak\Bundle\ErrorTrackerBundle\ErrorTrackerInterface;
use Webeak\Bundle\FileBundle\FilesTrait;
use Webeak\Component\Utils\ArrayUtils;
use Webeak\Component\Utils\ObjectUtils;
use Webeak\Bundle\FileBundle\FileManager;
use Webeak\Bundle\FileBundle\PublicFile;
use Webeak\Bundle\FileBundle\PublicFileCollection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\UnitOfWork;

class EntityListener
{
    use FilesTrait;

    /** @var FileManager */
    private $fileManager;

    /** @var ErrorTrackerInterface */
    private $errorTracker;

    /** @var array */
    private $oldValues;

    public function __construct(FileManager $fileManager, ErrorTrackerInterface $errorTracker)
    {
        $this->fileManager = $fileManager;
        $this->errorTracker = $errorTracker;
        $this->oldValues = [];
    }

    /**
     * Doctrine 'postLoad' lifecycle hook callback.
     *
     * @param LifecycleEventArgs $args
     */
    final public function postLoad(LifecycleEventArgs $args)
    {
        $em = $args->getEntityManager();
        $entity = $args->getEntity();
        $metadata = $em->getClassMetadata(get_class($entity), $entity);
        $hash = null;
        foreach ($metadata->fieldMappings as $name => $data) {
            if ($data['type'] === 'file' || $data['type'] === 'files') {
                if ($hash === null) {
                    $hash = spl_object_hash($entity);
                }
                if (!array_key_exists($hash, $this->oldValues)) {
                    $this->oldValues[$hash] = [];
                }
                if (!($entity instanceof \Doctrine\Common\Persistence\Proxy)) {
                    $this->oldValues[$hash][$data['fieldName']] = ObjectUtils::readProperty($entity, $data['fieldName']);
                }
            }
        }
    }

    /**
     * Doctrine 'onFlush' hook callback.
     *
     * @param OnFlushEventArgs $args
     *
     * @throws
     */
    final public function onFlush(OnFlushEventArgs $args)
    {
        $em = $args->getEntityManager();
        $uow = $em->getUnitOfWork();
        $insertions = $uow->getScheduledEntityInsertions();
        $updates = $uow->getScheduledEntityUpdates();
        $deletions = $uow->getScheduledEntityDeletions();

        foreach ($insertions as $entity) {
            $this->prepareEntityForWriting($em, $uow, $entity);
            $this->validateFiles($em, $entity);
        }
        foreach ($updates as $entity) {
            $this->prepareEntityForWriting($em, $uow, $entity);
            $this->validateFiles($em, $entity);
        }
        foreach ($deletions as $entity) {
            $this->prepareEntityForDeletion($em, $entity);
        }
    }

    /**
     * Handle changes on files before the entity is flushed into the database.
     * This method will call the FileManager to validate new files and will remove deleted files.
     *
     * @param EntityManager $em
     * @param UnitOfWork $uow
     * @param $entity
     */
    private function prepareEntityForWriting(EntityManager $em, UnitOfWork $uow, $entity)
    {
        $changed = false;
        $metadata = null;
        $hash = spl_object_hash($entity);

        if (!array_key_exists($hash, $this->oldValues)) {
            return ;
        }
        foreach ($this->oldValues[$hash] as $propertyName => $oldValue) {
            $newValue = ObjectUtils::readProperty($entity, $propertyName);
            $hasChanged = gettype($oldValue) !== gettype($newValue);

            if ($oldValue instanceof PublicFileCollection) {
                $oldValue = iterator_to_array($oldValue);
            }
            if ($newValue instanceof PublicFileCollection) {
                $newValue = iterator_to_array($newValue);
            }
            if (!$hasChanged) {
                if ($oldValue instanceof PublicFile && $newValue instanceof PublicFile) {
                    $hasChanged = count(ArrayUtils::compare(
                            $oldValue->exportGenericRepresentation(),
                            $newValue->exportGenericRepresentation()
                        )) > 0;
                } else if (is_array($newValue)) {
                    $hasChanged = count(ArrayUtils::compare($oldValue, $newValue)) > 0;
                }
            }
            if ($hasChanged) {
                if ($metadata === null) {
                    $metadata = $em->getClassMetadata(get_class($entity), $entity);
                }
                $newValue = $this->handleFileChanges($oldValue, $newValue, $metadata->fieldMappings[$propertyName]['type'] ===  'files');
                ObjectUtils::writeProperty($entity, $propertyName, $newValue);
                $changed = true;
            }
        }
        if ($changed && $metadata !== null) {
            $uow->recomputeSingleEntityChangeSet($metadata, $entity);
        }
    }

    /**
     * Removes files associated with an entity before it is removed.
     *
     * @param EntityManager $em
     * @param $entity
     */
    private function prepareEntityForDeletion(EntityManager $em, $entity)
    {
        $metadata = null;
        $hash = spl_object_hash($entity);

        if (!array_key_exists($hash, $this->oldValues)) {
            return ;
        }
        foreach ($this->oldValues[$hash] as $propertyName => $oldValue) {
            if ($oldValue !== null) {
                if ($metadata === null) {
                    $metadata = $em->getClassMetadata(get_class($entity), $entity);
                }
                $this->handleFileChanges($oldValue, null, $metadata->fieldMappings[$propertyName]['type'] === 'files');
            }
        }
    }

    /**
     * Removes expiration date of files associated with an entity.
     *
     * @param EntityManager $em
     * @param object        $entity
     */
    private function validateFiles(EntityManager $em, $entity)
    {
        $metadata = $em->getClassMetadata(get_class($entity), $entity);
        foreach ($metadata->fieldMappings as $name => $data) {
            if ($data['type'] === 'file' || $data['type'] === 'files') {
                try {
                    $value = ObjectUtils::readProperty($entity, $data['fieldName']);
                    if ($value instanceof PublicFile) {
                        $this->fileManager->confirmRegistration($value);
                    } else if ($value instanceof PublicFileCollection) {
                        foreach ($value as $file) {
                            $this->fileManager->confirmRegistration($file);
                        }
                    }
                } catch (\Exception $e) {
                    // An exception here MUST NOT interrupt the process.
                    // Simply log it and continue.
                    $this->errorTracker->track($e);
                }
            }
        }
    }
}
