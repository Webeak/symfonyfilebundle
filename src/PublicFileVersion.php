<?php
namespace Webeak\Bundle\FileBundle;

/**
 * Simple structure holding public data for a file version.
 */
class PublicFileVersion
{
    /**
     * URL to access the file by HTTP.
     *
     * @var string
     */
    public $url;

    /**
     * Real name of the file.
     *
     * @var string
     */
    public $name;

    /**
     * Size of the file (in bytes).
     *
     * @var integer
     */
    public $size;

    /**
     * Mime type of the file.
     *
     * @var string
     */
    public $type;

    public function exportGenericRepresentation()
    {
        return [
            'url' => $this->url,
            'name' => $this->name,
            'size' => $this->size,
            'type' => $this->type
        ];
    }

    public function importGenericRepresentation(array $data)
    {
        $this->url = $data['url'];
        $this->name = $data['name'];
        $this->size = $data['size'];
        $this->type = $data['type'];
    }

    public static function createFromGenericRepresentation(array $data)
    {
        $instance = new self;
        $instance->importGenericRepresentation($data);
        return $instance;
    }
}
