<?php
namespace Webeak\Bundle\FileBundle;

/**
 * Simple structure holding public data for a file.
 */
class PublicFile
{
    /**
     * Unique identifier of the file.
     * Used to make any operation on the file (remove it, changing rights, add a version, etc.).
     */
    public ?string $identifier;

    /**
     * Original name of the first version of the file.
     */
    public ?string $name;

    /**
     * Type of the first version of the file.
     */
    public ?string $type;

    /**
     * Versions of the file.
     *
     * @var PublicFileVersion[]
     */
    public array $versions;

    /**
     * Public additional data associated with the file.
     * Can be anything.
     */
    public mixed $extra;

    public function __construct()
    {
        $this->name = null;
        $this->type = null;
        $this->identifier = null;
        $this->versions = [];
        $this->extra = [];
    }

    /**
     * Alias of 'exportGenericRepresentation'.
     *
     * @return array
     */
    public function asArray(): array
    {
        return $this->exportGenericRepresentation();
    }

    /**
     * Returns the array representation of the object.
     *
     * @return array
     */
    public function exportGenericRepresentation(): array
    {
        $versions = [];
        foreach ($this->versions as $name => $version) {
            $versions[$name] = $version->exportGenericRepresentation();
        }
        return [
            'name' => $this->name,
            'type' => $this->type,
            'identifier' => $this->identifier,
            'versions' => $versions,
            'extra' => $this->extra
        ];
    }

    /**
     * Import an array representation of a PublicFile into the current instance.
     *
     * @param array $data
     */
    public function importGenericRepresentation(array $data): void
    {
        $versions = [];
        if (array_key_exists('versions', $data)) {
            foreach ($data['versions'] as $name => $vdata) {
                $versions[$name] = PublicFileVersion::createFromGenericRepresentation($vdata);
            }
        }
        $this->name = $data['name'];
        $this->type = $data['type'];
        $this->identifier = $data['identifier'];
        $this->versions = $versions;
        $this->extra = array_key_exists('extra', $data) ? ((array)$data['extra']) : [];
    }

    /**
     * Create a PublicFile instance and import the input data into it.
     *
     * @param array $data
     *
     * @return PublicFile
     */
    public static function createFromGenericRepresentation(array $data): PublicFile
    {
        $instance = new self;
        $instance->importGenericRepresentation($data);
        return $instance;
    }
}
