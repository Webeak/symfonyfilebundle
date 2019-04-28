# Constraints

When a file is registered into the `FileManager`, constraints are applied to check if the file should be accepted or not.

The Symfony's validator component is used for this, so you can use any of the constraint offered by the `symfony/validator` component.

To create a new constraint, simply create a class that inherits from `Symfony\Component\Validator\Constraint`.

You can then add it to your configuration:

```php
$configurationBuilder->addConstraint(new MyCustomConstraint())
```

## Defining constraints aliases

To apply constraints in the configuration you can use the FQCN of the class:

```yaml
wb_file:
    configuration_presets:
        avatar:
            public: true
            constraints:
                Symfony\Component\Validator\Constraints\Image: 
                    maxSize: 1M
            
```

but it's kind of verbose and becomes quickly hard to read when your configuration grows.

So you can define aliases to shorten the constraint name:

```yaml
# config/packages/wb_file.yaml

wb_file:
    constraints_aliases:
        image: Symfony\Component\Validator\Constraints\Image
        custom: App\Validation\Constraints\CustomConstraint
        other: App\Validation\Constraints\OtherConstraint
```

Then you can replace the FQCN by your alias:
```yaml
# config/packages/wb_file.yaml

wb_files:
    configuration_presets:
        avatar:
            public: true
            constraints:
                image: { maxSize: 1M }
```

Built-in aliases are:
```php
[
    'file' => 'Symfony\Component\Validator\Constraints\File',
    'image' => 'Symfony\Component\Validator\Constraints\Image',
    'pdf' => 'Webeak\Bundle\FileBundle\Constraint\PdfConstraint'
]
```


You can also define slots to group multiple constraints and options into a single alias:

```yaml
# config/packages/wb_file.yaml

wb_file:
    slots:
        mySlotName:
            constraints:
                custom: { option1: value, option2: value2 }
                other: { foo: bar }
```
