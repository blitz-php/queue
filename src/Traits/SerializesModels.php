<?php

/**
 * This file is part of BlitzPHP Queue.
 *
 * (c) 2026 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Queue\Traits;

use ReflectionClass;
use ReflectionProperty;

/**
 * Sérialise et restaure les propriétés d'un job, y compris les modèles liés.
 */
trait SerializesModels
{
    use SerializesAndRestoresModelIdentifiers;

    /**
     * Prépare les valeurs de l'instance pour la sérialisation.
     */
    public function __serialize(): array
    {
        $values = [];

        $reflectionClass = new ReflectionClass($this);

        [$class, $properties, $classLevelWithoutRelations] = [
            static::class,
            $reflectionClass->getProperties(),
            property_exists($this, 'withoutRelations') && $this->withoutRelations === true,
        ];

        foreach ($properties as $property) {
            if ($property->isStatic()) {
                continue;
            }

            if (! $property->isInitialized($this)) {
                continue;
            }

            if (method_exists($property, 'isVirtual') && $property->isVirtual()) {
                continue;
            }

            $value = $this->getPropertyValue($property);

            if ($property->hasDefaultValue() && $value === $property->getDefaultValue()) {
                continue;
            }

            $name = $property->getName();

            if ($property->isPrivate()) {
                $name = "\0{$class}\0{$name}";
            } elseif ($property->isProtected()) {
                $name = "\0*\0{$name}";
            }

            $values[$name] = $this->getSerializedPropertyValue(
                $value,
                ! $classLevelWithoutRelations,
            );
        }

        return $values;
    }

    /**
     * Restaure le modèle après désérialisation.
     */
    public function __unserialize(array $values): void
    {
        $properties = (new ReflectionClass($this))->getProperties();

        $class = static::class;

        foreach ($properties as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $name = $property->getName();

            if ($property->isPrivate()) {
                $name = "\0{$class}\0{$name}";
            } elseif ($property->isProtected()) {
                $name = "\0*\0{$name}";
            }

            if (! array_key_exists($name, $values)) {
                continue;
            }

            $property->setValue(
                $this,
                $this->getRestoredPropertyValue($values[$name]),
            );
        }
    }

    /**
     * Retourne la valeur de la propriété donnée.
     */
    protected function getPropertyValue(ReflectionProperty $property): mixed
    {
        return $property->getValue($this);
    }
}
