<?php
namespace Webeak\Bundle\FileBundle;

use Webeak\Bundle\ErrorTrackerBundle\ErrorTrackerInterface;
use Webeak\Bundle\EssentialBundle\Exception\InvalidArgumentException;
use Webeak\Bundle\EssentialBundle\Exception\RuntimeException;
use Webeak\Bundle\EssentialBundle\UniqueIdGenerator;
use Webeak\Bundle\FileBundle\Exception\FileNotFoundException;
use Webeak\Bundle\FileBundle\Adapter\AdapterInterface;
use Webeak\Bundle\FileBundle\Processor\ProcessorInterface;
use Webeak\Bundle\FileBundle\Storage\StorageInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Gives an easy interface between files and the application.
 * A file is only a unique string identifier for the app, all operations on paths, access rights etc
 * are handled internally by the file manager.
 *
 * You give an input to it (could be an UploadedFile object, a path, a ftp, etc) and it returns a
 * unique identifier you'll have to save and use anytime you want to access this file again.
 *
 * The real path of the file is never visible except if it is public.
 */
class FileManager
{
    /** @var ContainerInterface */
    protected $container;

    /** @var ValidatorInterface */
    protected $validator;

    /** @var ErrorTrackerInterface */
    protected $errorTracker;

    /** @var UniqueIdGenerator */
    protected $uniqueIdGenerator;

    /** @var AdapterInterface */
    protected $adapters;

    /** @var ProcessorInterface */
    protected $processors;

    /** @var StorageInterface */
    protected $storages;

    /** @var FileSystem */
    protected $filesystem;

    /** @var string */
    protected $storageType;

    /** @var integer */
    protected $tempFilesLifetime;

    public function __construct(ContainerInterface $container,
                                ValidatorInterface $validator,
                                ErrorTrackerInterface $errorTracker,
                                UniqueIdGenerator $uniqueIdGenerator,
                                FileSystem $filesystem,
                                $storageType,
                                $tempFilesLifetime)
    {
        $this->container = $container;
        $this->validator = $validator;
        $this->errorTracker = $errorTracker;
        $this->uniqueIdGenerator = $uniqueIdGenerator;
        $this->filesystem = $filesystem;
        $this->storageType = $storageType;
        $this->tempFilesLifetime = $tempFilesLifetime;
        $this->adapters = [];
        $this->processors = [];
        $this->storages = [];
    }

    /**
     * Register a file.
     * This will output an array of ManagedFile objects even if errors occurs. Errors are stored in the object.
     *
     * @param mixed                      $input          any input supported by at least one adapter
     * @param string|array|Configuration $configuration (optional)
     *
     * @return ManagedFile[]
     */
    public function register($input, $configuration = null)
    {
        $files = $this->prepareForRegistration($input, $configuration);
        for ($i = 0, $ii = count($files); $i < $ii; ++$i) {
            $file = $files[$i];
            if (!$file->hasError()) {
                $this->persist($file);
            }
        }
        return $files;
    }

    /**
     * Register a file using its content.
     * You may want to define its content type and name too.
     *
     * @param mixed                      $content        content of the file
     * @param string                     $name           name of the file
     * @param string|array|Configuration $configuration (optional)
     *
     * @return ManagedFile[]
     */
    public function registerByContent($content, $name, $configuration = null)
    {
        $path = $this->filesystem->writeTemporarily($content);
        $file = new File($path);
        $file->setVirtualName($name);
        return $this->register($file, $configuration);
    }

    /**
     * Register a file for a limited period of time.
     * The file must be confirmed before the expiration date is reached or the CRON task will remove it.
     *
     * The lifetime of the file is determined by the configuration property "temp_file_lifetime" (default value is 2 hours).
     * For more control, you can set the expiration date of the file in the configuration object yourself.
     *
     * @param mixed                      $input          any input supported by at least one adapter
     * @param string|array|Configuration $configuration (optional)
     *
     * @return ManagedFile[]
     *
     * @throws
     */
    public function registerTemporarily($input, $configuration = null)
    {
        $configuration = $this->resolveConfiguration($configuration);
        $date = new \DateTime();
        $date->setTimestamp($date->getTimestamp() + max(60, intval($this->tempFilesLifetime)));
        $configuration->setExpirationDate($date);
        return $this->register($input, $configuration);
    }

