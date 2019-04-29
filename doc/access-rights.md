# Access rights

Files are stored in two different places depending if there are protected or not.

A file marked as `public` will not have any protection associated with it and is stored in a directory directly accessible via an http request (for performance reasons).

But a protected file is stored outside of the public scope. So accessing it through http require to pass through a proxy route.

The proxy route is defined in the `FileManagerController` of the bundle. The route is named `wb_file_proxy` and its path is `/file/proxy/{identifier}/{version}/{type}`.

But you should **NEVER** have to call this route manually (more on this on the [Accessing files](#accessing-files) section.


## Accessing files

No matter if the file you want to access is public or protected, you should **ALWAYS** use the `FileManager` to get the necessary data.

The only case where you can bypass the `FileManager` is if you stored a `PublicFile` instance previously returned by the manager.
`PubliFile` contains all public data about a file and should be enough in most cases.

If you need to modify the file or access its local path, then you have to ask for a `ManagedFile` to the `FileManager`:

```php
<?php
namespace App\Controller;

use Webeak\Bundle\FileBundle\FileManager;
use Webeak\Bundle\FileBundle\ManagedFile;

class ExampleController
{
    public function getFile(FileManager $fileManager)
    {
        /** @var ManagedFile $managedFile */
        $managedFile = $fileManager->get('FILE_IDENTIFIER');
        // Do whatever you need with it
        // For example:
        // $localPath = $managedFile->getLocalPath();
    }
}
```

## Controlling the access

By default no access limits are added, anyone with the url can access the file (being either public or protected behind the proxy).

### User roles

You can control access to your file(s) on the roles of the logged user.
To do so, simply set the `requiredRoles` attribute to your configuration :

**Using the builder**:

```php
/** @var \Webeak\Bundle\FileBundle\ConfigurationBuilder $builder */
$builder->requiredRoles(['ROLE_USER', 'ROLE_ADMIN']);
```

**Using a preset**:

```yaml
wb_file:
    configuration_presets:
        bills:
            requiredRoles: [ROLE_USER, ROLE_ADMIN]
```

**Note:** A user only have to match **one** of the roles to access the file.

### Whitelist

You can also limit the access to a limited list of users.

There is two different whitelist for this:

 - **<u>*exclusive*</u> whitelist**: when set, the user HAVE TO be on this list, otherwise the access is defined, no matter other access controls.
 - **<u>*cumulative*</u> whitelist**: this list adds up with roles. To access the file the user have to be on the list **OR** have the required roles.

Usage:

**Using the builder**:

```php
/** @var \Webeak\Bundle\FileBundle\ConfigurationBuilder $builder */

// Exclusive
$builder->addUsersWhiteListExclusive(['user_1@domain.tld', 'user_2@domain.tld']);

// Cumulative
$builder->addUsersWhiteListCumulative(['user_1@domain.tld', 'user_2@domain.tld']);
```

**Using a preset**:

```yaml
wb_file:
    configuration_presets:
        bills:
            # Exclusive
            whiteListExclusive: ['user_1@domain.tld', 'user_2@domain.tld']
            
            # Cumulative
            whiteListCumulative: ['user_1@domain.tld', 'user_2@domain.tld']
```

<u>**NOTE**: Both whitelists look for the `username` of the user.</u>

### Blacklist

If you want to totally deny the access to specific users.

Users listed here will ***never*** be able to access the file, no matter their roles or other controls values.

<u>**NOTE**: Like whitelists, the blacklist look for the `username` of the user.</u>

