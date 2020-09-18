<?php
namespace Neos\Neos\Ui\Service;

/*
 * This file is part of the Neos.Neos.Ui package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Security\Authorization\Privilege\Node\CreateNodePrivilege;
use Neos\ContentRepository\Security\Authorization\Privilege\Node\CreateNodePrivilegeSubject;
use Neos\ContentRepository\Security\Authorization\Privilege\Node\EditNodePrivilege;
use Neos\ContentRepository\Security\Authorization\Privilege\Node\EditNodePropertyPrivilege;
use Neos\ContentRepository\Security\Authorization\Privilege\Node\NodePrivilegeSubject;
use Neos\ContentRepository\Security\Authorization\Privilege\Node\PropertyAwareNodePrivilegeSubject;
use Neos\ContentRepository\Security\Authorization\Privilege\Node\RemoveNodePrivilege;
use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Security\Authorization\Privilege\PrivilegeInterface;
use Neos\Flow\Security\Authorization\PrivilegeManagerInterface;
use Neos\Flow\Security\Policy\PolicyService;
use Neos\Neos\Security\Authorization\Privilege\NodeTreePrivilege;

/**
 * @Flow\Scope("singleton")
 */
class NodePolicyService
{

    /**
     * @Flow\Inject
     * @var PrivilegeManagerInterface
     */
    protected $privilegeManager;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @param ObjectManagerInterface $objectManager
     * @return array the key is a Privilege class name; the value is "true" if privileges are configured for this class name.
     * @Flow\CompileStatic
     */
    public static function getUsedPrivilegeClassNames(ObjectManagerInterface $objectManager): array
    {
        $policyService = $objectManager->get(PolicyService::class);
        $usedPrivilegeClassNames = [];
        foreach ($policyService->getPrivilegeTargets() as $privilegeTarget) {
            $usedPrivilegeClassNames[$privilegeTarget->getPrivilegeClassName()] = true;
            foreach (class_parents($privilegeTarget->getPrivilegeClassName()) as $parentPrivilege) {
                if (is_a($parentPrivilege, PrivilegeInterface::class, true)) {
                    $usedPrivilegeClassNames[$parentPrivilege] = true;
                }
            }
        }

        return $usedPrivilegeClassNames;
    }

    /**
     * @param NodeInterface $node
     * @return array
     */
    public function getNodePolicyInformation(NodeInterface $node): array
    {
        return [
            'disallowedNodeTypes' => $this->getDisallowedNodeTypes($node),
            'canRemove' => $this->canRemoveNode($node),
            'canEdit' => $this->canEditNode($node),
            'disallowedProperties' => $this->getDisallowedProperties($node)
        ];
    }

    /**
     * @param NodeInterface $node
     * @return bool
     */
    public function isNodeTreePrivilegeGranted(NodeInterface $node): bool
    {
        if (!isset(self::getUsedPrivilegeClassNames($this->objectManager)[NodeTreePrivilege::class])) {
            return true;
        }

        return $this->privilegeManager->isGranted(
            NodeTreePrivilege::class,
            new NodePrivilegeSubject($node)
        );
    }

    /**
     * @param NodeInterface $node
     * @return array
     */
    public function getDisallowedNodeTypes(NodeInterface $node): array
    {
        $disallowedNodeTypes = [];

        if (!isset(self::getUsedPrivilegeClassNames($this->objectManager)[CreateNodePrivilege::class])) {
            return $disallowedNodeTypes;
        }

        // determine the set of configured node supertypes
        $nodeNodeType = $node->getNodeType();

        $superTypes = [];
        $generateSuperTypes = function(array $nodeTypes, &$superTypes) use (&$generateSuperTypes) {
            foreach ($nodeTypes as $nodeType) {
                $superTypes[$nodeType->getName()] = $nodeType;
                $generateSuperTypes($nodeType->getDeclaredSuperTypes(), $superTypes);
            }
        };
        $generateSuperTypes($nodeNodeType->getDeclaredSuperTypes(), $superTypes);

        $parentNode     = $node->getParent();
        $parentNodeType = $parentNode->getNodeType();

        // check if the node is auto-created
        $isAutCreated   = false;
        if ($parentNodeType && array_key_exists((string)$node->getNodeName(), $parentNodeType->getAutoCreatedChildNodes())) {
            $isAutCreated = true;
        }

        // filter the set of configured nodeTypes
        $nodeName = (string)$node->getNodeName();

        $constraintAndSuperTypeFilter = function ($nodeType) use ($nodeName, $nodeNodeType, $superTypes, $isAutCreated, $parentNodeType) {
            // check if the nodeType is mentioned in the constraints
            if ($isAutCreated) {
                if ($parentNodeType && $parentNodeType->allowsGrandchildNodeType($nodeName, $nodeType)) {
                    return true;
                }
            }
            else if ($nodeNodeType->allowsChildNodeType($nodeType)) {
                return true;
            }
            // check if the nodeType is a supertype
            else if (isset($superTypes[$nodeType->getName()])) {
                return true;
            }
            // check if the nodeType is the same as that of the current $node's nodeType
            else if ($nodeType->getName() == $nodeNodeType->getName()) {
                return true;
            }

            // ignore other nodeType
            return false;
        };

        $nodeTypes = array_filter($this->nodeTypeManager->getNodeTypes(), $constraintAndSuperTypeFilter);

        // filter the remaining nodeTypes via policy check
        $filter = function ($nodeType) use ($node) {
            return !$this->privilegeManager->isGranted(
                CreateNodePrivilege::class,
                new CreateNodePrivilegeSubject($node, $nodeType)
            );
        };

        $disallowedNodeTypeObjects = array_filter($nodeTypes, $filter);

        $mapper = function ($nodeType) {
            return $nodeType->getName();
        };

        return array_values(array_map($mapper, $disallowedNodeTypeObjects));
    }

    /**
     * @param NodeInterface $node
     * @return bool
     */
    public function canRemoveNode(NodeInterface $node): bool
    {
        $canRemove = true;
        if (isset(self::getUsedPrivilegeClassNames($this->objectManager)[RemoveNodePrivilege::class])) {
            $canRemove = $this->privilegeManager->isGranted(RemoveNodePrivilege::class, new NodePrivilegeSubject($node));
        }

        return $canRemove;
    }

    /**
     * @param NodeInterface $node
     * @return bool
     */
    public function canEditNode(NodeInterface $node): bool
    {
        $canEdit = true;
        if (isset(self::getUsedPrivilegeClassNames($this->objectManager)[EditNodePrivilege::class])) {
            $canEdit = $this->privilegeManager->isGranted(EditNodePrivilege::class, new NodePrivilegeSubject($node));
        }

        return $canEdit;
    }

    /**
     * @param NodeInterface $node
     * @return array
     */
    public function getDisallowedProperties(NodeInterface $node): array
    {
        $disallowedProperties = [];

        if (!isset(self::getUsedPrivilegeClassNames($this->objectManager)[EditNodePropertyPrivilege::class])) {
            return $disallowedProperties;
        }

        $filter = function ($propertyName) use ($node) {
            return !$this->privilegeManager->isGranted(
                EditNodePropertyPrivilege::class,
                new PropertyAwareNodePrivilegeSubject($node, null, $propertyName)
            );
        };

        $disallowedProperties = array_filter(array_keys($node->getNodeType()->getProperties()), $filter);
        return $disallowedProperties;
    }
}