    /**
     * To confirm registration of a temporary file.
     *
     * @param ManagedFile|PublicFile|string $file
     *
     * @return PublicFile
     *
     * @throws
     */
    public function confirmRegistration($file)
    {
        $storage = $this->getStorage();
        $file = $this->ensureManagedFile($file);
        $file->getConfiguration()->setExpirationDate(null);

        if (($duplicate = $this->getFileDuplicate($file)) !== null && $duplicate->getConfiguration()->getExpirationDate() === null && $duplicate->isValid()) {
            $this->remove($file);
            $duplicate->incrementUsageCount();
            $storage->persist($duplicate);
            return $duplicate->getPublicFile();
        }
        $storage->removeExpirationDate($file);
        return $file->getPublicFile();
    }

    /**
     * Get a file using its identifier.
     * This method returns a ManagedFile which may contain multiple version.
     * You can use getVersion($identifier, $version) to get a specific version.
     *
     * @param string $identifier
     *
     * @return ManagedFile
     *
     * @throws
     */
    public function get($identifier)
    {
        $storage = $this->getStorage();
        return $storage->load($identifier);
    }

    /**
     * Remove a file.
     * The behavior slightly changes depending on the value of the $version attribute:
     *
     *  - if null: the whole managed file (and all its versions) is removed
     *  - if string: the specific version is removed
     *  - if array: all versions in the array are removed
     *
     * If after the remove, no more versions persist in the managed file, it will be removed as well.
     *
     * @param ManagedFile|PublicFile|string $file
     * @param string|array|null  $version
     *
     * @throws
     */
    public function remove($file, $version = null): void
    {
        $storage = $this->getStorage();
        if ($version !== null) {
            $storage->removeVersion($file, $version);
        } else {
            $storage->remove($file);
        }
    }

    /**
     * Schedule a file or an array of files for saving.
     * You MUST call flush() to effectively write the modifications in the storage.
     *
     * @param ManagedFile|ManagedFile[] $files
     *
     * @throws
     */
    public function persist($files): void
    {
        if (!is_array($files)) {
            $files = [$files];
        }
        $storage = $this->getStorage();
        for ($i = 0, $ii = count($files); $i < $ii; ++$i) {
            $file = $files[$i];
            if ($file instanceof ManagedFile) {
                $storage->persist($file);
            } else {
                $this->errorTracker->trackAndThrow(
                    new InvalidArgumentException('Invalid input for persist(). Only "ManagedFile" objects are accepted.')
                );
            }
        }
    }

    /**
     * Flush waiting operations like writing files' metadata in the database.
     * This will be done automatically on the 'kernel.terminate' event.
     * However, you can force it manually to ensure its done and have a feedback.
     *
     * @param ManagedFile[] $files (optional, default: null) array of files to flush, if not defined the whole pool will be flushed
     */
    public function flush($files = null): void
    {
        $storage = $this->getStorage();
        $storage->flush($files);
    }

    /**
     * Dependency injection callback for registering constraints bound using services' tags.
     *
     * To register here you need to add the tag "wb.file.file_manager_adapter" to the service concerned.
     *
     * @param mixed $service
     * @param array $attributes
     */
    public function registerAdapter($service, $attributes): void
    {
        if (!($service instanceof AdapterInterface)) {
            throw new \InvalidArgumentException(sprintf('The adapter "%s" must implement the "AdapterInterface".', get_class($service)));
        }
        $this->adapters[] = $service;
    }

