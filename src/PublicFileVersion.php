<?php
namespace Webeak\Bundle\FileBundle;

/**
 * Simple structure holding public data for a file version.
 */
class PublicFileVersion
{
    /**
     * URL to access the file by HTTP.
     */
    public string $url;

    /**
     * Real name of the file.
     */
    public string $name;

    /**
     * Size of the file (in bytes).
     */
    public int $size;

    /**
     * Mime type of the file.
     */
    public string $type;

    public function exportGenericRepresentation(): array
    {
        return [
            'url' => $this->url,
            'name' => $this->name,
            'size' => $this->size,
            'type' => $this->type
        ];
    }

    public function importGenericRepresentation(array $data): void
    {
        $this->url = $data['url'];
        $this->name = $data['name'];
        $this->size = $data['size'];
        $this->type = $data['type'];
    }

    public static function createFromGenericRepresentation(array $data): PublicFileVersion
    {
        $instance = new self;
        $instance->importGenericRepresentation($data);
        return $instance;
    }
}
