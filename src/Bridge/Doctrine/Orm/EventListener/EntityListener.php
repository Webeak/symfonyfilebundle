<?php
namespace Webeak\Bundle\FileBundle\Bridge\Doctrine\Orm\EventListener;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\Proxy;
use Webeak\Bundle\FileBundle\FileManager;
use Webeak\Bundle\FileBundle\FilesTrait;
use Webeak\Bundle\FileBundle\PublicFile;
use Webeak\Bundle\FileBundle\PublicFileCollection;
use Webeak\Component\Utils\ArrayUtils;
use Webeak\Component\Utils\ObjectUtils;

class EntityListener
{
    use FilesTrait;

    private array $oldValues;

    public function __construct(private readonly FileManager $fileManager)
    {
        $this->oldValues = [];
    }

    /**
     * Doctrine 'postLoad' lifecycle hook callback.
     */
    final public function postLoad(PostLoadEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $entity = $args->getObject();
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
                if (!($entity instanceof Proxy)) {
                    $this->oldValues[$hash][$data['fieldName']] = ObjectUtils::readProperty($entity, $data['fieldName']);
                }
            }
        }
    }

    /**
     * TODO: Do some optimization here. Currently we do a validateFiles() for each inserted/updated/deleted entity.
     * The method then do getClassMetadata() which is slow.
     * TODO: Store in the cache if there are files fields in each type of entity to avoid doing all this for nothing.
     * TODO: In a second time, try to only do the processing if an actual file has changed, not any property of the entity.
     *
     * Doctrine 'onFlush' hook callback.
     *
     * @param OnFlushEventArgs $args
     *
     * @throws
     */
    final public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
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
     */
    private function prepareEntityForWriting(EntityManager $em, UnitOfWork $uow, $entity): void
    {
        $changed = false;
        $metadata = null;
        $hash = spl_object_hash($entity);

        if (!array_key_exists($hash, $this->oldValues)) {
            return ;
        }
        foreach ($this->oldValues[$hash] as $propertyName => $oldValue) {
            $getter = 'get'.ucfirst($propertyName);
            $setter = 'set'.ucfirst($propertyName);
            $newValue = method_exists($entity, $getter) ? $entity->$getter() : ObjectUtils::readProperty($entity, $propertyName);
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
                if (method_exists($entity, $setter)) {
                    $entity->$setter($newValue);
                } else {
                    ObjectUtils::writeProperty($entity, $propertyName, $newValue);
                }
                $changed = true;
            }
        }
        if ($changed && $metadata !== null) {
            $uow->recomputeSingleEntityChangeSet($metadata, $entity);
        }
    }

    /**
     * Removes files associated with an entity before it is removed.
     */
    private function prepareEntityForDeletion(EntityManager $em, $entity): void
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
     */
    private function validateFiles(EntityManager $em, $entity): void
    {
        $metadata = $em->getClassMetadata(get_class($entity), $entity);
        foreach ($metadata->fieldMappings as $name => $data) {
            if ($data['type'] === 'file' || $data['type'] === 'files') {
                try {
                    $value = ObjectUtils::readProperty($entity, $data['fieldName']);
                    if ($value instanceof PublicFile) {
                        $newPublicFile = $this->fileManager->confirmRegistration($value);
                        ObjectUtils::writeProperty($entity, $data['fieldName'], $newPublicFile);
                    } else if ($value instanceof PublicFileCollection) {
                        $array = [];
                        foreach ($value as $file) {
                            // Very important to reassign the file because it may have changed
                            // if the same file is already stored.
                            $array[] = $this->fileManager->confirmRegistration($file);
                        }
                        ObjectUtils::writeProperty($entity, $data['fieldName'], PublicFileCollection::createFromArray($array));
                    }
                } catch (\Exception | \Throwable $e) {
                    // An exception here MUST NOT interrupt the process.
                    // Simply log it and continue.
                    if ($value instanceof PublicFile) {
                        ObjectUtils::writeProperty($entity, $data['fieldName'], null);
                    } else {
                        ObjectUtils::writeProperty($entity, $data['fieldName'], []);
                    }
                }
            }
        }
    }
}
