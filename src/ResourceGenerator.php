<?php

namespace Hal;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Hydrator\ExtractionInterface;

class ResourceGenerator
{
    /**
     * @var ContainerInterface Service locator for hydrators.
     */
    private $hydrators;

    /**
     * @var LinkGenerator Route-based link generation.
     */
    private $linkGenerator;

    /**
     * @var Metadata\MetadataMap Metadata on known objects.
     */
    private $metadataMap;

    /**
     * @var ResourceGenerator\Strategy[]
     */
    private $strategies = [];

    public function __construct(
        Metadata\MetadataMap $metadataMap,
        ContainerInterface $hydrators,
        LinkGenerator $linkGenerator
    ) {
        $this->metadataMap = $metadataMap;
        $this->hydrators = $hydrators;
        $this->linkGenerator = $linkGenerator;

        $this->addStrategy(
            Metadata\UrlBasedResourceMetadata::class,
            new ResourceGenerator\UrlBasedResourceStrategy()
        );
        $this->addStrategy(
            Metadata\RouteBasedResourceMetadata::class,
            new ResourceGenerator\RouteBasedResourceStrategy()
        );
    }

    /**
     * Link a metadata type to a strategy that can create a resource for it.
     *
     * @param string $metadataType
     * @param string|ResourceGenerator\Strategy $strategy
     */
    public function addStrategy(string $metadataType, $strategy) : void
    {
        if (! class_exists($metadataType)
            || ! in_array(Metadata\AbstractMetadata::class, class_parents($metadataType), true)
        ) {
            throw UnknownMetadataTypeException::forInvalidMetadataClass($metadataType);
        }

        if (is_string($strategy)
            && (
                ! class_exists($strategy)
                || ! in_array(ResourceGenerator\Strategy::class, class_implements($strategy), true)
            )
        ) {
            throw InvalidStrategyException::forType($strategy);
        }

        if (is_string($strategy)) {
            $strategy = new $strategy();
        }

        if (! $strategy instanceof ResourceGenerator\Strategy) {
            throw InvalidStrategyException::forInstance($strategy);
        }

        $this->strategies[$metadataType] = $strategy;
    }

    public function fromArray(array $data, string $uri = null) : Resource
    {
        $resource = new Resource($data);

        if (null !== $uri) {
            return $resource->withLink(new Link('self', $uri));
        }

        return $resource;
    }

    /**
     * @param object $instance An object of any type; the type will be checked
     *     against types registered in the metadata map.
     * @param ServerRequestInterface $request
     */
    public function fromObject($instance, ServerRequestInterface $request) : Resource
    {
        if (! is_object($instance)) {
            throw InvalidObjectException::forNonObject($instance);
        }

        $class = get_class($instance);
        if (! $this->metadataMap->has($class)) {
            throw InvalidObjectException::forUnknownType($class);
        }

        $metadata = $this->metadataMap->get($class);
        $metadataType = get_class($metadata);

        if (! isset($this->strategies[$metadataType])) {
            throw UnknownMetadataTypeException::forMetadata($metadata);
        }

        $strategy = $this->strategies[$metadataType];
        return $strategy->createResource(
            $instance,
            $metadata,
            $this->hydrators,
            $this->linkGenerator,
            $request
        );
    }
}