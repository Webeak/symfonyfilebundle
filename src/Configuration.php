<?php
namespace Webeak\Bundle\FileBundle;

use Webeak\Bundle\FileBundle\Processor\ProcessorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Webeak\Component\Utils\ArrayUtils;

/**
 * Configuration object used by the file manager.
 * It defines how the manager should handle a given set of input files.
 */
class Configuration
{
    /** @var Constraint[] */
    protected $constraints;

    /** @var ProcessorInterface[][] */
    protected $processorsSequences;

    /**
     * List of users that have access to the file if they ALSO match required roles.
     *
     * @var array
     */
    protected $usersWhiteListCumulative;

    /**
     * List of users that have access to the file NO MATTER their roles
     * and what roles are required to access the file.
     *
     * @var array
     */
    protected $usersWhiteListExclusive;

    /**
     * List of users that have NO access to the file NO MATTER their roles
     * or anything else. If a user is listed here he should never access the file.
     *
     * @var array
     */
    protected $usersBlackList;

    /**
     * Roles required to access the file. See the interactions between roles and
     * white/black list described above for details.
     *
     * @var array
     */
    protected $requiredRoles;

    /**
     * If the file publicly available?
     * Having no access right DOES NOT make it public, you have to explicitly set it to public if you so desire.
     * A file CANNOT be public if any access right have been setup.
     *
     * A public file is stored in the "web/" folder and is directly accessible by HTTP.
     *
     * Non public files can only be accessed trough a proxy action, no matter their access rights.
     *
     * @var boolean
     */
    protected $public;

    /** @var \DateTime */
    protected $expirationDate;

    /** @var array */
    protected $extra;

    /** @var array */
    protected $publicExtra;

    public function __construct()
    {
        $this->constraints = [];
        $this->processorsSequences = [];
        $this->usersWhiteListCumulative = [];
        $this->usersWhiteListExclusive = [];
        $this->usersBlackList = [];
        $this->requiredRoles = [];
        $this->public = false;
        $this->expirationDate = null;
        $this->extra = [];
        $this->publicExtra = [];
    }

    /**
     * Add a new constraint.
     *
     * @param Constraint $constraint
     *
     * @return $this
     */
    public function addConstraint(Constraint $constraint)
    {
        $this->constraints[] = $constraint;
        return $this;
    }

    /**
     * Get the list of constraints.
     *
     * @return Constraint[]
     */
    public function getConstraints()
    {
        return $this->constraints;
    }

    /**
     * Test if the configuration defines constraints.
     *
     * @return boolean
     */
    public function hasConstraints()
    {
        return count($this->constraints) > 0;
    }

    /**
     * Add a new processor sequence.
     * Is a sequence is a list of processors that will be executed sequentially (they send their output to the next processor as a pipeline).
     * Sequences are executed in parallel (the input is always the same at the beginning of each sequence).
     *
     * @param ProcessorInterface[] $sequence
     *
     * @return $this
     */
    public function addProcessorsSequence(array $sequence)
    {
        if (count($sequence) > 0) {
            $this->processorsSequences[] = $sequence;
        }
        return $this;
    }

    /**
     * Get the list of processors sequences.
     * Each sequence contains an array of processors.
     *
     * @return ProcessorInterface[][]
     */
    public function getProcessorsSequences()
    {
        return $this->processorsSequences;
    }

    /**
     * Test if the configuration defines processors.
     *
     * @return boolean
     */
    public function hasProcessors()
    {
        return count($this->processorsSequences) > 0;
    }

    /**
     * Add new users to the cumulative white list
     *
     * @param string|string[] $username
     *
     * @return $this
     */
    public function addUsersWhiteListCumulative($username)
    {
        $this->usersWhiteListCumulative = array_unique(array_merge($this->usersWhiteListCumulative, (array)$username));
        return $this;
    }

    /**
     * Set users of the cumulative white list
     *
     * @param string|string[] $username
     *
     * @return $this
     */
    public function setUsersWhiteListCumulative($username)
    {
        $this->usersWhiteListCumulative = [];
        $this->addUsersWhiteListCumulative($username);
        return $this;
    }

