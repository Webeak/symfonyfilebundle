<?php
namespace Webeak\Bundle\FileBundle;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\Validator\Constraint;
use Webeak\Bundle\EssentialBundle\Exception\UsageException;
use Webeak\Bundle\FileBundle\Processor\ProcessorInterface;
use Webeak\Component\Utils\ArrayUtils;

/**
 * Offer an handy interface to create a configuration object.
 */
class ConfigurationBuilder
{
    protected ContainerInterface $container;
    protected Configuration $configuration;
    private array $presets;
    private array $constraintsAliases;
    private array $processorsAliases;
    private ?array $processorsSequence;

    public function __construct(ContainerInterface $container,
                                $presets,
                                $constraintsAliases,
                                $processorsAliases)
    {
        $this->configuration = new Configuration();
        $this->container = $container;
        $this->constraintsAliases = $constraintsAliases;
        $this->processorsAliases = $processorsAliases;
        $this->presets = $presets;
        $this->processorsSequence = null;
    }

    /**
     * Register a new constraint.
     *
     * @param string|Constraint $constraint alias name, FQCN or a constraint class
     * @param array             $options    options to give to the constraint (not applicable if an instance is given)
     *
     * @return $this
     *
     * @throws
     */
    public function addConstraint($constraint, array $options = []): static
    {
        if (is_string($constraint)) {
            $fqcn = null;
            if (array_key_exists($constraint, $this->constraintsAliases)) {
                $fqcn = $this->constraintsAliases[$constraint];
            } else if (strpos($constraint, '/') !== false || strpos($constraint, '\\') !== false) {
                $fqcn = str_replace('/', '\\', $constraint);
            }
            if (!$fqcn) {
                throw new UsageException(sprintf(
                    'Constraint "%s" not found. Create an alias or set the FQCN of the target class.',
                    $constraint
                ));
            }
            $constraint = new $fqcn($options);
        }
        if ($constraint instanceof Constraint) {
            $this->configuration->addConstraint($constraint);
        } else {
            throw new UsageException(
                'Invalid constraint. Must be a subclass of "Symfony\Component\Validator\Constraint".',
                0,
                null,
                ['constraint' => $constraint]
            );
        }
        return $this;
    }

    /**
     * Register a new processor.
     *
     * @param string|ProcessorInterface $processor service id, alias name or processor class
     * @param array                     $options   options to give to the processor
     *
     * @return $this
     *
     * @throws
     */
    public function addProcessor($processor, array $options = [])
    {
        $originalInput = $processor;
        if ($this->processorsSequence === null) {
            throw new UsageException(
                'No processor sequence currently active. Call startProcessorsSequence() first.'
            );
        }
        try {
            $service = $processor;
            if (is_string($processor)) {
                if (array_key_exists($processor, $this->processorsAliases)) {
                    $processor = $this->processorsAliases[$processor];
                }
                $service = $this->container->get($processor);
            }
            if ($service instanceof ProcessorInterface) {
                $service->setOptions((array)$options);
                $this->processorsSequence[] = $service;
            } else {
                throw new UsageException(sprintf(
                    'The processor "%s" must implement "Webeak\Bundle\FileBundle\Processor\ProcessorInterface".',
                    $originalInput
                ), 0, null, ['processor' => $processor]);
            }
        } catch (ServiceNotFoundException $e) {
            throw new UsageException(sprintf(
                'No processor found for "%s". Service "%s" not found.',
                $originalInput,
                $processor
            ), 0, $e);
        }
        return $this;
    }

    /**
     * Add new users to the cumulative white list
     */
    public function addUsersWhiteListCumulative($username): static
    {
        $this->configuration->addUsersWhiteListCumulative($username);
        return $this;
    }

    /**
     * Get or set users ids of the cumulative white list.
     */
    public function usersWhiteListCumulative(mixed $username): array|static
    {
        if ($username !== null) {
            $this->configuration->setUsersWhiteListCumulative($username);
            return $this;
        }
        return $this->configuration->getUsersWhiteListCumulative();
    }

    /**
     * Add new users to the exclusive white list
     */
    public function addUsersWhiteListExclusive($username): static
    {
        $this->configuration->addUsersWhiteListExclusive($username);
        return $this;
    }

    /**
     * Get/set users of the exclusive white list
     */
    public function usersWhiteListExclusive($username): array|static
    {
        if ($username !== null) {
            $this->configuration->setUsersWhiteListExclusive($username);
            return $this;
        }
        return $this->configuration->getUsersWhiteListExclusive();
    }

    /**
     * Add new users to the black list
     */
    public function addUsersBlackList($username): static
    {
        $this->configuration->addUsersBlackList($username);
        return $this;
    }

    /**
     * Get/set users of the black list
     */
    public function usersBlackList($username = null): array|static
    {
        if ($username !== null) {
            $this->configuration->setUsersBlackList($username);
            return $this;
        }
        return $this->configuration->getUsersBlackList();
    }

    /**
     * Add roles to the existing set of required roles
     */
    public function addRequiredRoles($requiredRoles): static
    {
        $this->configuration->addRequiredRoles($requiredRoles);
        return $this;
    }

