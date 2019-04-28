# Adapters

When registering a file, the input can be of several types. The `FileManager` will have to 
normalize the input into a `File` instance. This is done by **adapters**.

Here is the list of built-in adapters:

| Name                | Description                                                                                                                 |
|---------------------|-----------------------------------------------------------------------------------------------------------------------------|
| LocalPathAdapter    | Convert a local path into a File instance.                                                                                  |
| HttpPathAdapter     | Take a remote http path, download the file and stores it into the local filesystem. Then creates a File instance out of it. |
| UploadedFileAdapter | Take a UploadedFile instance (created by Symfony when a file is uploaded) and create a File instance out of it.             |
| FileAdapter         | Placeholder adapter in case the input is already a File instance. It simply returns it with no change.                      |


## Create your own adapters

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

