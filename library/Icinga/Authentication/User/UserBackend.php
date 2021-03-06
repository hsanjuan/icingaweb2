<?php
/* Icinga Web 2 | (c) 2013-2015 Icinga Development Team | GPLv2+ */

namespace Icinga\Authentication\User;

use Icinga\Application\Config;
use Icinga\Application\Logger;
use Icinga\Application\Icinga;
use Icinga\Data\ConfigObject;
use Icinga\Data\ResourceFactory;
use Icinga\Exception\ConfigurationError;

/**
 * Factory for user backends
 */
class UserBackend
{
    /**
     * The default user backend types provided by Icinga Web 2
     *
     * @var array
     */
    protected static $defaultBackends = array(
        'external',
        'db',
        'ldap',
        'msldap'
    );

    /**
     * The registered custom user backends with their identifier as key and class name as value
     *
     * @var array
     */
    protected static $customBackends;

    /**
     * Register all custom user backends from all loaded modules
     */
    protected static function registerCustomUserBackends()
    {
        if (static::$customBackends !== null) {
            return;
        }

        static::$customBackends = array();
        $providedBy = array();
        foreach (Icinga::app()->getModuleManager()->getLoadedModules() as $module) {
            foreach ($module->getUserBackends() as $identifier => $className) {
                if (array_key_exists($identifier, $providedBy)) {
                    Logger::warning(
                        'Cannot register user backend of type "%s" provided by module "%s".'
                        . ' The type is already provided by module "%s"',
                        $identifier,
                        $module->getName(),
                        $providedBy[$identifier]
                    );
                } elseif (in_array($identifier, static::$defaultBackends)) {
                    Logger::warning(
                        'Cannot register user backend of type "%s" provided by module "%s".'
                        . ' The type is a default type provided by Icinga Web 2',
                        $identifier,
                        $module->getName()
                    );
                } else {
                    $providedBy[$identifier] = $module->getName();
                    static::$customBackends[$identifier] = $className;
                }
            }
        }
    }

    /**
     * Return the class for the given custom user backend
     *
     * @param   string  $identifier     The identifier of the custom user backend
     *
     * @return  string|null             The name of the class or null in case there was no
     *                                   backend found with the given identifier
     *
     * @throws  ConfigurationError      In case the class associated to the given identifier does not exist
     */
    protected static function getCustomUserBackend($identifier)
    {
        static::registerCustomUserBackends();
        if (array_key_exists($identifier, static::$customBackends)) {
            $className = static::$customBackends[$identifier];
            if (! class_exists($className)) {
                throw new ConfigurationError(
                    'Cannot utilize user backend of type "%s". Class "%s" does not exist',
                    $identifier,
                    $className
                );
            }

            return $className;
        }
    }

    /**
     * Create and return a user backend with the given name and given configuration applied to it
     *
     * @param   string          $name
     * @param   ConfigObject    $backendConfig
     *
     * @return  UserBackendInterface
     *
     * @throws  ConfigurationError
     */
    public static function create($name, ConfigObject $backendConfig = null)
    {
        if ($backendConfig === null) {
            $authConfig = Config::app('authentication');
            if ($authConfig->hasSection($name)) {
                $backendConfig = $authConfig->getSection($name);
            } else {
                throw new ConfigurationError('User backend "%s" does not exist', $name);
            }
        }

        if ($backendConfig->name !== null) {
            $name = $backendConfig->name;
        }

        if (! ($backendType = strtolower($backendConfig->backend))) {
            throw new ConfigurationError(
                'Authentication configuration for user backend "%s" is missing the \'backend\' directive',
                $name
            );
        }
        if ($backendType === 'external') {
            $backend = new ExternalBackend($backendConfig);
            $backend->setName($name);
            return $backend;
        }
        if (in_array($backendType, static::$defaultBackends)) {
            // The default backend check is the first one because of performance reasons:
            // Do not attempt to load a custom user backend unless it's actually required
        } elseif (($customClass = static::getCustomUserBackend($backendType)) !== null) {
            $backend = new $customClass($backendConfig);
            if (! is_a($backend, 'Icinga\Authentication\User\UserBackendInterface')) {
                throw new ConfigurationError(
                    'Cannot utilize user backend of type "%s". Class "%s" does not implement UserBackendInterface',
                    $backendType,
                    $customClass
                );
            }

            $backend->setName($name);
            return $backend;
        } else {
            throw new ConfigurationError(
                'Authentication configuration for user backend "%s" defines an invalid backend type.'
                . ' Backend type "%s" is not supported',
                $name,
                $backendType
            );
        }

        if ($backendConfig->resource === null) {
            throw new ConfigurationError(
                'Authentication configuration for user backend "%s" is missing the \'resource\' directive',
                $name
            );
        }

        if ($backendConfig->resource instanceof ConfigObject) {
            $resource = ResourceFactory::createResource($backendConfig->resource);
        } else {
            $resource = ResourceFactory::create($backendConfig->resource);
        }

        switch ($backendType) {
            case 'db':
                $backend = new DbUserBackend($resource);
                break;
            case 'msldap':
                $backend = new LdapUserBackend($resource);
                $backend->setBaseDn($backendConfig->base_dn);
                $backend->setUserClass($backendConfig->get('user_class', 'user'));
                $backend->setUserNameAttribute($backendConfig->get('user_name_attribute', 'sAMAccountName'));
                $backend->setFilter($backendConfig->filter);
                break;
            case 'ldap':
                $backend = new LdapUserBackend($resource);
                $backend->setBaseDn($backendConfig->base_dn);
                $backend->setUserClass($backendConfig->get('user_class', 'inetOrgPerson'));
                $backend->setUserNameAttribute($backendConfig->get('user_name_attribute', 'uid'));
                $backend->setFilter($backendConfig->filter);
                break;
        }

        $backend->setName($name);
        return $backend;
    }
}