    /**
     * Get/set the whole list of required roles
     */
    public function requiredRoles($requiredRoles = null): array|static
    {
        if ($requiredRoles !== null) {
            $this->configuration->setRequiredRoles($requiredRoles);
            return $this;
        }
        return $this->configuration->getRequiredRoles();
    }

    /**
     * Get/set if the file should be publicly accessible through HTTP
     */
    public function public($public = null): bool|static
    {
        if ($public !== null) {
            $this->configuration->setPublic($public);
            return $this;
        }
        return $this->configuration->isPublic();
    }

    /**
     * Get/set if the file should auto confirm when uploaded.
     */
    public function autoConfirm($autoConfirm = null): bool|static
    {
        if ($autoConfirm !== null) {
            $this->configuration->setAutoConfirm($autoConfirm);
            return $this;
        }
        return $this->configuration->getAutoConfirm();
    }

    /**
     * Set the expiration date.
     * THIS METHOD IS ONLY A SETTER.
     *
     * Call getExpirationDate() to get the current value.
     */
    public function expiresAt(\DateTime $date = null): static
    {
        $this->configuration->setExpirationDate($date);
        return $this;
    }

    /**
     * Get the current expiration date.
     */
    public function getExpirationDate(): \DateTimeInterface
    {
        return $this->configuration->getExpirationDate();
    }

    /**
     * Get/set extra data associated to the file.
     * These extra are PRIVATE and will NOT appear in the PublicFile instance.
     */
    public function extra(array $extra = null): array|static
    {
        if ($extra !== null) {
            $this->configuration->addExtra($extra);
            return $this;
        }
        return $this->configuration->getExtra();
    }

    /**
     * Get/set public extra data associated to the file.
     * These extra are PUBLIC and WILL appear in the PublicFile instance.
     */
    public function publicExtra(array $extra = null): array|static
    {
        if ($extra !== null) {
            $this->configuration->addPublicExtra($extra);
            return $this;
        }
        return $this->configuration->getPublicExtra();
    }

    /**
     * Get the Configuration object behind the builder.
     */
    public function getConfiguration(): Configuration
    {
        return $this->configuration;
    }

    /**
     * Load a preset into the configuration.
     *
     * @param string|array $preset preset's name or configuration
     * @param boolean      $reset  (optional, default: false) if true, a new configuration object will be created,
     *                             otherwise the preset specifications will be added to the existing one.
     *
     * @return $this
     *
     * @throws
     */
    public function loadPreset($preset, $reset = false): static
    {
        if (is_string($preset)) {
            if (!array_key_exists($preset, $this->presets)) {
                throw new UsageException(sprintf('No configuration preset named "%s" has been found', $preset));
            }
            $preset = $this->presets[$preset];
        }
        if (!is_array($preset)) {
            throw new UsageException('A preset definition must be an array.', 0, null, ['input' => $preset]);
        }
        if ($reset) {
            $this->configuration = new Configuration();
        }
        if (array_key_exists('constraints', $preset)) {
            foreach ($preset['constraints'] as $alias => $options) {
                $this->addConstraint($alias, $options);
            }
        }
        if (array_key_exists('processors', $preset)) {
            $processors = array_values((array)$preset['processors']);
            for ($i = 0, $ii = count($processors); $i < $ii; ++$i) {
                $this->startProcessorsSequence();
                foreach ($processors[$i] as $name => $options) {
                    $this->addProcessor($name, $options);
                }
                $this->endProcessorsSequence();
            }
        }
        $this->addRequiredRoles(ArrayUtils::ensureArray(ArrayUtils::getValue($preset, 'requiredRoles')));
        $this->addUsersWhiteListExclusive(ArrayUtils::ensureArray(ArrayUtils::getValue($preset, 'whiteListExclusive')));
        $this->addUsersWhiteListCumulative(ArrayUtils::ensureArray(ArrayUtils::getValue($preset, 'whiteListCumulative')));
        $this->addUsersBlackList(ArrayUtils::ensureArray(ArrayUtils::getValue($preset, 'blackList')));
        $this->public(ArrayUtils::getValue($preset, 'public', $this->public()));
        $this->autoConfirm(ArrayUtils::getValue($preset, 'autoConfirm', $this->autoConfirm()));
        $this->extra(ArrayUtils::ensureArray(ArrayUtils::getValue($preset, 'extra')));
        $this->publicExtra(ArrayUtils::ensureArray(ArrayUtils::getValue($preset, 'publicExtra')));
        return $this;
    }

    /**
     * Create a sequence to register processors into.
     * All calls to 'addProcessor()' will be attributed to this sequence until a call to 'endProcessorsSequence()' is made.
     *
     * @throws
     */
    public function startProcessorsSequence(): static
    {
        if ($this->processorsSequence !== null) {
            throw new UsageException('A processor sequence is already started. Call endProcessorsSequence() first.');
        }
        $this->processorsSequence = [];
        return $this;
    }

    /**
     * End the current sequence of processors and register them.
     *
     * @throws
     */
    public function endProcessorsSequence(): static
    {
        if ($this->processorsSequence === null) {
            throw new UsageException('No processor sequence currently active. Call startProcessorsSequence() first.');
        }
        if (count($this->processorsSequence) > 0) {
            $this->configuration->addProcessorsSequence($this->processorsSequence);
        }
        $this->processorsSequence = null;
        return $this;
    }
}
