<?php
namespace Webeak\Bundle\FileBundle\FileSystem;

class FileSystemConfigValidator
{
    // Rules for each filesystem type
    private static $rules = [
        'local' => [
            'required' => ['save_path'],
            'forbidden' => ['region', 'bucket', 'host', 'key', 'secret']
        ],
        'aws_s3' => [
            'required' => ['region', 'bucket'],
            'forbidden' => []
        ],
        'ftp' => [
            'required' => ['host'],
            'forbidden' => ['region', 'bucket']
        ],
        'sftp' => [
            'required' => ['host', 'save_path'],
            'forbidden' => ['region', 'bucket']
        ],
        'azure' => [
            'required' => ['connection_string', 'container'],
            'forbidden' => []
        ],
    ];

    /**
     * Validates the filesystem configuration based on its type.
     *
     * @param array $config The filesystem configuration array
     * @return bool Returns true if valid, false otherwise
     */
    public static function validate(array $config): bool
    {
        if (!isset(self::$rules[$config['type']])) {
            return false; // Unknown type
        }

        $rules = self::$rules[$config['type']];

        // Check required keys are present
        foreach ($rules['required'] as $key) {
            if (!isset($config[$key])) {
                return false;
            }
        }

        // Check forbidden keys are absent
        foreach ($rules['forbidden'] as $key) {
            if (isset($config[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns an error message based on the invalid filesystem config.
     *
     * @param array $config The invalid filesystem configuration array
     * @return string The error message
     */
    public static function getValidationErrorMessage(array $config): string
    {
        $type = $config['type'] ?? 'unknown';
        if (!isset(self::$rules[$type])) {
            return sprintf('Unknown filesystem type "%s".', $type);
        }

        $rules = self::$rules[$type];

        // Missing required keys
        foreach ($rules['required'] as $key) {
            if (!isset($config[$key])) {
                return sprintf('Filesystem type "%s" is missing required key "%s".', $type, $key);
            }
        }

        // Forbidden keys present
        foreach ($rules['forbidden'] as $key) {
            if (isset($config[$key])) {
                return sprintf('Filesystem type "%s" should not have the key "%s".', $type, $key);
            }
        }

        return 'Invalid filesystem configuration.';
    }
}
