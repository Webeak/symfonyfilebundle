<?php
namespace Webeak\Bundle\FileBundle;

use Webeak\Bundle\ErrorTrackerBundle\ErrorTrackerInterface;
use Symfony\Component\Routing\Router;
use Webeak\Bundle\EssentialBundle\Exception\Exception;
use Webeak\Bundle\EssentialBundle\Exception\InvalidArgumentException;
use Webeak\Bundle\EssentialBundle\Exception\InvalidConfigurationException;
use Webeak\Bundle\FileBundle\Exception\FileNotFoundException;

/**
 * Represent a file managed by the file manager.
 */
class ManagedFile
{
    /** @var ErrorTrackerInterface */
    protected $errorTracker;

    /** @var FileSystem */
    protected $filesystem;

    /** @var Router */
    protected $router;

    /**
     * Unique identifier of the file and all its versions.
     * This is the identifier the app will always refer to.
     *
     * @var string
     */
    protected $identifier;

    /**
     * Associative array of versionName => File instance.
     * A "default" version is mandatory.
     *
     * @var File
     */
    protected $versions;

    /**
     * Array of versions names waiting to be effectively removed.
     *
     * @var string[]
     */
    protected $removedVersions;

    /** @var Configuration */
    protected $configuration;

    /** @var string[] */
    protected $errors;

    /** @var integer  */
    private $usageCount;

    /** @var string */
    private $publicRootDir;

    /** @var string */
    private $httpRoot;

    /**
     * Create a ManagedFile instance.
     *
     * @param ErrorTrackerInterface $errorTracker
     * @param FileSystem            $filesystem
     * @param Router                $router
     * @param string|null           $httpRoot
     * @param string                $projectRootDir
     *
     * @throws
     */
    public function __construct(ErrorTrackerInterface $errorTracker,
                                FileSystem $filesystem,
                                Router $router,
                                ?string $httpRoot,
                                string $projectRootDir)
    {
        $this->errorTracker = $errorTracker;
        $this->filesystem = $filesystem;
        $this->router = $router;
        $this->publicRootDir = $projectRootDir . '/public';
        $this->httpRoot = $httpRoot;
        $this->versions = [];
        $this->removedVersions = [];
        $this->errors = [];
        $this->configuration = null;
        $this->usageCount = 0;
        if (!$this->publicRootDir) {
            $this->errorTracker->trackAndThrow(new InvalidArgumentException('Invalid web root dir.'));
        }
    }

    /**
     * Add a new version of this file.
     *
     * @param File    $version
     * @param string  $name
     * @param boolean $override (optional) true to override if the name already exists, default: true
     *
     * @return $this
     */
    public function addVersion(File $version, $name = 'default', $override = true)
    {
        if (!array_key_exists($name, $this->versions) || $override) {
            $version->setVersionName($name);
            $this->versions[$name] = $version;
        }
        return $this;
    }

    /**
     * Test if the file has a default version.
     *
     * @return boolean
     */
    public function hasDefaultVersion()
    {
        return $this->hasVersion('default');
    }

    /**
     * Test if a version of the file exist.
     *
     * @param string $version
     *
     * @return boolean
     */
    public function hasVersion($version)
    {
        return array_key_exists($version, $this->versions);
    }

    /**
     * Get the File instance of a version.
     *
     * @param string $name version's name
     *
     * @return File|void
     *
     * @throws
     */
    public function getVersion($name)
    {
        if (array_key_exists($name, $this->versions)) {
            return $this->versions[$name];
        }
        $this->errorTracker->trackAndThrow(new FileNotFoundException(sprintf(
            'No "%s" version has been found for the file "%s".',
            $name, $this->getIdentifier()
        )));
    }

    /**
     * Remove a version of a file.
     *
     * @param string $name
     */
    public function removeVersion($name)
    {
        if (array_key_exists($name, $this->versions) && !in_array($name, $this->removedVersions)) {
            $this->removedVersions[] = $name;
        }
    }

