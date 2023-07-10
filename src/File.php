<?php
namespace Webeak\Bundle\FileBundle;

use ReturnTypeWillChange;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;

/**
 * Represent a file managed by the file manager.
 */
class File extends SymfonyFile
{
    protected ?string $identifier;
    protected ?string $versionName;
    protected ?string $virtualName;
    protected bool $public;
    protected array $errors;
    private bool $_shouldBeProcessed;

    public function __construct($path, $checkPath = false, File $file = null)
    {
        parent::__construct($path, $checkPath);
        $this->errors = [];
        $this->identifier = $file?->getIdentifier();
        $this->public = $file !== null ? $file->isPublic() : false;
        $this->versionName = $file?->getVersionName();
        $this->virtualName = $file?->getVirtualName();
        $this->_shouldBeProcessed = false;
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
            $realPath = $this->getRealPath();
            if ($realPath) {
                return '#' . md5_file($realPath);
            }
        } catch (\Throwable $e) {
            // Ignore
        }
        return md5(microtime()); // So no risk to incorrectly match a hash.
    }
    
    /**
     * Try to get the extension of the file.
     */
    public function getExtension(): string
    {
        if ($this->virtualName && ($pos = strrpos($this->virtualName, '.')) !== false) {
            return strtolower(substr($this->virtualName, $pos + 1));
        }
        return parent::getExtension();
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

    /**
     * Test if the file is an image.
     */
    public function isImage(): bool
    {
        return str_starts_with($this->getMimeType(), 'image/');
    }
}
