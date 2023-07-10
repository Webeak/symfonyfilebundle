<?php
namespace Webeak\Bundle\FileBundle\Serializer;

use Webeak\Component\Utils\ArrayUtils;
use Webeak\Bundle\FileBundle\PublicFile;
use Webeak\Bundle\FileBundle\PublicFileCollection;

class PublicFileCollectionNormalizer extends PublicFileNormalizer
{
    /**
     * {@inheritdoc}
     */
    public function normalize($object, $format = null, array $context = []): array
    {
        /** @var PublicFileCollection $object */
        $output = [];
        for ($i = 0, $ii = count($object); $i < $ii; ++$i) {
            $output[] = $object[$i]->exportGenericRepresentation();
        }
        return $output;
    }

    /**
     * {@inheritdoc}
     */
    public function denormalize($data, $type, $format = null, array $context = []): PublicFileCollection
    {
        $output = new PublicFileCollection();
        $data = ArrayUtils::ensureArray($data);
        for ($i = 0, $ii = count($data); $i < $ii; ++$i) {
            if (($res = parent::denormalize($data[$i], PublicFile::class, $format, $context)) !== null) {
                $output[] = $res;
            }
        }
        return $output;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsDenormalization($data, $type, $format = null): bool
    {
        return $type === PublicFileCollection::class;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsNormalization($data, $format = null): bool
    {
        return self::FORMAT === $format && $data instanceof PublicFileCollection;
    }
}
