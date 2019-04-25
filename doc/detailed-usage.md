# Detailed usage

The main facade object your dealing with most of the time is the `FileManager`. 
It is used to register, validate, fetch and remove files.


## Registering a file

To register a file, you have to call one of the register methods on the `FileManager`. They are three of them:

| Method                                                                  | Description                                                                                                                                                                                                                                                                                                                                                                                                        |
|-------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `register`($input, $configuration = null)                   | Register a file. Output an array of ManagedFile objects even if errors occurs. Errors are stored in the object.                                                                                                                                                                                                                                                                                                    |
| `registerByContent`($content, $name, $configuration = null) | Register a file using its content. You may want to define its content type and name too.                                                                                                                                                                                                                                                                                                                           |
| `registerTemporarily`($input, $configuration = null)        | Register a file for a limited period of time. The file must be confirmed before the expiration date is reached or the next call the the "wb:files:clear-expired" command will delete it. The lifetime of the file is determined by the configuration property "temp_file_lifetime" (default value is 2 hours). For more control, you can set the expiration date of the file in the configuration object yourself. |

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

## Constraints

When a file is registered into the `FileManager`, constraints are applied to check if the file should be accepted or not.

The Symfony's validator component is used for this, so you can use any of the constraint offered by the `symfony/validator` component.

To create a new constraint, simply create a class that inherits from `Symfony\Component\Validator\Constraint`.

You can then add it to your configuration:

```php
$configurationBuilder->addConstraint(new MyCustomConstraint())
```

## Processors

Processors allow you to do some processing on the file before it is saved on the filesystem.

The following processors are built-in:

### ResizeProcessor (alias: resize)

Only applicable for images. Allow you to define constraints on the image size. The processor will resize the images 
to match these constraints.

<u>**Available options:**</u>

| Attribute  | Type    | Description                                                                                                                                       |
|------------|---------|---------------------------------------------------------------------------------------------------------------------------------------------------|
| width      | integer | Target width to resize to.                                                                                                                        |
| height     | integer | Target height to resize to.                                                                                                                       |
| background | string  | Background color if the image is too small to fit the entire area after resize.                                                                   |
| mode       | string  | Defines the resizing "strategy". Can be: 'default', 'scale', 'stretch', 'crop', 'zoomCrop'. See the documentation of "gregwar/image" for details. |

### CreateVersion (alias: createVersion)

Make a copy of the input and create a new version with it.
This processor only make sens in a processor sequence (see below), where you make additional processing on the newly created version.

Otherwise you would simply make a copy of the file.

<u>**Available options:**</u>

| Attribute  | Type  | Description                                                                                                                     |
|------------|-------|---------------------------------------------------------------------------------------------------------------------------------|
| name       | string| Name of the version (must be unique for a given file).                                                                          |

### Processors sequences

Processors are executed like a pipeline. This means that the output of a processor is the input of the next one.
But if you need to make parallel processing, it becomes a problem.

That's where you create a `sequence`. A sequence is a pipeline, but sequences are executed in parallel so each sequence gets the original input
no matter what other sequences do.

Imagine for example that you want to create multiple versions of an image, of different size. You can do it like this:



## Adapters

When registering a file, the input can be of several types. The `FileManager` will have to 
normalize the input into a `File` instance. This is done by **adapters**.

Here is the list of built-in adapters:

| Name                | Description                                                                                                                 |
|---------------------|-----------------------------------------------------------------------------------------------------------------------------|
| LocalPathAdapter    | Convert a local path into a File instance.                                                                                  |
| HttpPathAdapter     | Take a remote http path, download the file and stores it into the local filesystem. Then creates a File instance out of it. |
| UploadedFileAdapter | Take a UploadedFile instance (created by Symfony when a file is uploaded) and create a File instance out of it.             |
| FileAdapter         | Placeholder adapter in case the input is already a File instance. It simply returns it with no change.                      |


### Create your own adapters

Each adapter must implement the `AdapterInterface` interface which defines the following methods:

| Method                 | Description                                               |
|------------------------|-----------------------------------------------------------|
| bool supports($input)  | Test if the adapter supports the input.                   |
| File normalize($input) | Normalize the input value into a (symfony) File instance. |


To create your own adapters, create a class that implement `AdapterInterface`.

Then register it as a service with the `wb.file.file_manager_adapter` tag, like so:

```yaml
# config/services.yaml

App\FileManagerAdapter\CustomAdapter:
    arguments:
        - ...
        - ...
    tags:
        - { name: wb.file.file_manager_adapter }
```

