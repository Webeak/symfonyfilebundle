# Core concepts

- [How things are stored](#how-things-are-stored)
- [Basic usage](#basic-usage)
- [Advanced concepts](#advanced-concepts)

The main idea is that a file (from the application point of view) is only a string identifier.

The string identifier can be use to access all the data about a file (including its path) using a `FileManager`.

All data concerning a file are stored in an internal storage managed by the bundle.

The bundle is designed to support multiple types of storage but only `Doctrine` has been implemented for now (see the [Todo](#todo) section for more on this).

With doctrine, files's metadata are stored in the database, in a table managed by the bundle.

You are responsible of storing the file identifier so you can query it later, but the Doctrine part of the bundle handle
most of it automatically (see the [Doctrine](doctrine.md) section for more info).

## How things are stored

The files are stored in the filesystem, no matter the storage you choose. 
They can be stored at two different locations depending on their visibility:

- **private** files are stored in `var/storage/wb-files`. They can't be accessed directly in http, they are served by a **proxy** route (more on this later).
- **public** files are stored in `public/storage`. These files are in the `public` directory so you can access them directly.

The bundle is responsible of choosing in which directory put the files. It will only store files in the `public` directory when explicitly marked as **public**.

### Customize paths

You can change the paths where are stored the files like this:

```yaml
wb_file:
    # private files storage dir
    save_path: "%kernel.project_dir%/var/storage/wb-files"
    
    # public files storage dir
    public_save_path: "%kernel.project_dir%/public/storage"
```

Absolute paths are expected.

### Storing the metadata

Storing a file is good, but we need to store metadata describing what is this file (name, type, size, access rights, etc.).

These metadata are stored in whatever storage you define in the configuration. By default the `doctrine` storage is used, which means
metadata are stored in the database.

You can choose which storage you want in the configuration:

```yaml
wb_file:
    storage_type: doctrine
```

Only `doctrine` is supported for now (see [Todo](#todo)).

## Basic usage

To store a file, simply inject the `FileManager` and call the `register` method:

```php
<?php
namespace App\Controller;

use Webeak\Bundle\FileBundle\FileManager;

class ExampleController
{
    public function localImage(FileManager $fileManager)
    {
        $path = '/path/to/a/file.jpg';
        $managedFiles = $fileManager->register($path);
        $filePublicUrl = $managedFiles[0]->getPublicPath();
        
        // Do your stuff with it.
    }
}
```

You can of course give an `UploadedFile` instance to it to register a file uploaded by the current http request:

```php
<?php
namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Webeak\Bundle\FileBundle\FileManager;

class ExampleController
{
    public function localImage(Request $request, FileManager $fileManager)
    {
        $files = $request->files->all();
        
        // A ManagedFile instance will be returned for each uploaded file.
        $managedFiles = $fileManager->register($files);
        
        // Do your stuff with them.
    }
}
```

Of course with the above example you have no validation or processing of the files.
But they are stored in a secured way so they can't be accessed directly from the outside world and you only
have to save their identifiers to access them later.

You can access the identifier of a `ManagedFile` instance by doing:

```php
$managedFile->getIdentifier();
```
You can also store more information about the file so you don't have to call the 
`FileManager` each time you need the name of the file for example:

```php
// Returns a PublicFile instance containing non-sensitive information about
// the file (name, size, all versions with their public url, etc.).
$publicFile = $managedFile->getPublicFile();
```

You can then `serialize` this object into a database, or send it through an api to save it remotely.. you have a string so you're good to go.

You can also call `asArray()` on the `PublicFile` instance to get an associative array instead.


## Advanced concepts

More complex topics have their dedicated section:

- [Adapters](adapters.md)
- [Constraints](constraints.md)
- [Processors](processors.md)
- [Presets](presets.md)
- [Access rights](access-rights.md)
- [Doctrine](doctrine.md)
