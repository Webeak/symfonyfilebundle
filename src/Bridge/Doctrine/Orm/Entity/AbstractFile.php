<?php
namespace Webeak\Bundle\FileBundle\Bridge\Doctrine\Orm\Entity;

use Doctrine\ORM\Mapping as ORM;
use Webeak\Bundle\EssentialBundle\Entity\AbstractEntity;

abstract class AbstractFile extends AbstractEntity implements FileEntityInterface
{
    /**
     * Original name of the first version of the file.
     */
    #[ORM\Column(type: "string")]
    protected string $name;

    /**
     * Mime type of the first version of the file.
     */
    #[ORM\Column(type: "string", length: 255, nullable: true)]
    protected ?string $mimeType;

    /**
     * Versions names.
     */
    #[ORM\Column(type: "json")]
    protected array $versions;

    /**
     * Hold the whole configuration as a JSON object.
     */
    #[ORM\Column(type: "json")]
    protected array $configuration;

    /**
     * Extra custom data dedicated to the app logic.
     */
    #[ORM\Column(type: "json")]
    protected array $extra;

    /**
     * Extra custom data publicly visible.
     */
    #[ORM\Column(type: "json")]
    protected array $publicExtra;

    /**
     * Expiration date of the file.
     */
    #[ORM\Column(type: "datetime", nullable: true)]
    protected ?\DateTimeInterface $expirationDate;

    /**
     * Number of entities using the file.
     */
    #[ORM\Column(type: "smallint")]
    protected int $usageCount;

    /**
     * Holds the md5 hash of the original file used to create this file.
     */
    #[ORM\Column(type: "string", length: 32, nullable: false)]
    protected string $sourceFileHash;

    /**
     * Holds the hash of the file. Used to test for duplicates.
     * The hash must have used the following values : name, configuration, extra, publicExtra, sourceFileHash.
     */
    #[ORM\Column(type: "string", length: 32, nullable: false)]
    protected string $hash;

    public function __construct()
    {
        parent::__construct();
        $this->mimeType = null;
        $this->expirationDate = null;
        $this->versions = [];
        $this->configuration = [];
        $this->extra = [];
        $this->publicExtra = [];
        $this->usageCount = 0;
        $this->sourceFileHash = '';
        $this->hash = '';
    }

    /**
     * Set the unique identifier of the file.
     */
    public function setIdentifier(string $identifier): static
    {
        $this->setRef($identifier);
        return $this;
    }

    /**
     * Get the unique identifier of the file.
     */
    public function getIdentifier(): string
    {
        return $this->getRef();
    }

    /**
     * Set name
     */
    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Get name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set mimeType
     */
    public function setMimeType(string $mimeType): static
    {
        $this->mimeType = $mimeType;
        return $this;
    }

    /**
     * Get mimeType
     */
    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    /**
     * Add new versions
     */
    public function addVersions(array $versions): static
    {
        $this->versions = array_merge($this->versions, (array)$versions);
        return $this;
    }

    /**
     * Add a new version
     */
    public function addVersion(string $name, string $path): static
    {
        $this->versions[$name] = $path;
        return $this;
    }

    /**
     * Set the whole list of versions
     */
    public function setVersions(array $versions): static
    {
        $this->versions = $versions;
        return $this;
    }

    /**
     * Get versions
     */
    public function getVersions(): array
    {
        return $this->versions;
    }

    /**
     * Set configuration
     */
    public function setConfiguration(array $configuration): static
    {
        $this->configuration = $configuration;
        return $this;
    }

    /**
     * Get configuration
     */
    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    /**
     * Set extra
     */
    public function setExtra(array $extra): static
    {
        $this->extra = $extra;
        return $this;
    }

    /**
     * Get extra
     */
    public function getExtra(): array
    {
        return $this->extra;
    }

    /**
     * Set public extra
     */
    public function setPublicExtra(array $extra): static
    {
        $this->publicExtra = $extra;
        return $this;
    }

    /**
     * Get public extra
     */
    public function getPublicExtra(): array
    {
        return $this->publicExtra;
    }

    /**
     * Set the expiration date of the file
     */
    public function setExpirationDate(?\DateTimeInterface $date = null): static
    {
        $this->expirationDate = $date;
        return $this;
    }

    /**
     * Get the expiration date of the file
     */
    public function getExpirationDate(): ?\DateTimeInterface
    {
        return $this->expirationDate;
    }

    /**
     * Gets the total number of entities using this file.
     */
    public function getUsageCount(): int
    {
        return $this->usageCount;
    }

    /**
     * Sets the total number of entities using this file.
     */
    public function setUsageCount(int $count): static
    {
        $this->usageCount = $count;
        return $this;
    }

    /**
     * Sets the md5 hash of the source file.
     */
    public function setSourceFileHash(string $hash): FileEntityInterface
    {
        $this->sourceFileHash = $hash;
        return $this;
    }

    /**
     * Gets the md5 hash of the source file.
     */
    public function getSourceFileHash(): string
    {
        return $this->sourceFileHash;
    }

    /**
     * Sets the md5 hash of the AbstractFile.
     */
    public function setHash(string $hash): FileEntityInterface
    {
        $this->hash = $hash;
        return $this;
    }

    /**
     * Gets the md5 hash of the AbstractFile.
     */
    public function getHash(): string
    {
        return $this->hash;
    }
}
