<?php

namespace Alexwenzel\DependencyContainer;

use Illuminate\Support\Str;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Http\Requests\NovaRequest;

class DependencyContainer extends Field
{
    /**
     * The field's component.
     *
     * @var string
     */
    public $component = 'dependency-container';

    /**
     * @var bool
     */
    public $showOnIndex = false;

    /**
     * DependencyContainer constructor.
     *
     * @param array|Field[] $fields
     * @param string|null $attribute
     * @param callable|null $resolveCallback
     */
    public function __construct($fields, $attribute = null, $resolveCallback = null)
    {
        parent::__construct('', $attribute, $resolveCallback);

        $this->withMeta([
            'fields' => $fields,
            'dependencies' => [],
        ]);
    }

    public function dependsOn($field, $value)
    {
        return $this->addDependency($this->getFieldLayout($field, $value));
    }

    public function dependsOnNot($field, $value)
    {
        return $this->addDependency(array_merge($this->getFieldLayout($field), ['not' => $value]));
    }

    public function dependsOnEmpty($field)
    {
        return $this->addDependency(array_merge($this->getFieldLayout($field), ['empty' => true]));
    }

    public function dependsOnNotEmpty($field)
    {
        return $this->addDependency(array_merge($this->getFieldLayout($field), ['notEmpty' => true]));
    }

    public function dependsOnNullOrZero($field)
    {
        return $this->addDependency(array_merge($this->getFieldLayout($field), ['nullOrZero' => true]));
    }

    public function dependsOnIn($field, $array)
    {
        return $this->addDependency(array_merge($this->getFieldLayout($field), ['in' => $array]));
    }

    public function dependsOnNotIn($field, $array)
    {
        return $this->addDependency(array_merge($this->getFieldLayout($field), ['notin' => $array]));
    }

    protected function addDependency(array $dependency)
    {
        return $this->withMeta([
            'dependencies' => array_merge($this->meta['dependencies'], [$dependency]),
        ]);
    }

    protected function getFieldLayout($field, $value = null): array
    {
        $fieldParts = explode('.', $field);

        if (count($fieldParts) === 1) {
            $fieldParts[1] = $fieldParts[0];
        }

        return [
            'field' => $fieldParts[0],
            'property' => $fieldParts[1],
            'value' => $value,
        ];
    }

    public function resolveForDisplay($resource, ?string $attribute = null): void
    {
        foreach ($this->meta['fields'] as $field) {
            $field->resolveForDisplay($resource);
        }

        foreach ($this->meta['dependencies'] as $index => $dependency) {
            $this->meta['dependencies'][$index]['satisfied'] = false;

            $propertyValue = $resource->{$dependency['property']} ?? null;

            if (array_key_exists('empty', $dependency) && empty($propertyValue)) {
                $this->meta['dependencies'][$index]['satisfied'] = true;
                continue;
            }

            if (array_key_exists('notEmpty', $dependency) && !empty($propertyValue)) {
                $this->meta['dependencies'][$index]['satisfied'] = true;
                continue;
            }

            if (array_key_exists('nullOrZero', $dependency) && in_array($propertyValue, [null, 0, '0'], true)) {
                $this->meta['dependencies'][$index]['satisfied'] = true;
                continue;
            }

            if (array_key_exists('not', $dependency) && $propertyValue != $dependency['not']) {
                $this->meta['dependencies'][$index]['satisfied'] = true;
                continue;
            }

            if (array_key_exists('in', $dependency) && in_array($propertyValue, $dependency['in'])) {
                $this->meta['dependencies'][$index]['satisfied'] = true;
                continue;
            }

            if (array_key_exists('notin', $dependency) && !in_array($propertyValue, $dependency['notin'])) {
                $this->meta['dependencies'][$index]['satisfied'] = true;
                continue;
            }

            if (array_key_exists('value', $dependency)) {
                if (is_array($resource)) {
                    if (isset($resource[$dependency['property']]) && $resource[$dependency['property']] == $dependency['value']) {
                        $this->meta['dependencies'][$index]['satisfied'] = true;
                    }
                    continue;
                }

                if ($propertyValue == $dependency['value']) {
                    $this->meta['dependencies'][$index]['satisfied'] = true;
                    continue;
                }

                $morphable = $resource->getAttribute($dependency['property'] . '_type') ?? null;

                if ($morphable && Str::endsWith($morphable, '\\' . $dependency['value'])) {
                    $this->meta['dependencies'][$index]['satisfied'] = true;
                    continue;
                }
            }
        }
    }

    public function resolve($resource, ?string $attribute = null): void
    {
        foreach ($this->meta['fields'] as $field) {
            $field->resolve($resource, $attribute);
        }
    }

    public function fillInto(NovaRequest $request, $model, $attribute, $requestAttribute = null)
    {
        $callbacks = [];

        foreach ($this->meta['fields'] as $field) {
            $callbacks[] = $field->fill($request, $model);
        }

        return function () use ($callbacks) {
            foreach ($callbacks as $callback) {
                if (is_callable($callback)) {
                    $callback();
                }
            }
        };
    }

    public function areDependenciesSatisfied(NovaRequest $request): bool
    {
        if (empty($this->meta['dependencies']) || !is_array($this->meta['dependencies'])) {
            return false;
        }

        $satisfiedCounts = 0;

        foreach ($this->meta['dependencies'] as $dependency) {
            $value = $request->get($dependency['property']);

            if (isset($dependency['empty']) && empty($value)) {
                $satisfiedCounts++;
                continue;
            }

            if (isset($dependency['notEmpty']) && !empty($value)) {
                $satisfiedCounts++;
                continue;
            }

            if (isset($dependency['nullOrZero']) && in_array($value, [null, 0, '0', ''], true)) {
                $satisfiedCounts++;
                continue;
            }

            if (isset($dependency['in']) && in_array($value, $dependency['in'])) {
                $satisfiedCounts++;
                continue;
            }

            if (isset($dependency['notin']) && !in_array($value, $dependency['notin'])) {
                $satisfiedCounts++;
                continue;
            }

            if (isset($dependency['not']) && $dependency['not'] != $value) {
                $satisfiedCounts++;
                continue;
            }

            if (isset($dependency['value']) && $dependency['value'] == $value) {
                $satisfiedCounts++;
            }
        }

        return $satisfiedCounts === count($this->meta['dependencies']);
    }
}
