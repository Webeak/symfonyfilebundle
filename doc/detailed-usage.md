# Detailed usage

The main facade object your dealing with most of the time is the `FileManager`. 
It is used to register, validate, fetch and remove files.

- [Register a file](#register-a-file)

## Register a file

To register a file, you have to call one of the register methods on the `FileManager`. They are three of them:

| Method                                                                  | Description                                                                                                                                                                                                                                                                                                                                                                                                        |
|-------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `register`($input, $configuration = null)                   | Register a file. Output an array of ManagedFile objects even if errors occurs. Errors are stored in the object.                                                                                                                                                                                                                                                                                                    |
| `registerByContent`($content, $name, $configuration = null) | Register a file using its content. You may want to define its content type and name too.                                                                                                                                                                                                                                                                                                                           |
| `registerTemporarily`($input, $configuration = null)        | Register a file for a limited period of time. The file must be confirmed before the expiration date is reached or the next call the the "wb:file:clear-expired" command will delete it. The lifetime of the file is determined by the configuration property "temp_files_lifetime" (default value is 2 hours). For more control, you can set the expiration date of the file in the configuration object yourself. |

Each of these methods takes an input (can be anything, it will have to be convertible to a `File` instance by one of the adapters), a configuration object and will output an array of `ManagedFile`.

A `ManagedFile` represents a file managed by the `FileManager`. It can contains multiple `File` instances (multiple versions, each having a unique name) as well
as additional metadata the manager needs to work properly.  
  
### Configuration builder

Each of the register methods take a `$configuration` argument. The configuration defines all the rules to apply when registering the file:

- What constraints to apply?
- What processors or sequence of processors to apply?
- What preset to apply?
- What is the expiration date of the file?
- What access rights are required to access the file?
- And so on..

To easily configure this, there is a service you can invoke: `wb.file.configuration_builder`.

You can use it like this:

```php
[...]
use Symfony\Component\Validator\Constraints\Image;
use Webeak\Bundle\FileBundle\ConfigurationBuilder;

class ExampleController
{
    public function index(ConfigurationBuilder $configurationBuilder)
    {
        $configuration = $configurationBuilder
            ->addConstraint(new Image())
            ->addProcessor('resize', ['width' => 500])
            ->expiresAt((new \DateTime())->add(new \DateInterval('PT1D')))
            ->getConfiguration();
        
        // Use the configuration
    }
}
```

It implements the fluent interface so you can chain method calls. When you're done simply call the `getConfiguration()` method
to get a `Configuration` object.

This object can then be passed to the register method of your choice.

### Presets

Defining the configuration in PHP can complexify the code uselessly and create code repetitions.

To counter this problem, you can define configuration presets in the `config/packages/wb_file.yaml` configuration file (automatically created if your installed the bundle using `flex`):

```yaml
# config/packages/wb_file.yaml

wb_file:
    configuration_presets:
        avatar:
            public: true
            constraints:
                image: { maxSize: 1M }
            processors:
                - resize: { width: 128, height: 128, mode: crop }
```

Each key in the `configuration_presets` is the name of a preset and the value is its configuration.

Here the list of available variables in presets:

| Variable            | Type    | Description                                                                                                                                                                                                                                                                                                                                                                                |
|---------------------|---------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| constraints         | object  | Set by a key => value pair where the key can be either the FQCN of a constraint, an alias or a slot name.The value contains the options.                                                                                                                                                                                                                                                   |
| processors          | object  | Set like constraints **BUT** can be wrapped into an array to define a `sequence`.Each first level array is a sequence that can contain any number of processors. Sequences are executed in parallel whereas processors inside a sequence are executed sequentially, the output of a processor being the input of the next(more details on this in the [processors](processors.md) section. |
| public              | boolean | Is the file public? If true the file will be directly accessible by http, no access rights can be added to it.                                                                                                                                                                                                                                                                             |
| requiredRoles       | array   | List of required roles required to access the file. Matching one of them is enough to have access.                                                                                                                                                                                                                                                                                         |
| whiteListExclusive  | array   | List of users' username that are the only ones able to access the file.                                                                                                                                                                                                                                                                                                                    |
| whiteListCumulative | array   | List of users' username that can access the file even if they don't have the required roles.                                                                                                                                                                                                                                                                                               |
| blackList           | array   | List of users' username that will don't have access to the file even if they pass other tests (whitelists and roles).                                                                                                                                                                                                                                                                      |
| extra               | object  | Any data you want to associate with the file **privately**. Data stored here will never be exposed publicly.                                                                                                                                                                                                                                                                               |
| publicExtra         | object  | Any data you want to associate with the file **publicly**. Data stored here will be part of `PublicFile`.                                                                                                                                                                                                                                                                                  |