    /**
     * Dependency injection callback for registering processors bound using services' tags.
     *
     * To register here you need to add the tag "wb.file.file_manager_processor" to the service concerned.
     *
     * @param mixed  $service
     * @param string $serviceId
     * @param array  $attributes
     */
    public function registerProcessor($service, $serviceId, $attributes): void
    {
        if (!($service instanceof ProcessorInterface)) {
            throw new \InvalidArgumentException(sprintf('The processor "%s" must implement the "ProcessorInterface".', get_class($service)));
        }
        $this->processors[$serviceId] = $service;
        if (is_array($attributes)) {
            for ($i = 0, $ii = count($attributes); $i < $ii; ++$i) {
                if (array_key_exists('alias', $attributes[$i])) {
                    if (!array_key_exists($attributes[$i]['alias'], $this->processors)) {
                        $this->processors[$attributes[$i]['alias']] = $service;
                    } else {
                        throw new InvalidConfigurationException(
                            sprintf('A processor named "%s" has already been defined.', $attributes[$i]['alias'])
                        );
                    }
                }
            }
        }
    }

    /**
     * Dependency injection callback for registering storages bound using services' tags.
     *
     * To register here you need to add the tag "wb_file.file_manager_storage" to the service concerned.
     *
     * @param mixed $service
     * @param array $attributes
     */
    public function registerStorage($service, $attributes): void
    {
        if (!($service instanceof StorageInterface)) {
            throw new \InvalidArgumentException(sprintf('The storage "%s" must implement the "StorageInterface".', get_class($service)));
        }
        if (is_array($attributes)) {
            for ($i = 0, $ii = count($attributes); $i < $ii; ++$i) {
                if (array_key_exists('alias', $attributes[$i])) {
                    if (!array_key_exists($attributes[$i]['alias'], $this->storages)) {
                        $this->storages[$attributes[$i]['alias']] = $service;
                        return ;
                    } else {
                        throw new InvalidConfigurationException(
                            sprintf('A storage named "%s" has already been defined.', $attributes[$i]['alias'])
                        );
                    }
                }
            }
        }
        throw new InvalidConfigurationException('A storage must define an "alias" attribute in its tag.');
    }

    /**
     * Symfony 'kernel.terminate' event.
     * That's where the flushing of entities only known by the tracker occurs.
     */
    public function onKernelTerminate(): void
    {
        $this->flush();
    }

    /**
     * Clear files for which the expiration date has been reached.
     *
     * @param OutputInterface $output
     */
    public function clearExpiredFiles(OutputInterface $output): void
    {
        $storage = $this->getStorage();
        $storage->clearExpiredFiles($output);
        $this->filesystem->clearEmptyDirectories();
    }

    /**
     * Try to find the duplicate of a file already in the storage to avoid
     * saving twice the same file.
     *
     * @param ManagedFile $file
     * 
     * @return ManagedFile|null
     */
    private function getFileDuplicate(ManagedFile $file): ?ManagedFile
    {
        if ($file->getExpirationDate() !== null) {
            return null;
        }
        $hash = $file->getHash();
        $storage = $this->getStorage();
        try {
            $result = $storage->loadByHash($hash);
            if ($result instanceof ManagedFile && $result->getIdentifier() !== $file->getIdentifier()) {
                return $result;
            }
        } catch (FileNotFoundException $e) {
            // Ignore..
        }
        return null;
    }

    /**
     * Convert the input into an array of ManagedFile object.
     *
     * Executes the following procedure:
     *   - resolve the configuration given as input (optional)
     *   - normalize the input using the adapters
     *   - validate resulting ManagedFile objects with no error
     *   - execute processors defined in the configuration on valid files
     *   - check if another file have already been defined with the exact same content and configuration, and use it if so
     *
     * A ManagedFile object will be created even if errors occurs. Errors are stored in the object.
     *
     * @param mixed                      $input          any input supported by at least one adapter
     * @param string|array|Configuration $configuration (optional)
     *
     * @return ManagedFile[]
     */
    private function prepareForRegistration($input, $configuration = null)
    {
        $configuration = $this->resolveConfiguration($configuration);
        $files = $this->normalizeInput($input, $configuration);
        for ($i = 0, $ii = count($files); $i < $ii; ++$i) {
            $file = $files[$i];
            if ($file->hasError()) {
                continue ;
            }
            // Validate
            $this->validateManagedFiles($file, $configuration);
            if ($file->hasError()) {
                continue;
            }
            // Process
            $this->processManagedFiles($file, $configuration);
            if ($file->hasError()) {
                continue;
            }
            // Find duplicate
            if (($duplicate = $this->getFileDuplicate($file)) !== null && $duplicate->isValid()) {
                $versions = $file->getVersions();
                foreach ($versions as $name => $version) {
                    $this->filesystem->release($version);
                    $this->filesystem->remove($version);
                }
                $duplicate->incrementUsageCount();
                $files[$i] = $duplicate;
            }
        }
        return $files;
    }

