<?php
namespace Webeak\Bundle\FileBundle\Bridge\Doctrine\Orm\Entity;

use Webeak\Bundle\DoctrineExtensionsBundle\Entity\AbstractEntity;
use Doctrine\ORM\Mapping as ORM;

abstract class AbstractFile extends AbstractEntity implements FileEntityInterface
{
    /**
     * Original name of the first version of the file.
     *
     * @ORM\Column(type="json_array")
     */
    protected $name;

    /**
     * Mime type of the first version of the file.
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    protected $mimeType;

    /**
     * Versions names.
     *
     * @ORM\Column(type="json_array")
     */
    protected $versions;

    /**
     * Hold the whole configuration as a JSON object.
     *
     * @ORM\Column(type="json_array")
     */
    protected $configuration;

    /**
     * Extra custom data dedicated to the app logic.
     *
     * @ORM\Column(type="json_array")
     */
    protected $extra;

    /**
     * Extra custom data publicly visible.
     *
     * @ORM\Column(type="json_array")
     */
    protected $publicExtra;

    /**
     * Expiration date of the file.
     *
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected $expirationDate;

    /**
     * Number of entities using the file.
     *
     * @ORM\Column(type="smallint")
     */
    protected $usageCount;

    /**
     * Holds the md5 hash of the original file used to create this file.
     *
     * @ORM\Column(type="string", length=32, nullable=false)
     */
    protected $sourceFileHash;

    /**
     * Holds the hash of the file. Used to test for duplicates.
     * The hash must have used the following values : name, configuration, extra, publicExtra, sourceFileHash.
     *
     * @ORM\Column(type="string", length=32, nullable=false)
     */
    protected $hash;

    public function __construct()
    {
        parent::__construct();
        $this->name = null;
        $this->mimeType = null;
        $this->expirationDate = null;
        $this->versions = [];
        $this->configuration = [];
        $this->extra = [];
        $this->publicExtra = [];
        $this->usageCount = 0;
        $this->sourceFileHash = 'toto';
        $this->hash = 'toto';
    }

    /**
     * Set the unique identifier of the file.
     *
     * @param string $identifier
     *
     * @return $this
     */
    public function setIdentifier($identifier)
    {
        $this->setRef($identifier);
        return $this;
    }

    /**
     * Get the unique identifier of the file.
     *
     * @return string
     */
    public function getIdentifier()
    {
        return $this->getRef();
    }

    /**
     * Set name
     *
     * @param array $name
     *
     * @return AbstractFile
     */
    public function setName($name)
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Get name
     *
     * @return array
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set mimeType
     *
     * @param string $mimeType
     *
     * @return AbstractFile
     */
    public function setMimeType($mimeType)
    {
        $this->mimeType = $mimeType;
        return $this;
    }

    /**
     * Get mimeType
     *
     * @return string
     */
    public function getMimeType()
    {
        return $this->mimeType;
    }

    /**
     * Add new versions
     *
     * @param array $versions
     *
     * @return AbstractFile
     */
    public function addVersions(array $versions)
    {
        $this->versions = array_merge($this->versions, (array)$versions);
        return $this;
    }

    /**
     * Add a new version
     *
     * @param string $name
     * @param string $path
     *
     * @return AbstractFile
     */
    public function addVersion($name, $path)
    {
        $this->versions[$name] = $path;
        return $this;
    }

    /**
     * Set the whole list of versions
     *
     * @param array $versions
     *
     * @return AbstractFile
     */
    public function setVersions(array $versions)
    {
        $this->versions = $versions;
        return $this;
    }

    /**
     * Get versions
     *
     * @return array
     */
    public function getVersions()
    {
        return $this->versions;
    }

    /**
     * Set configuration
     *
     * @param array $configuration
     *
     * @return AbstractFile
     */
    public function setConfiguration(array $configuration)
    {
        $this->configuration = $configuration;
        return $this;
    }

    /**
     * Get configuration
     *
     * @return array
     */
    public function getConfiguration()
    {
        return $this->configuration;
    }

    /**
     * Set extra
     *
     * @param array $extra
     *
     * @return AbstractFile
     */
    public function setExtra(array $extra)
    {
        $this->extra = $extra;
        return $this;
    }

    /**
     * Get extra
     *
     * @return array
     */
    public function getExtra()
    {
        return $this->extra;
    }

    /**
     * Set public extra
     *
     * @param array $extra
     *
     * @return AbstractFile
     */
    public function setPublicExtra(array $extra)
    {
        $this->publicExtra = $extra;
        return $this;
    }

    /**
     * Get public extra
     *
     * @return array
     */
    public function getPublicExtra()
    {
        return $this->publicExtra;
    }

    /**
     * Set the expiration date of the file
     *
     * @param \DateTime $date
     *
     * @return AbstractFile
     */
    public function setExpirationDate(\DateTime $date = null)
    {
        $this->expirationDate = $date;
        return $this;
    }

    /**
     * Get the expiration date of the file
     *
     * @return array
     */
    public function getExpirationDate()
    {
        return $this->expirationDate;
    }

    /**
     * Gets the total number of entities using this file.
     *
     * @return integer
     */
    public function getUsageCount()
    {
        return $this->usageCount;
    }

    /**
     * Sets the total number of entities using this file.
     *
     * @param integer $count
     *
     * @return AbstractFile
     */
    public function setUsageCount($count)
    {
        $this->usageCount = $count;
        return $this;
    }

    /**
     * Sets the md5 hash of the source file.
     *
     * @param string $hash
     *
     * @return $this
     */
    public function setSourceFileHash($hash)
    {
        $this->sourceFileHash = $hash;
        return $this;
    }

    /**
     * Gets the md5 hash of the source file.
     *
     * @return string
     */
    public function getSourceFileHash()
    {
        return $this->sourceFileHash;
    }

    /**
     * Sets the md5 hash of the AbstractFile.
     *
     * @param string $hash
     *
     * @return $this
     */
    public function setHash($hash)
    {
        $this->hash = $hash;
        return $this;
    }

    /**
     * Gets the md5 hash of the AbstractFile.
     *
     * @return string
     */
    public function getHash()
    {
        return $this->hash;
    }
}