    /**
     * Get users of the cumulative white list
     *
     * @return string[]
     */
    public function getUsersWhiteListCumulative(): array
    {
        return $this->usersWhiteListCumulative;
    }

    /**
     * Add new users to the exclusive white list
     *
     * @param string|string[] $username
     *
     * @return $this
     */
    public function addUsersWhiteListExclusive($username)
    {
        $this->usersWhiteListExclusive = array_unique(array_merge($this->usersWhiteListExclusive, (array)$username));
        return $this;
    }

    /**
     * Set users of the exclusive white list
     *
     * @param string|string[] $username
     *
     * @return $this
     */
    public function setUsersWhiteListExclusive($username)
    {
        $this->usersWhiteListExclusive = [];
        $this->addUsersWhiteListExclusive($username);
        return $this;
    }

    /**
     * Get users of the exclusive white list
     *
     * @return array
     */
    public function getUsersWhiteListExclusive()
    {
        return $this->usersWhiteListExclusive;
    }

    /**
     * Add new users to the black list
     *
     * @param string|string[] $username
     *
     * @return $this
     */
    public function addUsersBlackList($username)
    {
        $this->usersBlackList = array_unique(array_merge($this->usersBlackList, (array)$username));
        return $this;
    }

    /**
     * Set users of the black list
     *
     * @param string|string[] $username
     *
     * @return $this
     */
    public function setUsersBlackList($username)
    {
        $this->usersBlackList = [];
        $this->addUsersBlackList($username);
        return $this;
    }

    /**
     * Get users of the black list.
     *
     * @return string[]
     */
    public function getUsersBlackList(): array
    {
        return $this->usersBlackList;
    }

    /**
     * Add roles to the existing set of required roles
     *
     * @param string|array $requiredRoles
     *
     * @return $this
     */
    public function addRequiredRoles($requiredRoles)
    {
        $this->requiredRoles = array_unique(array_merge($this->requiredRoles, (array)$requiredRoles));
        return $this;
    }

    /**
     * Set requiredRoles
     *
     * @param string|array $requiredRoles
     *
     * @return $this
     */
    public function setRequiredRoles($requiredRoles)
    {
        $this->requiredRoles = [];
        $this->addRequiredRoles($requiredRoles);
        return $this;
    }

    /**
     * Get requiredRoles
     *
     * @return array
     */
    public function getRequiredRoles()
    {
        return $this->requiredRoles;
    }

    /**
     * Set if the file should be publicly accessible through HTTP
     *
     * @param boolean $public
     *
     * @return $this
     */
    public function setPublic($public)
    {
        $this->public = !!$public;
        return $this;
    }

    /**
     * Get if the file should be publicly accessible through HTTP
     *
     * @return boolean
     */
    public function getPublic()
    {
        return $this->public;
    }

    /**
     * Alias of getPublic().
     *
     * @return boolean
     */
    public function isPublic()
    {
        return $this->getPublic();
    }

    /**
     * Set the expiration date of the file.
     * The file will be removed if that date is reached before it's confirmed.
     *
     * @param \DateTime $date
     *
     * @return $this
     */
    public function setExpirationDate(\DateTime $date = null)
    {
        $this->expirationDate = $date;
        return $this;
    }

    /**
     * Get the expiration date of the file.
     *
     * @return \DateTime
     */
    public function getExpirationDate()
    {
        return $this->expirationDate;
    }

    /**
     * Add extra while keeping existing ones.
     *
     * @param array $extra
     *
     * @return Configuration
     */
    public function addExtra(array $extra)
    {
        $this->extra = ArrayUtils::mergeRecursiveDistinct($this->extra, $extra);
        return $this;
    }

    /**
     * Set extra
     *
     * @param array $extra
     *
     * @return Configuration
     */
    public function setExtra(array $extra)
    {
        $this->extra = $extra;
        return $this;
    }

    /**
     * Get extra
     *
     * @return array
     */
    public function getExtra()
    {
        return $this->extra;
    }