    /**
     * Takes an input and returns a normalized array of File instances.
     * This method will test the input against all adapters until it finds a valid one.
     *
     * @param mixed         $input         input to normalize
     * @param Configuration $configuration configuration
     *
     * @return ManagedFile[] normalized output
     *
     * @throws
     */
    private function normalizeInput($input, Configuration $configuration)
    {
        $output = [];
        if (!is_array($input)) {
            $input = [$input];
        }
        $isAssoc = array_keys($input) !== range(0, count($input) - 1);
        if ($isAssoc && count(array_filter(array_keys($input), 'is_int')) > 0) {
            $this->errorTracker->trackAndThrow(new InvalidArgumentException(
                'You can\'t set numerical indexes to an associative array as input. '.
                'The array should be sequential or only contain string keys.'
            ));
        }
        $managedFile = null;
        foreach ($input as $key => $value) {
            for ($i = 0, $ii = count($this->adapters); $i < $ii; ++$i) {
                if ($this->adapters[$i]->supports($value)) {
                    if (!$isAssoc || $managedFile === null) {
                        $cloned = Configuration::createFromGenericRepresenation($this->container, $configuration->exportGenericRepresentation());
                        $managedFile = $this->container->get(ManagedFile::class);
                        $managedFile->setConfiguration($cloned);
                        $managedFile->setIdentifier($this->uniqueIdGenerator->generateId());
                        $managedFile->incrementUsageCount();
                        $output[] = $managedFile;
                    }
                    try {
                        $file = $this->adapters[$i]->normalize($value);
                        if ($file instanceof File) {
                            $file->setIdentifier($managedFile->getIdentifier());
                            $file->shouldBeProcessed(true);
                            $file->isPublic($managedFile->getConfiguration()->isPublic());
                            $managedFile->addVersion($file, $isAssoc ? $key : 'default');
                        } else {
                            $this->errorTracker->track(
                                new \RuntimeException(sprintf(
                                    'The adapter "%s" didn\'t return a valid File instance.', get_class($this->adapters[$i])
                                ))
                            );
                        }
                    } catch (\Exception $e) {
                        $managedFile->addErrors($e->getMessage());
                    }
                    break ;
                }
            }
            if ($i >= $ii) {
                $str = is_object($value) ? ('Instance of '.get_class($value)) : ((string)$value);
                if (strlen($str) > 100) {
                    $str = substr($str, 0, 100).'[..]';
                }
                $this->errorTracker->track(new RuntimeException(sprintf('No adapter found for input "%s".', $str)));
                $managedFile->addErrors(sprintf('No adapter found to handle this input: "%s".', $str));
            }
        }
        for ($i = 0, $ii = count($output); $i < $ii; ++$i) {
            if (!$output[$i]->hasError() && !$output[$i]->hasDefaultVersion()) {
                $this->errorTracker->track(
                    new RuntimeException('No "default" version found for the file. A default version is mandatory.'),
                    ['file' => $output[$i]]
                );
            }
        }
        return $output;
    }

    /**
     * Get the current storage.
     *
     * @return StorageInterface
     *
     * @throws
     */
    private function getStorage(): StorageInterface
    {
        if (array_key_exists($this->storageType, $this->storages)) {
            return $this->storages[$this->storageType];
        }
        $this->errorTracker->trackAndThrow(
            new RuntimeException(sprintf('No storage named "%s" has been registered.', $this->storageType))
        );
    }

