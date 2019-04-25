<?php
namespace Webeak\Bundle\FileBundle;

use Symfony\Component\HttpFoundation\File\File as SymfonyFile;

/**
 * Represent a file managed by the file manager.
 */
class File extends SymfonyFile
{
    /** @var string */
    protected $identifier;

    /** @var string */
    protected $versionName;

    /** @var string */
    protected $virtualName;

    /** @var boolean */
    protected $public;

    /** @var string[] */
    protected $errors;

    /** @var boolean */
    private $_shouldBeProcessed;

    public function __construct($path, $checkPath = false, File $file = null)
    {
        parent::__construct($path, $checkPath);
        $this->errors = [];
        $this->identifier = $file !== null ? $file->getIdentifier() : null;
        $this->public = $file !== null ? $file->isPublic() : false;
        $this->versionName = $file !== null ? $file->getVersionName() : null;
        $this->virtualName = $file !== null ? $file->getVirtualName() : null;
        $this->_shouldBeProcessed = false;
    }

    /**
     * Add an error message or an array of messages to the
     * current list of errors.
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
     * Get the whole list of error messages.
     *
     * @return string[]
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Test if errors have been registered.
     *
     * @return boolean
     */
    public function hasError()
    {
        return count($this->errors) > 0;
    }

    /**
     * Set the whole list of error messages.
     *
     * @param string|array $errors
     *
     * @return $this
     */
    public function setErrors($errors)
    {
        $this->errors = [];
        $this->addErrors($errors);
        return $this;
    }

    /**
     * Get the file's unique identifier.
     *
     * @return string
     */
    public function getIdentifier()
    {
        return $this->identifier;
    }

    /**
     * Set the file's unique identifier.
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
     * Get the file's version name.
     *
     * @return string
     */
    public function getVersionName()
    {
        return $this->versionName;
    }

    /**
     * Set the file's version name.
     *
     * @param string $name
     *
     * @return $this
     */
    public function setVersionName($name)
    {
        $this->versionName = $name;
        return $this;
    }

    /**
     * Get the file's virtual name.
     *
     * @return string
     */
    public function getVirtualName()
    {
        return $this->virtualName;
    }

    /**
     * Set the file's virtual name.
     *
     * @param string $name
     *
     * @return $this
     */
    public function setVirtualName($name)
    {
        $this->virtualName = $name;
        return $this;
    }

    /**
     * Try to get the extension of the file.
     *
     * @return string|null
     */
    public function getExtension()
    {
        if ($this->virtualName && ($pos = strrpos($this->virtualName, '.')) !== false) {
            return strtolower(substr($this->virtualName, $pos + 1));
        }
        return parent::getExtension();
    }

    /**
     * Get/Set if the file should be passed to processors.
     *
     * @param boolean|null $val
     *
     * @return boolean|$this
     */
    public function shouldBeProcessed($val = null)
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
     * A public file will have a direct access in HTTP.
     * A private file will only be accessible through a proxy action.
     *
     * @param boolean|null $val
     *
     * @return boolean|$this
     */
    public function isPublic($val = null)
    {
        if ($val !== null) {
            $this->public = !!$val;
            return $this;
        }
        return $this->public;
    }

    /**
     * Test if the file is an image.
     *
     * @return boolean
     */
    public function isImage()
    {
        return substr($this->getMimeType(), 0, 6) === 'image/';
    }
}
