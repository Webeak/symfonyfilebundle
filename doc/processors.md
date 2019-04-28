# Processors

Processors allow you to do some processing on the file before it is saved on the filesystem.

The following processors are built-in:

## ResizeProcessor (alias: resize)

Only applicable for images. Allow you to define constraints on the image size. The processor will resize the images 
to match these constraints.

<u>**Available options:**</u>

| Attribute  | Type    | Description                                                                                                                                       |
|------------|---------|---------------------------------------------------------------------------------------------------------------------------------------------------|
| width      | integer | Target width to resize to.                                                                                                                        |
| height     | integer | Target height to resize to.                                                                                                                       |
| background | string  | Background color if the image is too small to fit the entire area after resize.                                                                   |
| mode       | string  | Defines the resizing "strategy". Can be: 'default', 'scale', 'stretch', 'crop', 'zoomCrop'. See the documentation of "gregwar/image" for details. |

## CreateVersion (alias: createVersion)

Make a copy of the input and create a new version with it.
This processor only make sens in a processor sequence (see below), where you make additional processing on the newly created version.

Otherwise you would simply make a copy of the file.

<u>**Available options:**</u>

| Attribute  | Type  | Description                                                                                                                     |
|------------|-------|---------------------------------------------------------------------------------------------------------------------------------|
| name       | string| Name of the version (must be unique for a given file).                                                                          |

## Processors sequences

Processors are executed like a pipeline. This means that the output of a processor is the input of the next one.
But if you need to make parallel processing, it becomes a problem.

That's where you create a `sequence`. A sequence is a pipeline, but sequences are executed in parallel so each sequence gets the original input
no matter what other sequences do.

Imagine for example that you want to create multiple versions of an image, of different size. You can do it like this:

```php
<?php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints\Image;
use Webeak\Bundle\FileBundle\ConfigurationBuilder;
use Webeak\Bundle\FileBundle\FileManager;
use Webeak\Bundle\FileBundle\Processor\CreateVersionProcessor;
use Webeak\Bundle\FileBundle\Processor\ResizeProcessor;

class ExampleController extends AbstractController
{
    public function localImage(FileManager $fileManager, ConfigurationBuilder $configurationBuilder)
    {
        $configuration = $configurationBuilder
            ->addConstraint(new Image())
            ->startProcessorsSequence()
                ->addProcessor(CreateVersionProcessor::class, ['name' => 'md'])
                ->addProcessor(ResizeProcessor::class, ['width' => 512, 'height' => 512])
            ->endProcessorsSequence()
            ->startProcessorsSequence()
                ->addProcessor(CreateVersionProcessor::class, ['name' => 'sm'])
                ->addProcessor(ResizeProcessor::class, ['width' => 256, 'height' => 256])
            ->endProcessorsSequence()
            ->startProcessorsSequence()
                ->addProcessor(CreateVersionProcessor::class, ['name' => 'xs'])
                ->addProcessor(ResizeProcessor::class, ['width' => 96, 'height' => 96])
            ->endProcessorsSequence()
            ->getConfiguration();

        // Absolute path to an example image
        $path = $this->getParameter('kernel.project_dir') . '/assets/images/tech.jpg';
        
        // Register the file with the configuration generated above.
        $managedFiles = $fileManager->register($path, $configuration);
        
        // Register returns an array of ManagedFile instances.
        // Take the first one and get the public path (HTTP path) to the "xs" version of the file.
        return new Response($managedFiles[0]->getPublicPath('xs'));
    }
}
```

The same configuration can be achieved using presets defined in the `yaml` configuration. More on this on 
the [presets](#presets) section.

## Create your own processors

A processor must be a service implementing the `ProcessorInterface` interface which defines the following methods:

| Attribute                                        | Description                                                               |
|--------------------------------------------------|---------------------------------------------------------------------------|
| bool `supports`(File $file, ManagedFile $parent) | Test if the processor supports the input.                                 |
| File `process`(File $file, ManagedFile $parent)  | Do the processing.                                                        |
| void `setOptions`(array $options)                | Set an array of options.                                                  |
| array `getOptions`()                             | Get the full array of options.                                            |
| string `getServiceId`()                          | Get the id of the service in the container. The service must be `public`. |


To create your own processor, create a class that implement `ProcessorInterface`.

Then register it as a service with the `wb.file.file_manager_processor` tag, like so:

```yaml
# config/services.yaml

services:
    App\FileManagerProcessor\CustomProcessor:
        arguments: 
            - ...
        tags:
            - { name: wb.file.file_manager_processor, alias: custom }
```

You can also inherit from `AbstractProcessor` to have a default implementation for `getOptions`, `setOptions` and `getServiceId`.
**Important note**: If you use the `getServiceId()` implementation of `AbstractProcessor`, your service id **MUST** be the FQCN of the class.
