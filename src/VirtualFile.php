<?php
namespace Webeak\Bundle\FileBundle;

use League\Flysystem\Filesystem;
use League\Flysystem\UnableToReadFile;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Mime\MimeTypes;
use Webeak\Bundle\EssentialBundle\Exception\UsageException;

/**
 * Represent a file managed by the file manager.
 */
class VirtualFile extends File
{
    public const DEFAULT_VERSION_NAME = 'default';

    /**
     * File's unique identifier.
     *
     * @var string|null
     */
    protected ?string $identifier = null;

    /**
     * The path of the file in the filesystem.
     *
     * Can be null if there is no identifier assigned.
     *
     * @var string|null
     */
    protected ?string $path = null;

    /**
     * File's version name.
     * The version is kind of an alias of the main file, but under the same identifier.
     *
     * For example, an image could have a "thumbnail" version, which is smaller.
     *
     * The default version is "default".
     */
    protected string $versionName = self::DEFAULT_VERSION_NAME;

    /**
     * File's virtual name.
     *
     * This is the name of the file as it is known by the user.
     *
     * For example, a file could be named "my-image.jpg".
     */
    protected ?string $virtualName = null;

    /**
     * Stores any error related to the file processing.
     */
    protected array $errors = [];

    /**
     * Indicates if the file should be passed to processors or not.
     */
    private bool $_shouldBeProcessed = false;

    /**
     * The filesystem currently in use to store the file.
     *
     * If the filesystem changes, the file is automatically moved from the previous to the new one.
     */
    private ?Filesystem $fileSystem = null;

    /**
     * Path to the temporary local file.
     */
    private string $tempFilePath;

    public function __construct()
    {
        $this->tempFilePath = tempnam(sys_get_temp_dir(), 'virtualfile_');
        parent::__construct($this->tempFilePath, false);
    }

    /**
     * Change the filesystem used to store the file.
     * If the filesystem changes, the file will be migrated from the current filesystem to the new one.
     *
     * @throws UsageException
     */
    public function setFileSystem(Filesystem $fileSystem): void
    {
        if ($this->fileSystem === $fileSystem) {
            return;
        }
        if ($this->path !== null && $this->fileSystem !== null) {
            $this->moveFileBetweenFileSystems($this->fileSystem, $fileSystem);
        }
        $this->fileSystem = $fileSystem;
    }

    /**
     * Remove the actual file from the filesystem.
     *
     * @throws
     */
    public function dispose(): void
    {
        $this->ensurePathSystemReady();
        $this->fileSystem->delete($this->path);
        $directoryPath = dirname($this->path);
        $this->deleteEmptyParents($directoryPath);
    }

    /**
     * Destructor to clean up the temporary file.
     */
    public function __destruct()
    {
        if ($this->tempFilePath && file_exists($this->tempFilePath)) {
            @unlink($this->tempFilePath);
        }
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function isReadable(): bool
    {
        $this->ensurePathSystemReady();
        return $this->fileSystem->fileExists($this->path);
    }

    /**
     * Set the content of the file.
     *
     * @throws
     */
    public function setContent(string $content): static
    {
        $this->ensurePathSystemReady();
        $this->fileSystem->write($this->path, $content);
        @file_put_contents($this->tempFilePath, $content);
        return $this;
    }

    /**
     * Write the content of the file from a stream.
     *
     * @throws
     */
    public function setContentStream($stream): static
    {
        $this->ensurePathSystemReady();
        $this->fileSystem->writeStream($this->path, $stream);
        return $this;
    }

    /**
     * Get the content of the file.
     *
     * @throws
     */
    public function getContent(): string
    {
        $this->ensurePathSystemReady();
        return $this->fileSystem->read($this->path);
    }

    /**
     * Add an error message or an array of messages to the
     * current list of errors.
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
     * Get the whole list of error messages.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Test if errors have been registered.
     */
    public function hasError(): bool
    {
        return count($this->errors) > 0;
    }

    /**
     * Set the whole list of error messages.
     */
    public function setErrors(array|string $errors): static
    {
        $this->errors = [];
        $this->addErrors($errors);
        return $this;
    }

    /**
     * Get the file's unique identifier.
     */
    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    /**
     * Set the file's unique identifier.
     */
    public function setIdentifier(string $identifier): static
    {
        $this->identifier = $identifier;
        $this->updatePath();
        return $this;
    }

    /**
     * Get the file's version name.
     */
    public function getVersionName(): ?string
    {
        return $this->versionName;
    }

    /**
     * Set the file's version name.
     */
    public function setVersionName(string $name): static
    {
        $this->versionName = $name;
        return $this;
    }

    /**
     * Get the file's virtual name.
     */
    public function getVirtualName(): ?string
    {
        return $this->virtualName;
    }

    /**
     * Set the file's virtual name.
     */
    public function setVirtualName(string $name): static
    {
        $this->virtualName = $name;
        return $this;
    }

    public function getHash(): string
    {
        try {
            return '#' . md5($this->getContent());
        } catch (\Throwable $e) {
            // Ignore
        }
        return md5($this->identifier.'#'.$this->path); // So no risk to accidentally match another hash.
    }
    
    /**
     * Try to get the extension of the file.
     */
    public function getExtension(): string
    {
        if ($this->virtualName && ($pos = strrpos($this->virtualName, '.')) !== false) {
            return strtolower(substr($this->virtualName, $pos + 1));
        }
        return '';
    }

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
        static $mimeTypes = null;

        if ($mimeTypes === null) {
            $mimeTypes = new MimeTypes();
        }
        $mimeType = $this->getMimeType();
        $extensions = $mimeTypes->getExtensions($mimeType);
        return $extensions[0] ?? null;
    }

