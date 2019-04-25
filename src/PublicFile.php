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
     *
     * @var string
     */
    public $identifier;

    /**
     * Original name of the first version of the file.
     *
     * @var string
     */
    public $name;

    /**
     * Type of the first version of the file.
     *
     * @var string
     */
    public $type;

    /**
     * Versions of the file.
     *
     * @var PublicFileVersion[]
     */
    public $versions;

    /**
     * Public additional data associated with the file.
     * Can be anything.
     *
     * @var mixed
     */
    public $extra;

    public function __construct()
    {
        $this->name = null;
        $this->type = null;
        $this->identifier = null;
        $this->versions = [];
        $this->extra = [];
    }

    public function exportGenericRepresentation()
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

    public function importGenericRepresentation(array $data)
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

    public static function createFromGenericRepresentation(array $data)
    {
        $instance = new self;
        $instance->importGenericRepresentation($data);
        return $instance;
    }
}
