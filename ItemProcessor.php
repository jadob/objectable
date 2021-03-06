<?php

declare(strict_types=1);

namespace Jadob\Objectable;

use Doctrine\Common\Annotations\Reader;
use Jadob\Objectable\Annotation\Field;
use Jadob\Objectable\Annotation\Translate;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessor;

class ItemProcessor
{
    public function __construct(
        protected Reader $annotReader,
        protected ?PropertyAccessor $propertyAccessor = null,
        protected ?ItemMetadataParser $metadataParser = null
    ) {
        if ($this->propertyAccessor === null) {
            $this->propertyAccessor = PropertyAccess::createPropertyAccessor();
        }

        if ($this->metadataParser === null) {
            $this->metadataParser = new ItemMetadataParser();
        }
    }

    /**
     * Returns array of values from fields annotated with Field class and matching context.
     *
     * @param string[] $context
     */
    public function extractItemValues(object $item, array $context = ['default']): array
    {
        $output = [];
        $ref = new \ReflectionClass($item);
        $props = $ref->getProperties();

        foreach ($props as $reflectionProperty) {
            $attrs = $reflectionProperty->getAttributes(Field::class);
            $translations = $reflectionProperty->getAttributes(Translate::class);
            $shouldExtract = false;
            foreach ($attrs as $fieldAttr) {
                /** @var Field $instance */
                $instance = $fieldAttr->newInstance();

                //TODO: there should be some array intersection or something similar
                foreach ($context as $singleContext) {
                    if ($instance->hasContext($singleContext)) {
                        $reflectionProperty->setAccessible(true);
                        $val = $reflectionProperty->getValue($item);

                        $output[$instance->getName()] = $val;

                        foreach ($translations as $translationReflection) {
                            /** @var Translate $translationAttr */
                            $translationAttr = $translationReflection->newInstance();

                            if ($val === $translationAttr->getWhen()) {
                                $output[$instance->getName()] = $translationAttr->getThen();
                                continue 2;
                            }
                        }

                        if($val instanceof \DateTimeInterface) {
                            $dateFormat = $instance->getDateFormat();
                            if($dateFormat === null) {
                                throw new \LogicException('Could not process DateTime object as there is no dateFormat passed in Field.');
                            }

                            $output[$instance->getName()] = $val->format($dateFormat);
                            continue;
                        }

                        if ($instance->isStringable()) {
                            $output[$instance->getName()] = (string) $val;
                            continue;
                        }

                        if ($instance->isFlat()) {
                            //TODO: add allowNull in attribute?
                            if($val === null) {
                                $output[$instance->getName()] = null;
                                continue;
                            }

                            // todo: check if val is an object
                            $flattenedVal = $this->extractItemValues($val, $context);

                            if (count($flattenedVal) === 0) {
                                throw new \LogicException(
                                    sprintf(
                                        'Cannot pick a value from "%s" as no properties has been serialized from object.',
                                        get_class($val)
                                    )
                                );
                            }

                            if (count($flattenedVal) === 1) {
                                $output[$instance->getName()] = reset($flattenedVal);
                                continue;
                            }

                            if (count($flattenedVal) > 1) {
                                if ($instance->getFlatProperty() === null) {
                                    throw new \LogicException('$flatProperty cannot be null when there is more than one element to choose.');
                                }
                                $output[$instance->getName()] = $flattenedVal[$instance->getFlatProperty()];
                            }
                        }
                    }
                }
            }
        }

        return $output;
    }
}
