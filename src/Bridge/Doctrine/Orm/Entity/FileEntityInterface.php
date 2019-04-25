<?php
namespace Webeak\Bundle\FileBundle\Bridge\Doctrine\Orm\Entity;

interface FileEntityInterface
{
    /**
     * Set the unique identifier of the file.
     *
     * @param string $identifier
     *
     * @return FileEntityInterface
     */
    public function setIdentifier($identifier);

    /**
     * Get the unique identifier of the file.
     *
     * @return string
     */
    public function getIdentifier();

    /**
     * Set name
     *
     * @param string $name
     *
     * @return FileEntityInterface
     */
    public function setName($name);

    /**
     * Get name
     *
     * @return string
     */
    public function getName();

    /**
     * Set mimeType
     *
     * @param string $mimeType
     *
     * @return FileEntityInterface
     */
    public function setMimeType($mimeType);

    /**
     * Get mimeType
     *
     * @return string
     */
    public function getMimeType();

    /**
     * Add new versions
     *
     * @param array $versions
     *
     * @return FileEntityInterface
     */
    public function addVersions(array $versions);

    /**
     * Add a new version
     *
     * @param string $name
     * @param string $path
     *
     * @return FileEntityInterface
     */
    public function addVersion($name, $path);

    /**
     * Set the whole list of versions
     *
     * @param array $versions
     *
     * @return FileEntityInterface
     */
    public function setVersions(array $versions);

    /**
     * Get versions
     *
     * @return array
     */
    public function getVersions();

    /**
     * Set configuration
     *
     * @param array $configuration
     *
     * @return FileEntityInterface
     */
    public function setConfiguration(array $configuration);

    /**
     * Get configuration
     *
     * @return array
     */
    public function getConfiguration();

    /**
     * Set extra
     *
     * @param array $extra
     *
     * @return FileEntityInterface
     */
    public function setExtra(array $extra);

    /**
     * Get extra
     *
     * @return array
     */
    public function getExtra();

    /**
     * Set public extra
     *
     * @param array $extra
     *
     * @return FileEntityInterface
     */
    public function setPublicExtra(array $extra);

    /**
     * Get public extra
     *
     * @return array
     */
    public function getPublicExtra();

    /**
     * Set the expiration date of the file
     *
     * @param \DateTime $date
     *
     * @return FileEntityInterface
     */
    public function setExpirationDate(\DateTime $date = null);

    /**
     * Get the expiration date of the file
     *
     * @return \DateTime
     */
    public function getExpirationDate();

    /**
     * Gets the total number of entities using this file.
     *
     * @return integer
     */
    public function getUsageCount();

    /**
     * Sets the total number of entities using this file.
     *
     * @param integer $count
     *
     * @return AbstractFile
     */
    public function setUsageCount($count);

    /**
     * Sets the md5 hash of the source file.
     *
     * @param string $hash
     *
     * @return FileEntityInterface
     */
    public function setSourceFileHash($hash);

    /**
     * Gets the md5 hash of the source file.
     *
     * @return string
     */
    public function getSourceFileHash();

    /**
     * Sets the md5 hash of the FileEntityInterface.
     *
     * @param string $hash
     *
     * @return FileEntityInterface
     */
    public function setHash($hash);

    /**
     * Gets the md5 hash of the FileEntityInterface.
     *
     * @return string
     */
    public function getHash();
}

