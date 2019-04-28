<?php
namespace Webeak\Bundle\FileBundle;

use Symfony\Component\Security\Core\User\UserInterface;
use Webeak\Bundle\ErrorTrackerBundle\ErrorTrackerInterface;
use Symfony\Component\Routing\Router;
use Webeak\Bundle\EssentialBundle\Exception\InvalidArgumentException;
use Webeak\Bundle\EssentialBundle\Exception\InvalidConfigurationException;
use Webeak\Bundle\EssentialBundle\Exception\RuntimeException;
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
     *
     * @var File
     */
    protected $versions;

    /**
     * Array of removed versions.
     *
     * @var array
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
     * @param string  $name     (optional, default: 'default')
     * @param boolean $override (optional, default: true) true to override if the name already exists
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
            $this->removedVersions[$name] = $this->versions[$name];
            unset($this->versions[$name]);
        }
    }

    /**
     * Get the array of File waiting to be removed.
     *
     * @return array
     */
    public function getRemovedVersions()
    {
        return $this->removedVersions;
    }

    /**
     * Test if the file has expired.
     *
     * @return boolean
     */
    public function hasExpired(): bool
    {
        $expirationDate = $this->configuration->getExpirationDate();
        if ($expirationDate instanceof \DateTime) {
            try {
                return new \DateTime() >= $expirationDate;
            } catch (\Exception $e) {
                // Makes no sense that new \DateTime() throw an exception in this case but the try/catch is
                // here to remove ide warning because there is no @throws annotation on the comments.
                return true;
            }
        }
        return false;
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
     *
     * @throws
     */
    public function getContent($version = null)
    {
        if (!$version) {
            $version = $this->getDefaultVersionName();
        }
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
    public function setContent($content, $version = null)
    {
        if (!$version) {
            $version = $this->getDefaultVersionName();
        }
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
     *
     * @throws
     */
    public function getLocalPath($version = null)
    {
        if (!$version) {
            $version = $this->getDefaultVersionName();
        }
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
    public function getPublicPath($version = null)
    {
        if (!$version) {
            $version = $this->getDefaultVersionName();
        }
        $file = $this->getVersion($version);
        if ($file->isPublic()) {
            if (!$this->httpRoot) {
                $this->errorTracker->trackAndThrow(new InvalidConfigurationException(
                    'The "http_root" option of "wb_file" has not been defined. You must provide it in order to generate a public url.'
                ));
            }
            $relativePath = str_replace('\\', '/', str_replace($this->publicRootDir, '', $file->getRealPath()));
            return rtrim($this->httpRoot, '/').'/'.trim($relativePath, '/');
        }
        return $this->router->generate('wb_file_proxy', [
            'identifier' => $this->identifier,
            'version' => $version,
            'type' => $file->isImage() ? 'i' : 'g'
        ], Router::ABSOLUTE_URL);
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
     * Test if the file has any kind of limitation over its access.
     *
     * @return boolean
     */
    public function hasAccessLimitations(): bool
    {
        if ($this->configuration->isPublic()) {
            return false;
        }
        return $this->configuration->getRequiredRoles() ||
            $this->configuration->getUsersWhiteListCumulative() ||
            $this->configuration->getUsersWhiteListExclusive() ||
            $this->configuration->getUsersBlackList();
    }

    /**
     * Test all different types of access limitation to work out if a user has access to the file at all.
     *
     * You can call specific methods like "isBlacklisted()" if you need to test for a specific kind of
     * access control but most of the time you should use this method.
     *
     * @param string|UserInterface $user
     *
     * @return boolean
     */
    public function hasAccess($user): bool
    {
        $username = $user instanceof UserInterface ? $user->getUsername() : ((string)$user);
        $roles = $user instanceof UserInterface ? $user->getRoles() : [];
        if ($this->isBlacklisted($username)) {
            return false;
        }
        $exclusiveWhitelist = $this->configuration->getUsersWhiteListExclusive();
        $cumulativeWhitelist = $this->configuration->getUsersWhiteListCumulative();
        if (count($exclusiveWhitelist)) {
            return in_array($username, $exclusiveWhitelist);
        }
        return $this->hasRequiredRoles($roles) || in_array($username, $cumulativeWhitelist);
    }

    /**
     * Test if a user is whitelisted (in either exclusive or cumulative list).
     *
     * This method ONLY test the whitelist, please use "hasAccess()" if you need to test if
     * a user can access the file at all.
     *
     * @param string $username
     *
     * @return boolean
     */
    public function isWhitelisted(string $username): bool
    {
        $exclusive = $this->configuration->getUsersWhiteListExclusive();
        $cumulative = $this->configuration->getUsersWhiteListCumulative();
        return in_array($username, $exclusive) || in_array($username, $cumulative);
    }

    /**
     * Test if a user is blacklisted.
     *
     * This method ONLY test the blacklist, please use "hasAccess()" if you need to test if
     * a user can access the file at all.
     *
     * @param string $username
     *
     * @return boolean
     */
    public function isBlacklisted(string $username): bool
    {
        return in_array($username, $this->configuration->getUsersBlackList());
    }

    /**
     * Test if a user has the required roles to access the file.
     *
     * This method ONLY test for roles, please use "hasAccess()" if you need to test if
     * a user can access the file at all.
     *
     * @param array $userRoles
     *
     * @return boolean
     */
    public function hasRequiredRoles(array $userRoles): bool
    {
        $requiredRoles = $this->configuration->getRequiredRoles();
        $requiredRolesCount = count($requiredRoles);
        if (!$requiredRolesCount) {
            return true;
        }
        return count(array_diff($requiredRoles, $userRoles)) !== $requiredRolesCount;
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
            $publicVersion->url = $this->getPublicPath($name);
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
     *
     * @throws
     */
    public function guessExtension($version = null)
    {
        if (!$version) {
            $version = $this->getDefaultVersionName();
        }
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
     *
     * @throws
     */
    public function getMimeType($version = null)
    {
        if (!$version) {
            $version = $this->getDefaultVersionName();
        }
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
     *
     * @throws
     */
    public function getFilename($version = null)
    {
        if (!$version) {
            $version = $this->getDefaultVersionName();
        }
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
     *
     * @throws
     */
    public function getExtension($version = null)
    {
        if (!$version) {
            $version = $this->getDefaultVersionName();
        }
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
     *
     * @throws
     */
    public function getSize($version = null)
    {
        if (!$version) {
            $version = $this->getDefaultVersionName();
        }
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
     *
     * @throws
     */
    public function getATime($version = null)
    {
        if (!$version) {
            $version = $this->getDefaultVersionName();
        }
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
     *
     * @throws
     */
    public function getMTime($version = null)
    {
        if (!$version) {
            $version = $this->getDefaultVersionName();
        }
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
     *
     * @throws
     */
    public function getCTime($version = null)
    {
        if (!$version) {
            $version = $this->getDefaultVersionName();
        }
        return $this->getVersion($version)->getCTime();
    }

    /**
     * Get the name of the default version.
     *
     * @return string
     *
     * @throws
     */
    private function getDefaultVersionName(): string
    {
        if (!count($this->versions)) {
            throw new RuntimeException('This file has no version.');
        }
        if (array_key_exists('default', $this->versions)) {
            return 'default';
        }
        reset($this->versions);
        return key($this->versions);
    }
}
