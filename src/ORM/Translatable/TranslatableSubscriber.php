<?php

declare(strict_types=1);

namespace Knp\DoctrineBehaviors\ORM\Translatable;

use Doctrine\DBAL\Platforms\PostgreSqlPlatform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Id\BigIntegerIdentityGenerator;
use Doctrine\ORM\Id\IdentityGenerator;
use Doctrine\ORM\Mapping\Builder\ClassMetadataBuilder;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\ORMException;
use Knp\DoctrineBehaviors\ORM\AbstractSubscriber;
use Knp\DoctrineBehaviors\Reflection\ClassAnalyzer;

class TranslatableSubscriber extends AbstractSubscriber
{
    private $currentLocaleCallable;

    private $defaultLocaleCallable;

    private $translatableTrait;

    private $translationTrait;

    private $translatableFetchMode;

    private $translationFetchMode;

    public function __construct(
        ClassAnalyzer $classAnalyzer,
        ?callable $currentLocaleCallable = null,
        ?callable $defaultLocaleCallable = null,
        $translatableTrait,
        $translationTrait,
        $translatableFetchMode,
        $translationFetchMode
    ) {
        parent::__construct($classAnalyzer, false);

        $this->currentLocaleCallable = $currentLocaleCallable;
        $this->defaultLocaleCallable = $defaultLocaleCallable;
        $this->translatableTrait = $translatableTrait;
        $this->translationTrait = $translationTrait;
        $this->translatableFetchMode = $this->convertFetchString($translatableFetchMode);
        $this->translationFetchMode = $this->convertFetchString($translationFetchMode);
    }

    /**
     * Adds mapping to the translatable and translations.
     *
     * @param LoadClassMetadataEventArgs $eventArgs The event arguments
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $eventArgs): void
    {
        $classMetadata = $eventArgs->getClassMetadata();

        if ($classMetadata->reflClass === null) {
            return;
        }

        if ($this->isTranslatable($classMetadata)) {
            $this->mapTranslatable($classMetadata);
        }

        if ($this->isTranslation($classMetadata)) {
            $this->mapTranslation($classMetadata);
            $this->mapId(
                $classMetadata,
                $eventArgs->getEntityManager()
            );
        }
    }

    public function postLoad(LifecycleEventArgs $eventArgs): void
    {
        $this->setLocales($eventArgs);
    }

    public function prePersist(LifecycleEventArgs $eventArgs): void
    {
        $this->setLocales($eventArgs);
    }

    /**
     * Returns hash of events, that this subscriber is bound to.
     *
     * @return array
     */
    public function getSubscribedEvents()
    {
        return [
            Events::loadClassMetadata,
            Events::postLoad,
            Events::prePersist,
        ];
    }

