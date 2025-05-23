<?php

declare(strict_types=1);

namespace Jadob\Objectable;

use DateTimeInterface;
use Doctrine\Common\Collections\Collection;
use Jadob\Objectable\Annotation\Field;
use Jadob\Objectable\Annotation\Translate;
use Jadob\Objectable\Transformer\ItemTransformerInterface;
use LogicException;
use ReflectionClass;

class ItemProcessor
{
    /**
     * @var ItemTransformerInterface[]
     */
    private array $itemTransformers = [];

    public function __construct(
        iterable $itemTransformers = []
    ) {
        $this->itemTransformers = iterator_to_array($itemTransformers);
    }

    /**
     * Returns array of values from fields annotated with Field class and matching context.
     */
    public function extractItemValues(object $item, string $context = 'default'): array
    {
        foreach ($this->itemTransformers as $itemTransformer) {
            if ($itemTransformer->supports(get_class($item), $context)) {
                return $itemTransformer->process($item);
            }
        }

        $output = [];
        $ref = new ReflectionClass($item);
        $props = $ref->getProperties();

        foreach ($props as $reflectionProperty) {
            $attrs = $reflectionProperty->getAttributes(Field::class);
            $translations = $reflectionProperty->getAttributes(Translate::class);
            foreach ($attrs as $fieldAttr) {
                /** @var Field $instance */
                $instance = $fieldAttr->newInstance();

                if ($instance->hasContext($context)) {
                    $val = $reflectionProperty->getValue($item);

                    if ($instance->getMethod() !== null) {
                        $methodName = $instance->getMethod();
                        $val = $item->$methodName();
                    }

                    $output[$instance->getName()] = $val;

                    foreach ($translations as $translationReflection) {
                        /** @var Translate $translationAttr */
                        $translationAttr = $translationReflection->newInstance();

                        if ($val === $translationAttr->getWhen()) {
                            $output[$instance->getName()] = $translationAttr->getThen();
                            continue 2;
                        }
                    }

                    if ($val instanceof DateTimeInterface) {
                        $dateFormat = $instance->getDateFormat();
                        if ($dateFormat === null) {
                            throw new LogicException('Could not process DateTime object as there is no dateFormat passed in Field.');
                        }

                        $output[$instance->getName()] = $val->format($dateFormat);
                        continue;
                    }

                    /**
                     * Enables support for doctrine/orm entities, or any other lib that utilises doctrine/collections.
                     */
                    if ($val instanceof Collection) {
                        $collectionItems = $val->toArray();
                        $processedCollectionItems = [];
                        foreach ($collectionItems as $collectionItem) {
                            $processedCollectionItems[] = $this->extractItemValues($collectionItem, $context);
                        }

                        $output[$instance->getName()] = $processedCollectionItems;
                    }


                    if ($instance->isStringable()) {
                        $output[$instance->getName()] = (string) $val;
                        continue;
                    }

                    if ($instance->isFlat()) {
                        if ($val === null) {
                            $output[$instance->getName()] = null;
                            continue;
                        }

                        $flattenedVal = $this->extractItemValues($val, $context);

                        if (count($flattenedVal) === 0) {
                            throw new LogicException(sprintf('Cannot pick a value from "%s" as no properties has been serialized from object.', get_class($val)));
                        }

                        if (count($flattenedVal) === 1) {
                            $output[$instance->getName()] = reset($flattenedVal);
                            continue;
                        }

                        if (count($flattenedVal) > 1) {
                            if ($instance->getFlatProperty() === null) {
                                throw new LogicException('$flatProperty cannot be null when there is more than one element to choose.');
                            }
                            $output[$instance->getName()] = $flattenedVal[$instance->getFlatProperty()];
                        }
                    }

                    if (is_object($val)) {
                        $output[$instance->getName()] = $this->extractItemValues($val, $context);
                    }
                }
            }
        }

        return $output;
    }
}
