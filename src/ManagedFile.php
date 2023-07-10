<?php
namespace Webeak\Bundle\FileBundle;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Webeak\Bundle\EssentialBundle\Exception\UsageException;
use Webeak\Bundle\FileBundle\Exception\FileNotFoundException;
use Webeak\Bundle\FileBundle\FileSystem\FileSystemInterface;
use Webeak\Component\Utils\UtilPhp;

/**
 * Represent a file managed by the file manager.
 */
class ManagedFile
{

    /** @var FileSystemInterface */
    protected FileSystemInterface $filesystem;

    /** @var RouterInterface */
    protected RouterInterface $router;

    /**
     * Unique identifier of the file and all its versions.
     * This is the identifier the app will always refer to.
     */
    protected string $identifier;

    /**
     * Associative array of versionName => File instance.
     *
     * @var File[]
     */
    protected array $versions;

    /**
     * Array of removed versions.
     */
    protected array $removedVersions;

    /** @var Configuration */
    protected ?Configuration $configuration;

    /** @var string[] */
    protected array $errors;

    /** @var integer  */
    private int $usageCount;

    /** @var string */
    private string $publicRootDir;

    /** @var string */
    private string $httpRoot;

    /**
     * @throws
     */
    public function __construct(RequestStack $requestStack,
                                FileSystemInterface $filesystem,
                                RouterInterface $router,
                                string $projectRootDir)
    {
        $currentRequest = $requestStack->getCurrentRequest();
        $this->filesystem = $filesystem;
        $this->router = $router;
        $this->publicRootDir = $projectRootDir . '/public';
        $this->httpRoot = $currentRequest ? $currentRequest->getSchemeAndHttpHost() : '/';
        $this->versions = [];
        $this->removedVersions = [];
        $this->errors = [];
        $this->configuration = null;
        $this->usageCount = 0;
        if (!$this->publicRootDir) {
            throw new UsageException('Invalid web root dir.');
        }
    }

    /**
     * Add a new version of this file.
     */
    public function addVersion(File $version, string $name = 'default', bool $override = true): static
    {
        if (!array_key_exists($name, $this->versions) || $override) {
            $version->setVersionName($name);
            $this->versions[$name] = $version;
        }
        return $this;
    }

    /**
     * Test if a version of the file exist.
     */
    public function hasVersion(string $version): bool
    {
        return array_key_exists($version, $this->versions);
    }

    /**
     * Get the File instance of a version.
     *
     * @throws
     */
    public function getVersion(string $name): File
    {
        if (array_key_exists($name, $this->versions)) {
            return $this->versions[$name];
        }
        throw new FileNotFoundException(sprintf(
            'No "%s" version has been found for the file "%s".',
            $name, $this->getIdentifier()
        ));
    }

    /**
     * Remove a version of a file.
     */
    public function removeVersion(string $name): void
    {
        if (array_key_exists($name, $this->versions) && !in_array($name, $this->removedVersions)) {
            $this->removedVersions[$name] = $this->versions[$name];
            unset($this->versions[$name]);
        }
    }

    /**
     * Get the array of File waiting to be removed.
     */
    public function getRemovedVersions(): array
    {
        return $this->removedVersions;
    }

    /**
     * Test if the file has expired.
     */
    public function hasExpired(): bool
    {
        $expirationDate = $this->configuration->getExpirationDate();
        if ($expirationDate instanceof \DateTime) {
            try {
                return new \DateTime() >= $expirationDate;
            } catch (\Throwable $e) {
                // Makes no sense that new \DateTime() throw an exception in this case but the try/catch is
                // here to remove ide warning because there is no @throws annotation on the comments.
                return true;
            }
        }
        return false;
    }

    /**
     * Add an error message or an array of messages to the global errors array.
     */
    public function addErrors(array|string $errors): static
    {
        if (!is_array($errors)) {
            $errors = [$errors];
        }
        $this->errors = array_merge($this->errors, $errors);
        return $this;
    }

    /**
     * Test if any version of the files have an error.
     */
    public function hasError(): bool
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
     */
    public function getErrors(): array
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
     */
    public function getFlattenedErrors(): array
    {
        $output = $this->errors;
        foreach ($this->versions as $name => $version) {
            $output = array_merge($output, $version->getErrors());
        }
        return $output;
    }