    /**
     * Kept for BC-compatibility purposes : people expect this lib to map ids for
     * translations.
     *
     * @deprecated It should be removed because it probably does not work with
     *             every doctrine version.
     *
     * @see https://github.com/doctrine/doctrine2/blob/0bff6aadbc9f3fd8167a320d9f4f6cf269382da0/lib/Doctrine/ORM/Mapping/ClassMetadataFactory.php#L508
     */
    private function mapId(ClassMetadata $class, EntityManager $em): void
    {
        $platform = $em->getConnection()->getDatabasePlatform();
        if (! $class->hasField('id')) {
            $builder = new ClassMetadataBuilder($class);
            $builder->createField('id', 'integer')->isPrimaryKey()->generatedValue()->build();
            /// START DOCTRINE CODE
            $idGenType = $class->generatorType;
            if ($idGenType === ClassMetadata::GENERATOR_TYPE_AUTO) {
                if ($platform->prefersSequences()) {
                    $class->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_SEQUENCE);
                } elseif ($platform->prefersIdentityColumns()) {
                    $class->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_IDENTITY);
                } else {
                    $class->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_TABLE);
                }
            }

            // Create & assign an appropriate ID generator instance
            switch ($class->generatorType) {
            case ClassMetadata::GENERATOR_TYPE_IDENTITY:
                // For PostgreSQL IDENTITY (SERIAL) we need a sequence name. It defaults to
                // <table>_<column>_seq in PostgreSQL for SERIAL columns.
                // Not pretty but necessary and the simplest solution that currently works.
                $sequenceName = null;
                $fieldName = $class->identifier ? $class->getSingleIdentifierFieldName() : null;

                if ($platform instanceof PostgreSqlPlatform) {
                    $columnName = $class->getSingleIdentifierColumnName();
                    $quoted = isset($class->fieldMappings[$fieldName]['quoted']) || isset($class->table['quoted']);
                    $sequenceName = $class->getTableName() . '_' . $columnName . '_seq';
                    $definition = [
                        'sequenceName' => $platform->fixSchemaElementName($sequenceName),
                    ];

                    if ($quoted) {
                        $definition['quoted'] = true;
                    }

                    $sequenceName = $em->getConfiguration()->getQuoteStrategy()->getSequenceName($definition, $class, $platform);
                }

                $generator = $fieldName && $class->fieldMappings[$fieldName]['type'] === 'bigint'
                    ? new BigIntegerIdentityGenerator($sequenceName)
                    : new IdentityGenerator($sequenceName);

                $class->setIdGenerator($generator);

                break;

            case ClassMetadata::GENERATOR_TYPE_SEQUENCE:
                // If there is no sequence definition yet, create a default definition
                $definition = $class->sequenceGeneratorDefinition;

                if (! $definition) {
                    $fieldName = $class->getSingleIdentifierFieldName();
                    $columnName = $class->getSingleIdentifierColumnName();
                    $quoted = isset($class->fieldMappings[$fieldName]['quoted']) || isset($class->table['quoted']);
                    $sequenceName = $class->getTableName() . '_' . $columnName . '_seq';
                    $definition = [
                        'sequenceName' => $platform->fixSchemaElementName($sequenceName),
                        'allocationSize' => 1,
                        'initialValue' => 1,
                    ];

                    if ($quoted) {
                        $definition['quoted'] = true;
                    }

                    $class->setSequenceGeneratorDefinition($definition);
                }

                $sequenceGenerator = new \Doctrine\ORM\Id\SequenceGenerator(
                    $em->getConfiguration()->getQuoteStrategy()->getSequenceName($definition, $class, $platform),
                    $definition['allocationSize']
                );
                $class->setIdGenerator($sequenceGenerator);
                break;

            case ClassMetadata::GENERATOR_TYPE_NONE:
                $class->setIdGenerator(new \Doctrine\ORM\Id\AssignedGenerator());
                break;

            case ClassMetadata::GENERATOR_TYPE_UUID:
                $class->setIdGenerator(new \Doctrine\ORM\Id\UuidGenerator());
                break;

            case ClassMetadata::GENERATOR_TYPE_TABLE:
                throw new ORMException('TableGenerator not yet implemented.');
                break;

            case ClassMetadata::GENERATOR_TYPE_CUSTOM:
                $definition = $class->customGeneratorDefinition;
                if (! class_exists($definition['class'])) {
                    throw new ORMException("Can't instantiate custom generator : " .
                        $definition['class']);
                }
                $class->setIdGenerator(new $definition['class']());
                break;

            default:
                throw new ORMException('Unknown generator type: ' . $class->generatorType);
            }
            /// END DOCTRINE COPY / PASTED code
        }
    }

    private function mapTranslatable(ClassMetadata $classMetadata): void
    {
        if (! $classMetadata->hasAssociation('translations')) {
            $classMetadata->mapOneToMany([
                'fieldName' => 'translations',
                'mappedBy' => 'translatable',
                'indexBy' => 'locale',
                'cascade' => ['persist', 'merge', 'remove'],
                'fetch' => $this->translatableFetchMode,
                'targetEntity' => $classMetadata->getReflectionClass()->getMethod('getTranslationEntityClass')->invoke(null),
                'orphanRemoval' => true,
            ]);
        }
    }

    private function mapTranslation(ClassMetadata $classMetadata): void
    {
        if (! $classMetadata->hasAssociation('translatable')) {
            $classMetadata->mapManyToOne([
                'fieldName' => 'translatable',
                'inversedBy' => 'translations',
                'cascade' => ['persist', 'merge'],
                'fetch' => $this->translationFetchMode,
                'joinColumns' => [[
                    'name' => 'translatable_id',
                    'referencedColumnName' => 'id',
                    'onDelete' => 'CASCADE',
                ]],
                'targetEntity' => $classMetadata->getReflectionClass()->getMethod('getTranslatableEntityClass')->invoke(null),
            ]);
        }

        $name = $classMetadata->getTableName() . '_unique_translation';
        if (! $this->hasUniqueTranslationConstraint($classMetadata, $name)) {
            $classMetadata->table['uniqueConstraints'][$name] = [
                'columns' => ['translatable_id', 'locale'],
            ];
        }

        if (! ($classMetadata->hasField('locale') || $classMetadata->hasAssociation('locale'))) {
            $classMetadata->mapField([
                'fieldName' => 'locale',
                'type' => 'string',
                'length' => 5,
            ]);
        }
    }

    /**
     * Convert string FETCH mode to required string
     *
     * @param $fetchMode
     *
     * @return int
     */
    private function convertFetchString($fetchMode)
    {
        if (is_int($fetchMode)) {
            return $fetchMode;
        }

        switch ($fetchMode) {
            case 'LAZY':
                return ClassMetadataInfo::FETCH_LAZY;
            case 'EAGER':
                return ClassMetadataInfo::FETCH_EAGER;
            case 'EXTRA_LAZY':
                return ClassMetadataInfo::FETCH_EXTRA_LAZY;
            default:
                return ClassMetadataInfo::FETCH_LAZY;
        }
    }

    private function hasUniqueTranslationConstraint(ClassMetadata $classMetadata, $name)
    {
        if (! isset($classMetadata->table['uniqueConstraints'])) {
            return;
        }

        return isset($classMetadata->table['uniqueConstraints'][$name]);
    }

    /**
     * Checks if entity is translatable
     *
     * @return boolean
     */
    private function isTranslatable(ClassMetadata $classMetadata)
    {
        return $this->getClassAnalyzer()->hasTrait($classMetadata->reflClass, $this->translatableTrait);
    }

    /**
     * Checks if entity is a translation
     *
     * @return boolean
     */
    private function isTranslation(ClassMetadata $classMetadata)
    {
        return $this->getClassAnalyzer()->hasTrait($classMetadata->reflClass, $this->translationTrait);
    }

    private function setLocales(LifecycleEventArgs $eventArgs): void
    {
        $em = $eventArgs->getEntityManager();
        $entity = $eventArgs->getEntity();
        $classMetadata = $em->getClassMetadata(get_class($entity));

        if (! $this->isTranslatable($classMetadata)) {
            return;
        }

        $currentLocale = $this->getCurrentLocale();
        if ($currentLocale) {
            $entity->setCurrentLocale($currentLocale);
        }

        $defaultLocale = $this->getDefaultLocale();
        if ($defaultLocale) {
            $entity->setDefaultLocale($defaultLocale);
        }
    }

    private function getCurrentLocale()
    {
        $currentLocaleCallable = $this->currentLocaleCallable;

        if ($currentLocaleCallable) {
            return $currentLocaleCallable();
        }
    }

    private function getDefaultLocale()
    {
        $defaultLocaleCallable = $this->defaultLocaleCallable;

        if ($defaultLocaleCallable) {
            return $defaultLocaleCallable();
        }
    }
}
