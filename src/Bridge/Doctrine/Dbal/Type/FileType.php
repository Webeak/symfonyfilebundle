<?php
namespace Webeak\Bundle\FileBundle\Bridge\Doctrine\Dbal\Type;

use Webeak\Bundle\FileBundle\PublicFile;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Platforms\AbstractPlatform;

/**
 * Doctrine type representing a PublicFile instance in the database.
 */
class FileType extends Type
{
    const TYPE_NAME = 'file';

    /**
     * {@inheritdoc}
     */
    public function getSQLDeclaration(array $fieldDeclaration, AbstractPlatform $platform)
    {
        return $platform->getBlobTypeDeclarationSQL($fieldDeclaration);
    }

    /**
     * {@inheritdoc}
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform)
    {
        if (!$value) {
            return null;
        }
        if (is_array($value)) {
            $value = PublicFile::createFromGenericRepresentation($value);
        }
        return serialize($value);
    }

    /**
     * {@inheritdoc}
     */
    public function convertToPHPValue($value, AbstractPlatform $platform)
    {
        if (!$value) {
            return null;
        }
        return unserialize($value);
    }

    public function getName()
    {
        return self::TYPE_NAME;
    }
}
