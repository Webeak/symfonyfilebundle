# File bundle

- [Installation](#installation)
- [Cron tasks](#cron-tasks)
- [Server configuration](#server-configuration)
- [Core concepts](core-concepts.md)
- [Detailed usage](detailed-usage.md)
- [Adapters](adapters.md)
- [Constraints](constraints.md)
- [Processors](processors.md)
- [Presets](presets.md)
- [Access rights](access-rights.md)
- [Doctrine](doctrine.md)

## Installation

First ensure you have added the endpoint `https://symfony-recipes.webeak.fr` to your `composer.json` file, 
so `flex` can find the recipe :

```json
{
    [...]
    "extra": {
        "symfony": {
            "endpoint": "https://symfony-recipes.webeak.fr"
        }
    }
}
```

You can then install the bundle:

```bash
composer require webeak-file
```

## Commands

Certain commands have to be executed regularly to keep everything clean. 
Never executing these commands is not recommended if your project uses the bundle a lot as there will be a lot of trash accumulating.

 
| Command                | Description                                                                            |
|------------------------|----------------------------------------------------------------------------------------|
| wb:file:clear-expired | Deletes the files (and their metadata) for which the expiration date has been reached. |
| wb:file:clear         | Remove temporary files that have never been confirmed after upload.                    |


### Cron tasks

The ideal is to create cron task to execute the commands automatically. Below a possible configuration:

<u>**Clears temporary files**</u>:
```
*/1 * * * * php /var/www/vhosts/clic-et-menu.fr/api/httpdocs/bin/console wb:file:clear
```

<u>**Clears expired files files**</u>:
```
*/10 * * * * php /var/www/vhosts/clic-et-menu.fr/api/httpdocs/bin/console wb:file:clear-expired
```

## Server configuration

To ensure the upload works properly even for large files, you can tweek the configurations as defined below.

### Nginx

In  /etc/nginx/nginx.conf
```
# The value “always” will cause nginx to unconditionally wait for and process additional client data.
lingering_close always;
 
# So nginx keeps the connection opened for an unlimited amount of time while there are still data sent in.
lingering_time 0;
 
# Set the maximum size of the request body.
client_max_body_size 1G;
```

### Php.ini

```
upload_max_filesize = 1G
post_max_size = 1G
max_input_time = 86400
memory_limit = 512M
```

