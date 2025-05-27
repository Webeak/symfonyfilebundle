<?php
namespace Webeak\Bundle\FileBundle\Bridge\Doctrine\Orm\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\GeneratedValue;

#[ORM\Entity]
#[ORM\Table(name: "wb_file_identifier")]
#[ORM\Index(columns: ["ref"], flags: ["fulltext"])]
class FileIdentifier
{
    #[ORM\Id]
    #[ORM\Column(type: "integer"), GeneratedValue(strategy: "AUTO")]
    protected ?int $id;

    #[ORM\Column(name: "ref", type: "string", length: 45, options: ['collation' => 'utf8mb4_bin'])]
    protected ?string $ref;

    #[ORM\Column(name: "storage_type", type: "string", length: 45)]
    protected ?string $storageType;

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;
        return $this;
    }

    public function getRef(): ?string
    {
        return $this->ref;
    }

    public function setRef($ref): static
    {
        $this->ref = $ref;
        return $this;
    }

    public function getStorageType(): ?string
    {
        return $this->storageType;
    }

    public function setStorageType($storageType): static
    {
        $this->storageType = $storageType;
        return $this;
    }
}
