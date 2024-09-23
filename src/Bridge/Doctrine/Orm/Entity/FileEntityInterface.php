<?php
namespace Webeak\Bundle\FileBundle\Bridge\Doctrine\Orm\Entity;

interface FileEntityInterface
{
    /**
     * Set the unique identifier of the file.
     */
    public function setIdentifier(string $identifier): FileEntityInterface;

    /**
     * Get the unique identifier of the file.
     */
    public function getIdentifier(): string;

    /**
     * Set name
     */
    public function setName(string $name): FileEntityInterface;

    /**
     * Get name
     */
    public function getName(): string;

    /**
     * Set mimeType
     */
    public function setMimeType(string $mimeType): FileEntityInterface;

    /**
     * Get mimeType
     */
    public function getMimeType(): string;

    /**
     * Add new versions
     */
    public function addVersions(array $versions): FileEntityInterface;

    /**
     * Add a new version
     */
    public function addVersion(string $name, string $path): FileEntityInterface;

    /**
     * Set the whole list of versions
     */
    public function setVersions(array $versions): FileEntityInterface;

    /**
     * Get versions
     */
    public function getVersions(): array;

    /**
     * Set configuration
     */
    public function setConfiguration(array $configuration): FileEntityInterface;

    /**
     * Get configuration
     */
    public function getConfiguration(): array;

    /**
     * Set extra
     */
    public function setExtra(array $extra): FileEntityInterface;

    /**
     * Get extra
     *
     * @return array
     */
    public function getExtra(): array;

    /**
     * Set public extra
     */
    public function setPublicExtra(array $extra): FileEntityInterface;

    /**
     * Get public extra
     *
     * @return array
     */
    public function getPublicExtra(): array;

    /**
     * Set the expiration date of the file
     */
    public function setExpirationDate(?\DateTimeInterface $date = null): FileEntityInterface;

    /**
     * Get the expiration date of the file
     */
    public function getExpirationDate(): ?\DateTimeInterface;

    /**
     * Gets the total number of entities using this file.
     */
    public function getUsageCount(): int;

    /**
     * Sets the total number of entities using this file.
     */
    public function setUsageCount(int $count): FileEntityInterface;

    /**
     * Sets the md5 hash of the source file.
     */
    public function setSourceFileHash(string $hash): FileEntityInterface;

    /**
     * Gets the md5 hash of the source file.
     */
    public function getSourceFileHash(): string;

    /**
     * Sets the md5 hash of the FileEntityInterface.
     */
    public function setHash(string $hash): FileEntityInterface;

    /**
     * Gets the md5 hash of the FileEntityInterface.
     *
     * @return string
     */
    public function getHash(): string;

    /**
     * Sets the type of file system used to store the file.
     */
    public function setFileSystemType(?string $type): FileEntityInterface;

    /**
     * Gets the type of file system used to store the file.
     */
    public function getFileSystemType(): ?string;
}