    /**
     * Get the whole list of versions for this file.
     */
    public function getVersions(): array
    {
        return $this->versions;
    }

    /**
     * Get the list of versions names.
     */
    public function getVersionsNames(): array
    {
        return array_keys($this->versions);
    }

    /**
     * Set a version file.
     */
    public function setVersion(string $name, File $version): static
    {
        $this->versions[$name] = $version;
        return $this;
    }

    /**
     * Get the content of the file.
     *
     * @throws
     */
    public function getContent(?string $version = null): mixed
    {
        if (!$version) {
            $version = $this->getDefaultVersionName();
        }
        return $this->filesystem->read($this->getVersion($version));
    }

    /**
     * Get the content of a file.
     *
     * @throws
     */
    public function setContent(mixed $content, ?string $version = null): void
    {
        if (!$version) {
            $version = $this->getDefaultVersionName();
        }
        $this->filesystem->write($this->getVersion($version), $content);
    }

    /**
     * Get the unique identifier of the file.
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * Get the absolute path to the file in the filesystem.
     * You should avoid using this and let the file manager handle the file.
     *
     * NEVER use this to remove a file, as it will still exist in the file manager database.
     *
     * @throws
     */
    public function getLocalPath(?string $version = null): string
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
     * @throws
     */
    public function getPublicPath(?string $version = null): string
    {
        if (!$version) {
            $version = $this->getDefaultVersionName();
        }
        $file = $this->getVersion($version);
        if ($file->isPublic()) {
            $relativePath = str_replace('\\', '/', str_replace($this->publicRootDir, '', $file->getRealPath()));
            return rtrim($this->httpRoot, '/').'/'.trim($relativePath, '/');
        }
        $realFileName = $file->getVirtualName();
        $realFileExtension = '';
        if (($pos = strrpos($realFileName, '.')) !== false) {
            $realFileExtension = substr($realFileName, $pos);
            $realFileName = substr($realFileName, 0, $pos);
        }
        return $this->router->generate('wb_file_proxy', [
            'identifier' => $this->identifier,
            'version' => $version,
            'type' => $file->isImage() ? 'i' : 'g',
            'slug' => UtilPhp::slugify($realFileName) . $realFileExtension
        ]);
    }

    /**
     * Set the identifier.
     */
    public function setIdentifier(string $identifier): static
    {
        $this->identifier = $identifier;
        return $this;
    }

    /**
     * Set the usage count.
     */
    public function setUsageCount(int $count): static
    {
        $this->usageCount = max(0, intval($count));
        return $this;
    }

    /**
     * Get the usage count.
     */
    public function getUsageCount(): int
    {
        return $this->usageCount;
    }

    /**
     * Increment the usage count.
     */
    public function incrementUsageCount(int $count = 1): static
    {
        $this->usageCount += max(0, intval($count));
        return $this;
    }

    /**
     * Decrement the usage count.
     */
    public function decrementUsageCount(int $count = 1): static
    {
        $this->usageCount -= max(0, intval($count));
        return $this;
    }

