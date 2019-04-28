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

```