    /**
     * Get the array of File waiting to be removed.
     *
     * @return File[]
     */
    public function getRemovedVersions()
    {
        $output = [];
        for ($i = 0, $ii = count($this->removedVersions); $i < $ii; ++$i) {
            $name = $this->removedVersions[$i];
            $output[$name] = $this->getVersion($name);
        }
        return $output;
    }

    /**
     * Add an error message or an array of messages to the global errors array.
     *
     * @param string|array $errors
     *
     * @return $this
     */
    public function addErrors($errors)
    {
        if (!is_array($errors)) {
            $errors = [$errors];
        }
        $this->errors = array_merge($this->errors, $errors);
        return $this;
    }

    /**
     * Test if any version of the files have an error.
     *
     * @return boolean
     */
    public function hasError()
    {
        if (count($this->errors) > 0) {
            return true;
        }
        foreach ($this->versions as $version) {
            if ($version->hasError()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get an associative array where the key is the version name
     * and the value is an array of error messages.
     *
     * @return array
     */
    public function getErrors()
    {
        $output = ['_global' => $this->errors];
        foreach ($this->versions as $name => $version) {
            $output[$name] = $version->getErrors();
        }
        return $output;
    }

    /**
     * Get a flattened array of errors where errors of all versions
     * are concatenated in a single array.
     *
     * @return string[]
     */
    public function getFlattenedErrors()
    {
        $output = $this->errors;
        foreach ($this->versions as $name => $version) {
            $output = array_merge($output, $version->getErrors());
        }
        return $output;
    }

    /**
     * Get the whole list of versions for this file.
     *
     * @return array
     */
    public function getVersions()
    {
        return $this->versions;
    }

    /**
     * Get the list of versions names.
     *
     * @return array
     */
    public function getVersionsNames()
    {
        return array_keys($this->versions);
    }

    /**
     * Set a version file.
     *
     * @param string $name
     * @param File   $version
     *
     * @return $this
     */
    public function setVersion($name, File $version)
    {
        $this->versions[$name] = $version;
        return $this;
    }

    /**
     * Get the content of the file.
     *
     * @param string $version (optional)
     *
     * @return mixed
     */
    public function getContent($version = 'default')
    {
        return $this->filesystem->read($this->getVersion($version));
    }

    /**
     * Get the content of a file.
     *
     * @param mixed  $content content of the file
     * @param string $version (optional)
     *
     * @throws
     */
    public function setContent($content, $version = 'default')
    {
        $this->filesystem->write($this->getVersion($version), $content);
    }

    /**
     * Get the unique identifier of the file.
     *
     * @return string
     */
    public function getIdentifier()
    {
        return $this->identifier;
    }

    /**
     * Get the absolute path to the file in the filesystem.
     * You should avoid using this and let the file manager handle the file.
     *
     * NEVER use this to remove a file, as it will still exist in the file manager database.
     *
     * @param string $version
     *
     * @return string
     */
    public function getLocalPath($version = 'default')
    {
        return $this->getVersion($version)->getRealPath();
    }

    /**
     * Get the HTTP path to the file.
     * It can vary depending on the access rights associated with the file.
     *
     * If the file is publicly available (has no access rights), the HTTP link will be
     * a direct link to the file in the "web/" folder.
     *
     * If access rights have been added to the file, then the HTTP path will lead to
     * a proxy action where the user asking to view the file will be checked.
     *
     * @param string $version
     *
     * @return string
     *
     * @throws
     */
    public function getPublicPath($version = 'default')
    {
        // TODO: implement this.
        throw new Exception('Method getPublicPath() has not been implemented yet.');
//        $file = $this->getVersion($version);
//        if ($this->configuration->isPublic()) {
//            // Get direct HTTP path
//        } else {
//            // Get proxy HTTP path
//        }
    }
    
    /**
     * Set the identifier.
     *
     * @param string $identifier
     *
     * @return $this
     */
    public function setIdentifier($identifier)
    {
        $this->identifier = $identifier;
        return $this;
    }

    /**
     * Set the usage count.
     *
     * @param integer $count
     *
     * @return ManagedFile
     */
    public function setUsageCount($count)
    {
        $this->usageCount = max(0, intval($count));
        return $this;
    }

    /**
     * Get the usage count.
     *
     * @return integer
     */
    public function getUsageCount()
    {
        return $this->usageCount;
    }

    /**
     * Increment the usage count.
     *
     * @param integer $count
     *
     * @return ManagedFile
     */
    public function incrementUsageCount($count = 1)
    {
        $this->usageCount += max(0, intval($count));
        return $this;
    }

    /**
     * Decrement the usage count.
     *
     * @param integer $count
     *
     * @return ManagedFile
     */
    public function decrementUsageCount($count = 1)
    {
        $this->usageCount -= max(0, intval($count));
        return $this;
    }

    /**
     * Test if the managed file contains valid versions pointing on existing files.
     * If any version is invalid, the whole file is considered invalid.
     *
     * @return boolean
     */
    public function isValid()
    {
        if (!is_array($this->versions) || !count($this->versions)) {
            return false;
        }
        foreach ($this->versions as $name => $version) {
            if (!$version->isFile() || !$version->isReadable()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Set extra data to attach to the file.
     *
     * @param mixed  $extra
     *
     * @return $this
     */
    public function setExtra($extra)
    {
        $this->configuration->setExtra($extra);
        return $this;
    }

    /**
     * Get extra data associated with the file.
     *
     * @return array
     */
    public function getExtra()
    {
        return $this->configuration->getExtra();
    }

    /**
     * Set public extra data to attach to the file.
     * These extra WILL BE available in the PublicFile object.
     *
     * @param mixed  $extra
     *
     * @return $this
     */
    public function setPublicExtra($extra)
    {
        $this->configuration->setPublicExtra($extra);
        return $this;
    }

    /**
     * Get extra data associated with the file.
     * These extra WILL BE available in the PublicFile object.
     *
     * @return array
     */
    public function getPublicExtra()
    {
        return $this->configuration->getPublicExtra();
    }

    /**
     * Gets the expiration date of the file.
     *
     * @return \DateTime|null
     */
    public function getExpirationDate()
    {
        return $this->configuration->getExpirationDate();
    }

    /**
     * Set the configuration associated with the file.
     *
     * @param Configuration $configuration
     *
     * @return $this
     */
    public function setConfiguration(Configuration $configuration)
    {
        $this->configuration = $configuration;
        return $this;
    }

    /**
     * Get the configuration associated with the file.
     *
     * @return Configuration|null
     */
    public function getConfiguration()
    {
        return $this->configuration;
    }

    /**
     * Get a PublicFile instance for the file.
     *
     * @return PublicFile|null
     *
     * @throws
     */
    public function getPublicFile()
    {
        if ($this->hasError()) {
            return null;
        }
        $publicFile = new PublicFile();
        $publicFile->name = $this->getFilename();
        $publicFile->type = $this->getMimeType();
        $publicFile->identifier = $this->identifier;
        $publicFile->extra = $this->getPublicExtra();
        $publicFile->versions = [];
        foreach ($this->versions as $name => $version) {
            $publicVersion = new PublicFileVersion();
            $publicVersion->name = $version->getVirtualName();
            $publicVersion->size = $version->getSize();
            $publicVersion->type = $version->getMimeType();
            if ($version->isPublic()) {
                if (!$this->httpRoot) {
                    $this->errorTracker->trackAndThrow(new InvalidConfigurationException(
                        'The "http_root" option of "wb_file" has not been defined. You must provide it in order to generate a public URL.'
                    ));
                }
                $relativePath = str_replace('\\', '/', str_replace($this->publicRootDir, '', $version->getRealPath()));
                $publicVersion->url = rtrim($this->httpRoot, '/').'/'.trim($relativePath, '/');
            } else {
                $publicVersion->url = $this->router->generate('wb_file_proxy', [
                    'identifier' => $this->identifier,
                    'version' => $name,
                    'type' => $version->isImage() ? 'i' : 'g'
                ], Router::ABSOLUTE_URL);
            }
            $publicFile->versions[$name] = $publicVersion;
        }
        return $publicFile;
    }

    /**
     * \Symfony\Component\HttpFoundation\File\File proxies.
     */

    /**
     * Returns the extension based on the mime type.
     *
     * If the mime type is unknown, returns null.
     *
     * This method uses the mime type as guessed by getMimeType()
     * to guess the file extension.
     *
     * @param string $version
     *
     * @return string|null The guessed extension or null if it cannot be guessed
     */
    public function guessExtension($version = 'default')
    {
        return $this->getVersion($version)->guessExtension();
    }

    /**
     * Returns the mime type of the file.
     *
     * The mime type is guessed using a MimeTypeGuesser instance, which uses finfo(),
     * mime_content_type() and the system binary "file" (in this order), depending on
     * which of those are available.
     *
     * @param string $version
     *
     * @return string|null The guessed mime type (e.g. "application/pdf")
     */
    public function getMimeType($version = 'default')
    {
        return $this->getVersion($version)->getMimeType();
    }

    /**
     * Get an md5 of the md5 of each files versions.
     *
     * @return string
     */
    public function getSourceFilesHash()
    {
        $hashes = '';
        $keys = array_keys($this->versions);
        sort($keys);
        for ($i = 0, $ii = count($keys); $i < $ii; ++$i) {
            $realPath = $this->versions[$keys[$i]]->getRealPath();
            if ($realPath) {
                $hashes .= '#' . md5_file($realPath);
            } else {
                $hashes .= '#not-found'; // So no risk to incorrectly match a hash.
            }
        }
        return md5($hashes);
    }

    /**
     * Gets a unique md5 hash corresponding to the current state of the managed file.
     * The hash includes :
     *   - hashes from the content of the source files
     *   - the current configuration
     *
     * @return string
     */
    public function getHash()
    {
        $exported = $this->configuration->exportGenericRepresentation();
        unset($exported['expirationDate']);
        return md5($this->getSourceFilesHash().'#'.json_encode($exported));
    }

    /**
     * \SplFileInfo proxies.
     */

    /**
     * Gets the filename
     *
     * @param string $version
     *
     * @link http://php.net/manual/en/splfileinfo.getfilename.php
     * @return string The filename.
     * @since 5.1.2
     */
    public function getFilename($version = 'default')
    {
        return $this->getVersion($version)->getVirtualName();
    }

    /**
     * Gets the file extension
     *
     * @param string $version
     *
     * @link http://php.net/manual/en/splfileinfo.getextension.php
     * @return string a string containing the file extension, or an
     * empty string if the file has no extension.
     * @since 5.3.6
     */
    public function getExtension($version = 'default')
    {
        $file = $this->getVersion($version);
        if (!!($ext = $file->getExtension())) {
            return $ext;
        }
        return $file->guessExtension();
    }

    /**
     * Gets file size
     *
     * @param string $version
     *
     * @link http://php.net/manual/en/splfileinfo.getsize.php
     * @return int The filesize in bytes.
     * @since 5.1.2
     */
    public function getSize($version = 'default')
    {
        return $this->getVersion($version)->getSize();
    }

    /**
     * Gets last access time of the file
     *
     * @param string $version
     *
     * @link http://php.net/manual/en/splfileinfo.getatime.php
     * @return int the time the file was last accessed.
     * @since 5.1.2
     */
    public function getATime($version = 'default')
    {
        return $this->getVersion($version)->getATime();
    }

    /**
     * Gets the last modified time
     *
     * @param string $version
     *
     * @link http://php.net/manual/en/splfileinfo.getmtime.php
     * @return int the last modified time for the file, in a Unix timestamp.
     * @since 5.1.2
     */
    public function getMTime($version = 'default')
    {
        return $this->getVersion($version)->getMTime();
    }

    /**
     * Gets the inode change time
     *
     * @param string $version
     *
     * @link http://php.net/manual/en/splfileinfo.getctime.php
     * @return int The last change time, in a Unix timestamp.
     * @since 5.1.2
     */
    public function getCTime($version = 'default')
    {
        return $this->getVersion($version)->getCTime();
    }
}