    /**
     * Get/Set if the file should be passed to processors.
     */
    public function shouldBeProcessed(?bool $val = null): bool|static
    {
        if ($val !== null) {
            $this->_shouldBeProcessed = !!$val;
            return $this;
        }
        return $this->_shouldBeProcessed;
    }

    /**
     * Get/Set if the file is public or not.
     *
     * A public file will have direct access in HTTP.
     * A private file will only be accessible through a proxy action.
     */
    public function isPublic(?bool $val = null): bool|static
    {
        if ($val !== null) {
            $this->public = !!$val;
            return $this;
        }
        return $this->public;
    }

    public function getSize(): int
    {
        $this->ensurePathSystemReady();
        return $this->fileSystem->fileSize($this->path);
    }

    public function getMimeType(): string|null
    {
        $this->ensurePathSystemReady();
        $mimeType = $this->fileSystem->mimeType($this->path);
        return $mimeType ?? 'application/octet-stream';
    }

    public function isImage(): bool
    {
        return str_starts_with($this->getMimeType(), 'image/');
    }

    /**
     * Moves the file from the current filesystem to the new one.
     *
     * @throws UsageException
     */
    private function moveFileBetweenFileSystems(Filesystem $currentFileSystem, Filesystem $newFileSystem): void
    {
        try {
            $fileStream = $currentFileSystem->readStream($this->path);
            if (!$fileStream) {
                throw new UnableToReadFile('Failed to read the file from the current filesystem.');
            }
            $newFileSystem->writeStream($this->path, $fileStream);
            fclose($fileStream);
            $currentFileSystem->delete($this->path);
            $directoryPath = dirname($this->path);
            $this->deleteEmptyParents($directoryPath);
        } catch (\Throwable $e) {
            throw new UsageException("Failed to migrate the file between filesystems: " . $e->getMessage());
        }
    }

    private function updatePath(): void
    {
        if (!$this->identifier) {
            $this->path = null;
            return ;
        }
        $path = '';
        $maxDepth = 3;
        $segmentLength = 2;

        for ($i = 0; $i < $maxDepth; $i++) {
            $start = $i * $segmentLength;
            if (strlen($this->identifier) >= $start + $segmentLength) {
                $path .= '/' . substr($this->identifier, $start, $segmentLength);
            } else {
                break; // Stop if the identifier is too short for another segment
            }
        }
        $this->path = '/' . trim($path, '/') . '/' . $this->identifier;
    }

    private function ensurePathSystemReady(): void
    {
        if (!$this->path) {
            throw new UsageException('The file path is not set.');
        }
        if (!$this->fileSystem) {
            throw new UsageException('The file system is not set.');
        }
    }

    private function deleteEmptyParents($path): void
    {
        if (empty($path) || $path === "/" || $path === "." || $path === "..") {
            return;
        }
        if ($this->isDirectoryEmpty($path)) {
            try {
                $this->fileSystem->deleteDirectory($path);
            } catch (\Throwable $e) {
                // Ignore
            }
            $this->deleteEmptyParents(dirname($path));
        }
    }

    private function isDirectoryEmpty($path): bool
    {
        $contents = $this->fileSystem->listContents($path, false);
        return !count($contents->toArray());
    }
}
