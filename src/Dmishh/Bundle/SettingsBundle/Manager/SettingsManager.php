<?php

/**
 * This file is part of the DmishhSettingsBundle package.
 *
 * (c) 2013 Dmitriy Scherbina <http://dmishh.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Dmishh\Bundle\SettingsBundle\Manager;

use Dmishh\Bundle\SettingsBundle\Entity\Setting;
use Dmishh\Bundle\SettingsBundle\Entity\UserInterface;
use Dmishh\Bundle\SettingsBundle\Exception\UnknownSettingException;
use Dmishh\Bundle\SettingsBundle\Exception\WrongScopeException;
use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\ORM\EntityRepository;

class SettingsManager implements SettingsManagerInterface
{
    /**
     * @var array
     */
    private $globalSettings;

    /**
     * @var array
     */
    private $userSettings;

    /**
     * @var ObjectManager
     */
    private $em;

    /**
     * @var EntityRepository
     */
    private $repository;

    /**
     * @var array
     */
    private $options = array(
        'inherit_global_settings' => true,
        'settings_configuration' => array(),
    );

    /**
     * @param ObjectManager $em
     * @param array $options
     */
    public function __construct(ObjectManager $em, array $options = array())
    {
        $this->em = $em;
        $this->repository = $em->getRepository('Dmishh\\Bundle\\SettingsBundle\\Entity\\Setting');

        foreach ($options as $name => $value) {
            if (array_key_exists($name, $this->options)) {
                $this->options[$name] = $value;
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function get($name, UserInterface $user = null)
    {
        $this->validateSetting($name, $user);
        $this->loadSettings($user);

        $value = null;

        if ($user === null) {
            $value = $this->globalSettings[$name];
        } else {
            if ($this->userSettings[$user->getId()][$name] !== null) {
                $value = $this->userSettings[$user->getId()][$name];
            } elseif ($this->options['inherit_global_settings'] && array_key_exists($name, $this->globalSettings)) {
                $value = $this->globalSettings[$name];
            }
        }

        return $value;
    }

    /**
     * {@inheritDoc}
     */
    public function all(UserInterface $user = null)
    {
        $this->loadSettings($user);

        if ($user === null) {
            return $this->globalSettings;
        } else {
            return $this->userSettings[$user->getId()];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function set($name, $value, UserInterface $user = null)
    {
        $this->setWithoutFlush($name, $value, $user);

        return $this->flush($name, $user);
    }

    /**
     * {@inheritDoc}
     */
    public function setMany(array $settings, UserInterface $user = null)
    {
        foreach ($settings as $name => $value) {
            $this->setWithoutFlush($name, $value, $user);
        }

        return $this->flush(array_keys($settings), $user);
    }

    /**
     * {@inheritDoc}
     */
    public function clear($name, UserInterface $user = null)
    {
        return $this->set($name, null, $user);
    }

    /**
     * @param string $name
     * @param mixed $value
     * @param UserInterface $user
     * @return SettingsManager
     */
    private function setWithoutFlush($name, $value, UserInterface $user = null)
    {
        $this->validateSetting($name, $user);
        $this->loadSettings($user);

        if ($user === null) {
            $this->globalSettings[$name] = $value;
        } else {
            $this->userSettings[$user->getId()][$name] = $value;
        }

        return $this;
    }

    /**
     * @param string|array $names
     * @param UserInterface $user
     * @return SettingsManager
     */
    private function flush($names, UserInterface $user = null)
    {
        $names = (array) $names;

        $settings = $this->repository->findBy(array('name' => $names, 'userId' => $user === null ? null : $user->getId()));
        $findByName = function ($name) use ($settings) {
            $setting = array_filter($settings, function ($setting) use ($name) {
                return $setting->getName() == $name;
            });
            return !empty($setting) ? array_pop($setting) : null;
        };

        /** @var Setting $setting */
        foreach ($this->options['settings_configuration'] as $name => $configuration) {

            try {
                $value = $this->get($name, $user);
            } catch (WrongScopeException $e) {
                continue;
            }

            $setting = $findByName($name);
            if (!$setting) {
                $setting = new Setting();
                $setting->setName($name);
                if ($user !== null) {
                    $setting->setUserId($user->getId());
                }
                $this->em->persist($setting);
            }


            $setting->setValue(serialize($value));
        }

        $this->em->flush();

        return $this;
    }

    /**
     * Checks that $name is valid setting and it's scope is also valid
     *
     * @param string $name
     * @param UserInterface $user
     * @return SettingsManager
     * @throws \Dmishh\Bundle\SettingsBundle\Exception\UnknownSettingException
     * @throws \Dmishh\Bundle\SettingsBundle\Exception\WrongScopeException
     */
    private function validateSetting($name, UserInterface $user = null)
    {
        // Name validation
        if (!is_string($name) || !array_key_exists($name, $this->options['settings_configuration'])) {
            throw new UnknownSettingException($name);
        }

        // Scope validation
        $scope = $this->options['settings_configuration'][$name]['scope'];
        if ($scope !== SettingsManagerInterface::SCOPE_ALL) {
            if ($scope === SettingsManagerInterface::SCOPE_GLOBAL && $user !== null || $scope === SettingsManagerInterface::SCOPE_USER && $user === null) {
                throw new WrongScopeException($scope, $name);
            }
        }

        return $this;
    }

    /**
     * Method for settings lazy loading
     *
     * @param UserInterface $user
     * @return SettingsManager
     */
    private function loadSettings(UserInterface $user = null)
    {
        // Global settings
        if ($this->globalSettings === null) {
            $this->globalSettings = $this->getSettingsFromRepository();
        }

        // User settings
        if ($user !== null && ($this->userSettings === null || !array_key_exists($user->getId(), $this->userSettings))) {
            $this->userSettings[$user->getId()] = $this->getSettingsFromRepository($user);
        }

        return $this;
    }

    /**
     * Retreives settings from repository
     *
     * @param UserInterface $user
     * @return array
     */
    private function getSettingsFromRepository(UserInterface $user = null)
    {
        $settings = array();

        foreach (array_keys($this->options['settings_configuration']) as $name) {
            try {
                $this->validateSettingScope($name, $user);
                $settings[$name] = null;
            } catch (WrongScopeException $e) {
                continue;
            }
        }

        /** @var Setting $setting */
        foreach ($this->repository->findBy(array('userId' => $user === null ? null : $user->getId())) as $setting) {
            if (array_key_exists($setting->getName(), $settings)) {
                $settings[$setting->getName()] = unserialize($setting->getValue());
            }
        }

        return $settings;
    }
}
