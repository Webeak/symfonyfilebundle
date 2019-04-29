# Doctrine

- [Introduction](#introduction)
- [Custom types](#custom-types)
- [File entity](#file-entity)
- [Auto sync](#auto-sync)

## Introduction

Doctrine is an optional dependency. By default the bundle does not require it.

If Doctrine is installed, several things happen:

- Custom entity types are added (`file` and `files`)
- A listener is listening to all your operations on the entity manager to handle changes that occurs on your `file` and `files` entity attributes.
- The `doctrine` storage is available. When enabled, metadata are stored in your database.

## Custom types

To make the storage of a file very easy, two types are automatically added to Doctrine when the bundle loads:

- `file`: represent a single file. The type expect a `PublicFile` instance to be passed (more on this later).
- `files`: represent a collection of `PublicFile` instances.

To use a type, simply set the type in your Doctrine annotations:

```php
/**
 * @ORM\Entity
 */
class Article
{
    [...]
    /**
     * @ORM\Column(type="file", nullable=true)
     */
    private $illustration;
    
    [...]
}
```

## File entity

When you register a file, metadata describing the file must be saved as well.
With the doctrine storage, they are saved in the database.

By default, metadata are stored in a `wb_file_file` defined in the `Webeak\Bundle\FileBundle\Bridge\Doctrine\Orm\Entity\File` entity.

This entity only contains the necessary info the bundle needs to work. 
It inherits from `AbstractFile` which implements the interface `FileEntityInterface` (both of the same namespace).

**It should be enough if 99.99% of the cases.**

If you need to store extra metadata, you have to fields at your disposal:

 - `extra`: you can put anything serializable to json in here. It will be kept private.
 - `publicExtra`: same as `extra` but the data stored in here will be copied in the `PublicFile` instance of the file.

## Auto sync

An entity listener is automatically added to watch for `file` and `files` types in all your entities.

When an entity is loaded or persisted in Doctrine, the listener will check if there is any change in these fields and will
take the appropriate measures to deal with them:

- In case of a single file, it will remove the previous file if it has changed or if the field have been set to null.
- Same with collections. The listener will search for missing files from the previous version and remove them from the filesystem automatically.

**Note**: A counter is maintained in the internal metadata to know how many entities use the same file. The file will only be removed when the counter reach 0.

