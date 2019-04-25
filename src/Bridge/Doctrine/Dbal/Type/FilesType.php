<?php
namespace Webeak\Bundle\FileBundle\Bridge\Doctrine\Dbal\Type;

use Webeak\Bundle\FileBundle\PublicFile;
use Webeak\Bundle\FileBundle\PublicFileCollection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Platforms\AbstractPlatform;

/**
 * Doctrine type representing a PublicFile instance in the database.
 */
class FilesType extends Type
{
    const TYPE_NAME = 'files';

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
        if (!($value instanceof PublicFileCollection)) {
            if (!is_array($value)) {
                return null;
            }
            $value = PublicFileCollection::createFromArray($value);
        }
        for ($i = 0, $ii = count($value); $i < $ii; ++$i) {
            if (is_array($value[$i])) {
                $value[$i] = PublicFile::createFromGenericRepresentation($value[$i]);
            }
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
