<?php
namespace Webeak\Bundle\FileBundle;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Webeak\Bundle\DoctrineExtensionsBundle\Utils\UniqueIdGenerator;
use Webeak\Bundle\EssentialBundle\Exception\UsageException;
use Webeak\Bundle\EssentialBundle\RandomTaskFactory;
use Webeak\Bundle\FileBundle\Adapter\AdapterInterface;
use Webeak\Bundle\FileBundle\Exception\FileExpiredException;
use Webeak\Bundle\FileBundle\Exception\FileNotFoundException;
use Webeak\Bundle\FileBundle\Exception\FileProtectedException;
use Webeak\Bundle\FileBundle\Processor\ProcessorInterface;
use Webeak\Bundle\FileBundle\Storage\StorageCollection;
use Webeak\Bundle\FileBundle\Storage\StorageInterface;

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
    /** @var AdapterInterface[] */
    private array $adapters;

    /** @var ProcessorInterface[] */
    private array $processors;

    public function __construct(private readonly ContainerInterface    $container,
                                private readonly ValidatorInterface    $validator,
                                private readonly UniqueIdGenerator     $uniqueIdGenerator,
                                private readonly FileIdentifierManager $fileIdentifierManager,
                                private readonly RandomTaskFactory     $randomTaskFactory,
                                private readonly StorageCollection     $storages,
                                private readonly int                   $tempFilesLifetime)
    {
        $this->adapters = [];
        $this->processors = [];
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
    public function register(mixed $input, mixed $configuration = null): array
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
     * @return ManagedFile[]
     */
    public function registerByContent(mixed $content, string $name, array|string|Configuration $configuration = null): array
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'file_manager_');
        if (!@file_put_contents($tempPath, $content)) {
            throw new UsageException('Failed to write temporary file.');
        }
        return $this->register($tempPath, $configuration);
    }

    /**
     * Register a file for a limited period of time.
     * The file must be confirmed before the expiration date is reached or the CRON task will remove it.
     *
     * The lifetime of the file is determined by the configuration property "temp_files_lifetime" (default value is 2 hours).
     * For more control, you can set the expiration date of the file in the configuration object yourself.
     *
     * @return ManagedFile[]
     *
     * @throws
     */
    public function registerTemporarily(mixed $input, array|string|Configuration $configuration = null): array
    {
        $configuration = $this->resolveConfiguration($configuration);
        if (!$configuration->getAutoConfirm()) {
            $now = new \DateTime('now', new \DateTimeZone('UTC'));
            $now->add(new \DateInterval(sprintf('PT%dS', max(60, intval($this->tempFilesLifetime)))));
            $configuration->setExpirationDate($now);
        } else {
            $configuration->setExpirationDate(null);
        }
        return $this->register($input, $configuration);
    }

    /**
     * To confirm registration of a temporary file.
     *
     * @throws
     */
    public function confirmRegistration(PublicFile|ManagedFile|string $file): PublicFile
    {
        $file = $this->ensureManagedFile($file);
        $storage = $this->getStorage($file);
        $this->ensureAccessGranted($file);
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
     * @throws
     */
    public function get(string $identifier): ManagedFile
    {
        $storageType = $this->fileIdentifierManager->getStorageTypeForIdentifier($identifier);
        $storage = $this->getStorage($storageType);
        $managedFile = $storage->load($identifier);
        if ($managedFile->hasExpired()) {
            throw new FileNotFoundException('Not found.');
        }
        $this->ensureAccessGranted($managedFile);
        return $managedFile;
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
     * If `force` is true, the usage count is ignored, and the file is always removed.
     *
     * @throws
     */
    public function remove(PublicFile|ManagedFile|string $file, array|string|null $version = null, bool $force = false): void
    {
        $file = $this->ensureManagedFile($file);
        $this->ensureAccessGranted($file);
        if ($version !== null) {
            if (!$file->hasVersion($version)) {
                throw new UsageException(sprintf('No version "%s" have been found.', $version));
            }
            $versions = $file->getVersions();
            // Remove the only version of the file is the same as removing the file as a whole.
            if (count($versions) === 1) {
                $this->remove($file);
                return ;
            }
            $this->getStorage($file)->removeVersion($file, $version);
        } else {
            $this->getStorage($file)->remove($file, $force);
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
    public function persist(mixed $files): void
    {
        if (!is_array($files)) {
            $files = [$files];
        }
        for ($i = 0, $ii = count($files); $i < $ii; ++$i) {
            $file = $files[$i];
            if (!($file instanceof ManagedFile)) {
                throw new UsageException('Invalid input for persist(). Only "ManagedFile" objects are accepted.');
            }
            $this->getStorage($file)->persist($file);
        }
    }

    /**
     * Flush waiting operations like writing files' metadata in the database.
     * This will be done automatically on the 'kernel.terminate' event.
     * However, you can force it manually to ensure its done and have a feedback.
     *
     * @throws
     */
    public function flush(): void
    {
        foreach ($this->storages as $storage) {
            $storage->flush();
        }
    }

    /**
     * Dependency injection callback for registering constraints bound using services' tags.
     *
     * To register here you need to add the tag "wb.file.file_manager_adapter" to the service concerned.
     */
    public function registerAdapter(mixed $service, array $attributes): void
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
     * @throws
     */
    public function registerProcessor(mixed $service, string $serviceId, array $attributes): void
    {
        if (!($service instanceof ProcessorInterface)) {
            throw new \InvalidArgumentException(sprintf('The processor "%s" must implement the "ProcessorInterface".', get_class($service)));
        }
        $this->processors[$serviceId] = $service;
        for ($i = 0, $ii = count($attributes); $i < $ii; ++$i) {
            if (array_key_exists('alias', $attributes[$i])) {
                if (array_key_exists($attributes[$i]['alias'], $this->processors)) {
                    throw new UsageException(sprintf(
                        'A processor named "%s" has already been defined.',
                        $attributes[$i]['alias']
                    ));
                }
                $this->processors[$attributes[$i]['alias']] = $service;
            }
        }
    }

    /**
     * Symfony 'kernel.terminate' event.
     * That's where the flushing of entities only known by the tracker occurs.
     */
    public function onKernelTerminate(): void
    {
        $this->flush();
        $this->maybeRunCleanupCommands();
    }

    /**
     * Clear files for which the expiration date has been reached.
     */
    public function clearExpiredFiles(?OutputInterface $output = null): bool
    {
        $failureCount = 0;
        foreach ($this->storages as $storage) {
            if (!$storage->clearExpiredFiles($output)) {
                ++$failureCount;
            }
        }
        return $failureCount === 0;
    }

    /**
     * List files matching certain criteria.
     */
    public function find(string $storageType, ?int $offset, array $filters = [], int $maxResults = 20): array
    {
        return $this->getStorage($storageType)->find($offset, $filters, $maxResults);
    }

    /**
     * Try to find the duplicate of a file already in the storage to avoid
     * saving twice the same file.
     */
    private function getFileDuplicate(ManagedFile $file): ?ManagedFile
    {
        if ($file->getExpirationDate() !== null) {
            return null;
        }
        $hash = $file->getHash();
        $storage = $this->getStorage($file);
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
     * @return ManagedFile[]
     */
    private function prepareForRegistration(mixed $input, array|string|null|Configuration $configuration = null): array
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
                $this->remove($file);
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
     * @return ManagedFile[] normalized output
     *
     * @throws
     */
    private function normalizeInput(mixed $input, Configuration $configuration): array
    {
        $output = [];
        if (!is_array($input)) {
            $input = [$input];
        }
        $isAssoc = array_keys($input) !== range(0, count($input) - 1);
        if ($isAssoc && count(array_filter(array_keys($input), 'is_int')) > 0) {
            throw new UsageException(
                'You can\'t set numerical indexes to an associative array as input. '.
                'The array should be sequential or only contain string keys.'
            );
        }
        $managedFile = null;
        foreach ($input as $key => $value) {
            for ($i = 0, $ii = count($this->adapters); $i < $ii; ++$i) {
                if ($this->adapters[$i]->supports($value)) {
                    if (!$isAssoc || $managedFile === null) {
                        $cloned = Configuration::createFromGenericRepresenation($this->container, $configuration->exportGenericRepresentation());
                        $managedFile = $this->container->get(ManagedFile::class);
                        $managedFile->setConfiguration($cloned);
                        $managedFile->setIdentifier($this->fileIdentifierManager->create($managedFile->getStorageType()));
                        $managedFile->incrementUsageCount();
                        $output[] = $managedFile;
                    }
                    try {
                        $file = $this->adapters[$i]->normalize($value, $managedFile);
                        if ($file instanceof VirtualFile) {
                            $file->shouldBeProcessed(true);
                            $file->isPublic($managedFile->getConfiguration()->isPublic());
                            $managedFile->addVersion($file, $isAssoc ? $key : 'default');
                        } else {
                            throw new UsageException(sprintf(
                                'The adapter "%s" didn\'t return a valid File instance.',
                                get_class($this->adapters[$i])
                            ));
                        }
                    } catch (\Throwable $e) {
                        if ($e instanceof UsageException) {
                            throw $e;
                        }
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
                $managedFile->addErrors(sprintf('No adapter found to handle this input: "%s".', $str));
            }
        }
        return $output;
    }

    /**
     * Get the current storage.
     *
     * @throws
     */
    private function getStorage(mixed $fileOrStorageTye): StorageInterface
    {
        if ($fileOrStorageTye instanceof ManagedFile) {
            $fileOrStorageTye = $fileOrStorageTye->getStorageType();
        }
        if (is_string($fileOrStorageTye) && $this->storages->offsetExists($fileOrStorageTye)) {
            return $this->storages->offsetGet($fileOrStorageTye);
        }
        throw new UsageException(sprintf(
            'No storage named "%s" has been registered.',
            $fileOrStorageTye
        ));
    }

    /**
     * Validates an instance or an array of instances of ManagedFile.
     * If violations are found, error messages are added to the ManagedFile instance.
     */
    private function validateManagedFiles(array|ManagedFile $files, Configuration $configuration): void
    {
        if (!$configuration->hasConstraints()) {
            return ;
        }
        if (!is_array($files)) {
            $files = [$files];
        }
        for ($i = 0, $ii = count($files); $i < $ii; ++$i) {
            $versions = $files[$i]->getVersions();
            foreach ($versions as $file) {
                $this->validateFile($file, $configuration);
            }
        }
    }

    /**
     * Execute processors for an instance or an array of instances of ManagedFile.
     * The whole processor configuration is execute independently for each of its versions.
     */
    private function processManagedFiles(array|ManagedFile $files, Configuration $configuration): void
    {
        if (!$configuration->hasProcessors()) {
            return ;
        }
        if (!is_array($files)) {
            $files = [$files];
        }
        $sequences = $configuration->getProcessorsSequences();
        foreach ($files as $managedFile) {
            $versions = $managedFile->getVersions();
            foreach ($versions as $versionFile) {
                /** @var VirtualFile $versionFile */
                if ($versionFile->shouldBeProcessed()) {
                    for ($j = 0, $jj = count($sequences); $j < $jj; ++$j) {
                        $previousOutput = $versionFile;
                        for ($k = 0, $kk = count($sequences[$j]); $k < $kk; ++$k) {
                            $processor = $sequences[$j][$k];
                            try {
                                if ($processor->supports($previousOutput, $managedFile)) {
                                    $output = $processor->process($previousOutput, $managedFile);
                                    if (!($output instanceof VirtualFile)) {
                                        throw new UsageException(sprintf(
                                            'Invalid output for processor "%s". It must return a File instance.',
                                            get_class($processor)
                                        ), 0, null, ['output' => $output]);
                                    }
                                    $previousOutput = $output;
                                }
                            } catch (\Exception $e) {
                                if ($e instanceof UsageException) {
                                    throw $e;
                                }
                                $versionFile->addErrors($e->getMessage());
                                break;
                            }
                        }
                        if ($versionFile->hasError()) {
                            break;
                        }
                    }
                }
                if ($versionFile->hasError()) {
                    break ;
                }
            }
        }
    }

    /**
     * Validate a File instance.
     */
    private function validateFile(VirtualFile $file, Configuration $configuration): void
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
     * @throws
     */
    private function resolveConfiguration(array|string|null|Configuration $configuration): Configuration
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
            throw new UsageException(
                'Argument should be a string or a ManagedFile instance.',
                0,
                null,
                ['input' => $file]
            );
        }
        // At this point the input is necessarily a file identifier, otherwise it must fail anyway.
        $identifier = $file;
        $storageType = $this->fileIdentifierManager->getStorageTypeForIdentifier($identifier);
        $managedFile = $this->getStorage($storageType)->load($identifier);
        if ($managedFile->hasExpired()) {
            throw new FileExpiredException(sprintf('File expired on "%s".', $managedFile->getExpirationDate()?->format('Y-m-d H:i:s')));
        }
        return $managedFile;
    }

    /**
     * Check if the current user has access to a given file and throw an exception if not.
     *
     * @throws
     */
    private function ensureAccessGranted(ManagedFile $managedFile): void
    {
        if (!$managedFile->hasAccessLimitations()) {
            return ;
        }
        if (!$this->container->has('security.token_storage')) {
            throw new UsageException('You must install symfony/security-bundle in order to use access control on the file manager.');
        }
        $tokenStorage = $this->container->get('security.token_storage');
        $token = $tokenStorage->getToken();
        $user = $token ? $token->getUser() : null;
        if (!$user || !$managedFile->hasAccess($user)) {
            throw new FileProtectedException('Access denied.');
        }
    }

    /**
     * Will maybe clear expired files and/or temporary files.
     *
     * @throws
     */
    private function maybeRunCleanupCommands(): void
    {
        // TODO
        foreach ($this->storages as $storage) {
            //$this->randomTaskFactory->create('wb:file:clear', [$this->localFileSystem, 'clearOldTemporaryFiles'], 5, 0)->runMaybe();
            //$this->randomTaskFactory->create('wb:file:clear-expired', [$storage, 'clearExpiredFiles'], 5, 0)->runMaybe();
        }
    }
}