    /**
     * Set public extra while keeping existing ones.
     *
     * @param array $extra
     *
     * @return Configuration
     */
    public function addPublicExtra(array $extra)
    {
        $this->publicExtra = ArrayUtils::mergeRecursiveDistinct($this->publicExtra, $extra);
        return $this;
    }

    /**
     * Set public extra
     *
     * @param array $extra
     *
     * @return Configuration
     */
    public function setPublicExtra(array $extra)
    {
        $this->publicExtra = $extra;
        return $this;
    }

    /**
     * Get public extra
     *
     * @return array
     */
    public function getPublicExtra()
    {
        return $this->publicExtra;
    }

    /**
     * Return a PHP array holding the whole configuration.
     * You can restore the Configuration instance by using either:
     *
     *  - $configurationInstance->importGenericRepresentation()
     *  - Configuration::createFromGenericRepresentation()
     *
     * @return array
     */
    public function exportGenericRepresentation()
    {
        $sequences = [];
        for ($i = 0, $ii = count($this->processorsSequences); $i < $ii; ++$i) {
            $sequence = [];
            for ($j = 0, $jj = count($this->processorsSequences[$i]); $j < $jj; ++$j) {
                $sequence[] = [
                    'id' => $this->processorsSequences[$i][$j]->getServiceId(),
                    'options' => $this->processorsSequences[$i][$j]->getOptions()
                ];
            }
            $sequences[] = $sequence;
        }
        return [
            'constraints' => array_map('serialize', $this->constraints),
            'processorsSequences' => $sequences,
            'usersWhiteListCumulative' => $this->usersWhiteListCumulative,
            'usersWhiteListExclusive' => $this->usersWhiteListExclusive,
            'usersBlackList' => $this->usersBlackList,
            'requiredRoles' => $this->requiredRoles,
            'public' => $this->public,
            'expirationDate' => $this->expirationDate instanceof \DateTime ? $this->expirationDate->format('Y-m-d H:i:s') : null,
            'extra' => $this->extra,
            'publicExtra' => $this->publicExtra
        ];
    }

    /**
     * Import configuration data into the current instance.
     *
     * @param ContainerInterface $container
     * @param array              $configuration
     */
    public function importGenericRepresentation(ContainerInterface $container, array $configuration)
    {
        if (array_key_exists('constraints', $configuration)) {
            for ($i = 0, $ii = count($configuration['constraints']); $i < $ii; ++$i) {
                $this->addConstraint(unserialize($configuration['constraints'][$i]));
            }
        }
        if (array_key_exists('processorsSequences', $configuration)) {
            for ($i = 0, $ii = count($configuration['processorsSequences']); $i < $ii; ++$i) {
                $sequence = [];
                for ($j = 0, $jj = count($configuration['processorsSequences'][$i]); $j < $jj; ++$j) {
                    $instance = $container->get($configuration['processorsSequences'][$i][$j]['id']);
                    $instance->setOptions($configuration['processorsSequences'][$i][$j]['options']);
                    $sequence[] = $instance;
                }
                $this->addProcessorsSequence($sequence);
            }
        }
        if (array_key_exists('expirationDate', $configuration) && $configuration['expirationDate'] !== null) {
            $this->expirationDate = \DateTime::createFromFormat('Y-m-d H:i:s', $configuration['expirationDate']);
        }
        $fields = ['usersWhiteListCumulative', 'usersWhiteListExclusive', 'usersBlackList', 'requiredRoles', 'public', 'extra', 'publicExtra'];
        for ($i = 0, $ii = count($fields); $i < $ii; ++$i) {
            $field = $fields[$i];
            if (array_key_exists($field, $configuration)) {
                if (is_array($this->$field)) {
                    $configuration[$field] = ArrayUtils::ensureArray($configuration[$field]);
                }
                $this->$field = $configuration[$field];
            }
        }
    }

    /**
     * Creates an instance of Configuration using an exported one.
     *
     * @param ContainerInterface $container
     * @param array              $configuration
     *
     * @return Configuration
     */
    static public function createFromGenericRepresenation(ContainerInterface $container, array $configuration)
    {
        $instance = new self();
        $instance->importGenericRepresentation($container, $configuration);
        return $instance;
    }
}