    /**
     * Validates an instance or an array of instances of ManagedFile.
     * If violations are found, error messages are added to the ManagedFile instance.
     *
     * @param ManagedFile|ManagedFile[] $files
     * @param Configuration             $configuration
     */
    private function validateManagedFiles($files, Configuration $configuration): void
    {
        if (!$configuration->hasConstraints()) {
            return ;
        }
        if (!is_array($files)) {
            $files = [$files];
        }
        for ($i = 0, $ii = count($files); $i < $ii; ++$i) {
            $versions = $files[$i]->getVersions();
            foreach ($versions as $name => $file) {
                $this->validateFile($file, $configuration);
            }
        }
    }

    /**
     * Execute processors for an instance or an array of instances of ManagedFile.
     * The whole processor configuration is execute independently for each of its versions.
     *
     * @param ManagedFile|ManagedFile[] $files
     * @param Configuration             $configuration
     */
    private function processManagedFiles($files, Configuration $configuration): void
    {
        if (!$configuration->hasProcessors()) {
            return ;
        }
        if (!is_array($files)) {
            $files = [$files];
        }
        $sequences = $configuration->getProcessorsSequences();
        for ($i = 0, $ii = count($files); $i < $ii; ++$i) {
            $versions = $files[$i]->getVersions();
            foreach ($versions as $name => $file) {
                /** @var File $file */
                if ($file->shouldBeProcessed()) {
                    for ($j = 0, $jj = count($sequences); $j < $jj; ++$j) {
                        $previousOutput = $file;
                        for ($k = 0, $kk = count($sequences[$j]); $k < $kk; ++$k) {
                            $processor = $sequences[$j][$k];
                            try {
                                if ($processor->supports($previousOutput, $files[$i])) {
                                    $output = $processor->process($previousOutput, $files[$i]);
                                    if (!($output instanceof File)) {
                                        $this->errorTracker->trackAndThrow(new RuntimeException(
                                            sprintf('Invalid output for processor "%s". It must return a File instance.', get_class($processor))
                                        ), ['output' => $output]);
                                    }
                                    $previousOutput = $output;
                                }
                            } catch (\Exception $e) {
                                $file->addErrors($e->getMessage());
                                break;
                            }
                        }
                        if ($file->hasError()) {
                            break;
                        }
                    }
                }
                if ($file->hasError()) {
                    break ;
                }
            }
        }
    }

    /**
     * Validate a File instance.
     *
     * @param File          $file
     * @param Configuration $configuration
     */
    private function validateFile(File $file, Configuration $configuration): void
    {
        if (!$configuration->hasConstraints()) {
            return ;
        }
        $constraints = $configuration->getConstraints();
        $violations = $this->validator->validate($file, $constraints);
        for ($i = 0, $ii = count($violations); $i < $ii; ++$i) {
            $file->addErrors($violations[$i]->getMessage());
        }
    }


    /**
     * Resolve an input configuration to a Configuration object.
     * The input can be:
     *   - a string: a slot name
     *   - an array: a slot configuration array
     *   - an object: a Configuration instance (simply returned if so)
     *   - null: no configuration, a default configuration instance is created
     *
     * @param string|array|Configuration $configuration
     *
     * @return Configuration
     *
     * @throws
     */
    private function resolveConfiguration($configuration): Configuration
    {
        if ($configuration === null) {
            return new Configuration();
        }
        if ($configuration instanceof Configuration) {
            return $configuration;
        }
        $builder = $this->container->get(ConfigurationBuilder::class);
        $builder->loadPreset($configuration);
        return $builder->getConfiguration();
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
    private function ensureManagedFile($file): ManagedFile
    {
        if ($file instanceof ManagedFile) {
            return $file;
        }
        if ($file instanceof PublicFile) {
            $file = $file->identifier;
        }
        if (!is_string($file)) {
            $this->errorTracker->trackAndThrow(new InvalidArgumentException('Argument should be a string or a ManagedFile instance.'), ['input' => $file]);
        }
        return $this->getStorage()->load($file);
    }
}