    /**
     * Test if the managed file contains valid versions pointing on existing files.
     * If any version is invalid, the whole file is considered invalid.
     */
    public function isValid(): bool
    {
        if (!count($this->versions)) {
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
     */
    public function setExtra(array $extra): static
    {
        $this->configuration->setExtra($extra);
        return $this;
    }

    /**
     * Get extra data associated with the file.
     */
    public function getExtra(): array
    {
        return $this->configuration->getExtra();
    }

    /**
     * Set public extra data to attach to the file.
     * These extra WILL BE available in the PublicFile object.
     */
    public function setPublicExtra(array $extra): static
    {
        $this->configuration->setPublicExtra($extra);
        return $this;
    }

    /**
     * Get extra data associated with the file.
     * These extra WILL BE available in the PublicFile object.
     */
    public function getPublicExtra(): array
    {
        return $this->configuration->getPublicExtra();
    }

    /**
     * Gets the expiration date of the file.
     */
    public function getExpirationDate(): ?\DateTimeInterface
    {
        return $this->configuration->getExpirationDate();
    }

    /**
     * Set the configuration associated with the file.
     */
    public function setConfiguration(Configuration $configuration): static
    {
        $this->configuration = $configuration;
        return $this;
    }

    /**
     * Get the configuration associated with the file.
     */
    public function getConfiguration(): ?Configuration
    {
        return $this->configuration;
    }

    /**
     * Test if the file has any kind of limitation over its access.
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
     */
    public function hasAccess(UserInterface|string $user): bool
    {
        $username = $user instanceof UserInterface ? $user->getUserIdentifier() : ((string)$user);
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
     * @throws
     */
    public function getPublicFile(): ?PublicFile
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
     * @throws
     */
    public function guessExtension(?string $version = null): ?string
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
     * @throws
     */
    public function getMimeType(?string $version = null): ?string
    {
        if (!$version) {
            $version = $this->getDefaultVersionName();
        }
        return $this->getVersion($version)->getMimeType();
    }

    /**
     * Get a md5 of the md5 of each file's versions.
     */
    public function getSourceFilesHash(): string
    {
        $hashes = '';
        $keys = array_keys($this->versions);
        sort($keys);
        for ($i = 0, $ii = count($keys); $i < $ii; ++$i) {
            $version = $this->versions[$keys[$i]];
            if ($version instanceof File) {
                $hashes .= $version->getHash();
            } else {
                $hashes .= md5(((string)microtime()).rand(0, 10000));
            }
        }
        return md5($hashes);
    }

    /**
     * Gets a unique md5 hash corresponding to the current state of the managed file.
     * The hash includes :
     *   - hashes from the content of the source files
     *   - the current configuration
     */
    public function getHash(): string
    {
        $exported = $this->configuration->exportGenericRepresentation();
        // The expiration date will change for every file so ignore it.
        // It will be checked separately when the hash is used.
        $exported['expirationDate'] = null;
        return md5($this->getSourceFilesHash().'#'.serialize($exported));
    }

    /**
     * \SplFileInfo proxies.
     */

    /**
     * Gets the filename
     *
     * @throws
     *
     * @since 5.1.2
     *
     * @link http://php.net/manual/en/splfileinfo.getfilename.php
     */
    public function getFilename(?string $version = null): string
    {
        if (!$version) {
            $version = $this->getDefaultVersionName();
        }
        return $this->getVersion($version)->getVirtualName();
    }

    /**
     * Gets the file extension
     *
     * @return string a string containing the file extension, or an empty string if the file has no extension.
     *
     * @throws
     *
     * @since 5.3.6
     *
     * @link http://php.net/manual/en/splfileinfo.getextension.php
     */
    public function getExtension(?string $version = null): string
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
     * @return int The filesize in bytes.
     *
     * @throws
     *
     * @since 5.1.2
     *
     * @link http://php.net/manual/en/splfileinfo.getsize.php
     */
    public function getSize(?string $version = null): int
    {
        if (!$version) {
            $version = $this->getDefaultVersionName();
        }
        return $this->getVersion($version)->getSize();
    }

    /**
     * Gets last access time of the file
     *
     * @return int the time the file was last accessed.
     *
     * @throws
     *
     * @since 5.1.2
     *
     * @link http://php.net/manual/en/splfileinfo.getatime.php
     */
    public function getATime(?string $version = null): int
    {
        if (!$version) {
            $version = $this->getDefaultVersionName();
        }
        return $this->getVersion($version)->getATime();
    }

    /**
     * Gets the last modified time
     *
     * @return int the last modified time for the file, in a Unix timestamp.
     *
     * @throws
     *
     * @since 5.1.2
     *
     * @link http://php.net/manual/en/splfileinfo.getmtime.php
     */
    public function getMTime(?string $version = null): int
    {
        if (!$version) {
            $version = $this->getDefaultVersionName();
        }
        return $this->getVersion($version)->getMTime();
    }

    /**
     * Gets the inode change time
     *
     * @return int The last change time, in a Unix timestamp.
     *
     * @throws
     *
     * @since 5.1.2
     *
     * @link http://php.net/manual/en/splfileinfo.getctime.php
     */
    public function getCTime(string $version = null): int
    {
        if (!$version) {
            $version = $this->getDefaultVersionName();
        }
        return $this->getVersion($version)->getCTime();
    }

    /**
     * Get the name of the default version.
     *
     * @throws
     */
    private function getDefaultVersionName(): string
    {
        if (!count($this->versions)) {
            throw new UsageException('This file has no version.');
        }
        if (array_key_exists('default', $this->versions)) {
            return 'default';
        }
        reset($this->versions);
        return key($this->versions);
    }
}
