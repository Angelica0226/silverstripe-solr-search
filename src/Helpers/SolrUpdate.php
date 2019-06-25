<?php


namespace Firesphere\SolrSearch\Helpers;

use Exception;
use Firesphere\SolrSearch\Factories\DocumentFactory;
use Firesphere\SolrSearch\Indexes\BaseIndex;
use ReflectionClass;
use ReflectionException;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use Solarium\Core\Client\Response;
use Solarium\QueryType\Update\Query\Query;

class SolrUpdate
{
    public const DELETE_TYPE = 'delete';
    public const UPDATE_TYPE = 'update';
    public const CREATE_TYPE = 'create';

    protected $debug;

    /**
     * @todo use this helper in the IndexTask so it's not duplicated?
     * @param ArrayList|DataList|DataObject $items
     * @param string $type
     * @return bool|Response
     * @throws ReflectionException
     * @throws Exception
     */
    public function updateItems($items, $type)
    {
        $indexes = ClassInfo::subclassesFor(BaseIndex::class);
        $result = false;
        if ($items instanceof DataObject) {
            $items = ArrayList::create([$items]);
        }
        foreach ($indexes as $indexString) {
            // Skip the abstract base
            $ref = new ReflectionClass($indexString);
            if (!$ref->isInstantiable()) {
                continue;
            }

            /** @var BaseIndex $index */
            $index = Injector::inst()->get($indexString);
            // No point in sending a delete|update|create for something that's not in the index
            // @todo check the hierarchy, this could be a parent that should be indexed
            if (!in_array($items->first()->ClassName, $index->getClasses(), true)) {
                continue;
            }

            $client = $index->getClient();

            // get an update query instance
            $update = $client->createUpdate();

            // add the delete query and a commit command to the update query
            if ($type === static::DELETE_TYPE) {
                foreach ($items as $item) {
                    $update->addDeleteById(sprintf('%s-%s', $item->ClassName, $item->ID));
                }
            } elseif ($type === static::UPDATE_TYPE || $type === static::CREATE_TYPE) {
                $this->updateIndex($index, $items, $update);
            }
            $update->addCommit();
            $client->update($update);
        }
        gc_collect_cycles();

        return $result;
    }

    /**
     * @param BaseIndex $index
     * @param ArrayList|DataList $items
     * @param Query $update
     * @throws Exception
     */
    public function updateIndex($index, $items, $update): void
    {
        $fields = $index->getFieldsForIndexing();
        $count = 0;
        $factory = $this->getFactory($items);
        $docs = $factory->buildItems($fields, $index, $update, $count);
        if (count($docs)) {
            $update->addDocuments($docs);
        }
        // Does this clear out the memory properly?
        foreach ($docs as $doc) {
            unset($doc);
        }
    }

    /**
     * @param ArrayList|DataList $items
     * @return DocumentFactory
     */
    protected function getFactory($items): DocumentFactory
    {
        $factory = new DocumentFactory();
        $factory->setItems($items);
        $factory->setClass($items->first()->ClassName);
        $factory->setDebug($this->debug);

        return $factory;
    }

    /**
     * @param mixed $debug
     * @return SolrUpdate
     */
    public function setDebug($debug): SolrUpdate
    {
        $this->debug = $debug;

        return $this;
    }
}
