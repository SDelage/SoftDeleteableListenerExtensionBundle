<?php

namespace Evence\Bundle\SoftDeleteableExtensionBundle\EventListener;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Evence\Bundle\SoftDeleteableExtensionBundle\Exception\OnSoftDeleteUnknownTypeException;
use Evence\Bundle\SoftDeleteableExtensionBundle\Mapping\Annotation\onSoftDelete;
use Gedmo\Mapping\ExtensionMetadataFactory;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Soft delete listener class for onSoftDelete behaviour.
 *
 * @author Ruben Harms <info@rubenharms.nl>
 *
 * @link http://www.rubenharms.nl
 * @link https://www.github.com/RubenHarms
 */
class SoftDeleteListener
{
    use ContainerAwareTrait;

    /**
     * @param LifecycleEventArgs $args
     *
     * @throws OnSoftDeleteUnknownTypeException
     */
    public function preSoftDelete(LifecycleEventArgs $args)
    {
        $em = $args->getEntityManager();
        $uow = $em->getUnitOfWork();
        $entity = $args->getEntity();

        $entityReflection = new \ReflectionObject($entity);

        $namespaces = $em->getConfiguration()
            ->getMetadataDriverImpl()
            ->getAllClassNames();

        $reader = new AnnotationReader();
        foreach ($namespaces as $namespace) {
            $reflectionClass = new \ReflectionClass($namespace);
            if ($reflectionClass->isAbstract()) {
                continue;
            }

            $meta = $em->getClassMetadata($namespace);
            foreach ($reflectionClass->getProperties() as $property) {
                if ($onDelete = $reader->getPropertyAnnotation($property, 'Evence\Bundle\SoftDeleteableExtensionBundle\Mapping\Annotation\onSoftDelete')) {
                    $objects = null;
                    $manyToMany = null;
                    $manyToOne = null;
                    $oneToOne = null;
                    if (
                        ($manyToOne = $reader->getPropertyAnnotation($property, 'Doctrine\ORM\Mapping\ManyToOne')) ||
                        ($manyToMany = $reader->getPropertyAnnotation($property, 'Doctrine\ORM\Mapping\ManyToMany')) ||
                        ($oneToOne = $reader->getPropertyAnnotation($property, 'Doctrine\ORM\Mapping\OneToOne'))
                    ) {

                        $relationship = $manyToOne ?: $manyToMany ?: $oneToOne;

                        $ns = null;
                        $nsOriginal = $relationship->targetEntity;
                        $nsFromRelativeToAbsolute = $entityReflection->getNamespaceName().'\\'.$relationship->targetEntity;
                        $nsFromRoot = '\\'.$relationship->targetEntity;
                        if(class_exists($nsOriginal)){
                           $ns = $nsOriginal;
                        }
                        elseif(class_exists($nsFromRoot)){
                          $ns = $nsFromRoot;
                        }
                        elseif(class_exists($nsFromRelativeToAbsolute)){
                           $ns = $nsFromRelativeToAbsolute;
                        }

                        if (($manyToOne || $oneToOne) && $ns && $entity instanceof $ns) {
                            $objects = $em->getRepository($namespace)->findBy(array(
                                $property->name => $entity,
                            ));
                        }
                        elseif($manyToMany) {

                            // For ManyToMany relations, we only delete the relationship between
                            // two entities. This can be done on both side of the relation.
                            $allowMappedSide = get_class($entity) === $namespace;
                            $allowInversedSide = ($ns && $entity instanceof $ns);
                            if ($allowMappedSide || $allowInversedSide) {

                                if (strtoupper($onDelete->type) === 'SET NULL') {
                                    throw new \Exception('SET NULL is not supported for ManyToMany relationships');
                                }

                                try {
                                    $propertyAccessor = PropertyAccess::createPropertyAccessor();
                                    $collection = $propertyAccessor->getValue($entity, $property->name);
                                    $collection->clear();
                                    continue;
                                } catch (\Exception $e) {
                                    throw new \Exception(sprintf('No accessor found for %s in %s', $property->name, get_class($entity)));
                                }
                            }
                        }
                    }

                    if ($objects) {
                        $factory = $em->getMetadataFactory();
                        $cacheDriver = $factory->getCacheDriver();
                        $cacheId = ExtensionMetadataFactory::getCacheId($namespace, 'Gedmo\SoftDeleteable');
                        $softDelete = false;
                        if (($config = $cacheDriver->fetch($cacheId)) !== false) {
                            $softDelete = isset($config['softDeleteable']) && $config['softDeleteable'];
                        }
                        foreach ($objects as $object) {
                            if (strtoupper($onDelete->type) === 'SET NULL') {
                                $reflProp = $meta->getReflectionProperty($property->name);
                                $oldValue = $reflProp->getValue($object);

                                $reflProp->setValue($object, null);
                                $em->persist($object);

                                $uow->propertyChanged($object, $property->name, $oldValue, null);
                                $uow->scheduleExtraUpdate($object, array(
                                    $property->name => array($oldValue, null),
                                ));
                            } elseif (strtoupper($onDelete->type) === 'CASCADE') {
                                if ($softDelete) {
                                    $this->softDeleteCascade($em, $config, $object);
                                } else {
                                    $em->remove($object);
                                }
                            } else {
                                throw new OnSoftDeleteUnknownTypeException($onDelete->type);
                            }
                        }
                    }
                }
            }
        }
    }

    protected function softDeleteCascade($em, $config, $object)
    {
        $meta = $em->getClassMetadata(get_class($object));
        $reflProp = $meta->getReflectionProperty($config['fieldName']);
        $oldValue = $reflProp->getValue($object);
        if ($oldValue instanceof \Datetime) {
            return;
        }

        //check next level
        $args = new LifecycleEventArgs($object, $em);
        $this->preSoftDelete($args);

        $date = new \DateTime();
        $reflProp->setValue($object, $date);

        $uow = $em->getUnitOfWork();
        $uow->propertyChanged($object, $config['fieldName'], $oldValue, $date);
        $uow->scheduleExtraUpdate($object, array(
            $config['fieldName'] => array($oldValue, $date),
        ));
    }
}