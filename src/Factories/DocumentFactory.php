<?php


namespace Firesphere\SolrSearch\Factories;

use Exception;
use Firesphere\SolrSearch\Extensions\DataObjectExtension;
use Firesphere\SolrSearch\Helpers\SearchIntrospection;
use Firesphere\SolrSearch\Helpers\Statics;
use Firesphere\SolrSearch\Indexes\BaseIndex;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\Debug;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\SS_List;
use Solarium\QueryType\Update\Query\Document\Document;
use Solarium\QueryType\Update\Query\Query;

class DocumentFactory
{
    use Configurable;

    /**
     * @var SearchIntrospection
     */
    protected $introspection;

    /**
     * @var null|ArrayList|DataList
     */
    protected $items;

    /**
     * @var string
     */
    protected $class;

    /**
     * @var bool
     */
    protected $debug = false;

    /**
     * DocumentFactory constructor, sets up introspection
     */
    public function __construct()
    {
        $this->introspection = Injector::inst()->get(SearchIntrospection::class);
    }

    /**
     * Note, it can only take one type of class at a time!
     * So make sure you properly loop and set $class
     * @param $fields
     * @param BaseIndex $index
     * @param Query $update
     * @return array
     * @throws Exception
     */
    public function buildItems($fields, $index, $update): array
    {
        $class = $this->class;
        $this->introspection->setIndex($index);
        $docs = [];

        $debugString = sprintf('Adding %s to %s%s', $class, $index->getIndexName(), PHP_EOL);
        if ($this->debug) {
            $debugString .= '[';
        }
        $boostFields = $index->getBoostedFields();
        foreach ($this->items as $item) {
            if ($this->debug) {
                $debugString .= "$item->ID, ";
            }
            /** @var Document $doc */
            $doc = $update->createDocument();
            $this->addDefaultFields($doc, $item);

            $this->buildField($fields, $doc, $item, $boostFields);
            $item->destroy();

            $docs[] = $doc;
        }

        if ($this->debug) {
            Debug::message(rtrim($debugString, ', ') . ']' . PHP_EOL, false);
        }

        unset($this->items);

        return $docs;
    }

    /**
     * @param Document $doc
     * @param DataObject|DataObjectExtension $item
     */
    protected function addDefaultFields(Document $doc, DataObject $item)
    {
        $doc->setKey('_documentid', $item->ClassName . '-' . $item->ID);
        $doc->addField('ID', $item->ID);
        $doc->addField('ClassName', $item->ClassName);
        $doc->addField('ClassHierarchy', ClassInfo::ancestry($item));
        $doc->addField('ViewStatus', $item->getViewStatus());
    }

    /**
     * @param $fields
     * @param Document $doc
     * @param DataObject $item
     * @param array $boostFields
     * @throws Exception
     */
    protected function buildField($fields, Document $doc, DataObject $item, array $boostFields): void
    {
        foreach ($fields as $field) {
            $fieldData = $this->introspection->getFieldIntrospection($field);
            foreach ($fieldData as $dataField => $options) {
                // Only one field per class, so let's take the fieldData. This will override previous additions
                $this->addField($doc, $item, $fieldData[$dataField]);
                if (array_key_exists($field, $boostFields)) {
                    $doc->setFieldBoost($dataField, $boostFields[$field]);
                }
            }
            unset($field);
            gc_collect_cycles();
        }
    }

    /**
     * @param Document $doc
     * @param $object
     * @param $field
     */
    protected function addField($doc, $object, $field): void
    {
        $typeMap = Statics::getTypeMap();
        if (!$this->classIs($object, $field['origin'])) {
            return;
        }

        $value = $this->getValueForField($object, $field);

        $type = $typeMap[$field['type']] ?? $typeMap['*'];

        while ($item = array_shift($value)) {
            /* Solr requires dates in the form 1995-12-31T23:59:59Z */
            if ($type === 'tdate' || $item instanceof DBDate) {
                if (!$item) {
                    continue;
                }
                $item = gmdate('Y-m-d\TH:i:s\Z', strtotime($item));
            }

            /* Solr requires numbers to be valid if presented, not just empty */
            if (($type === 'tint' || $type === 'tfloat' || $type === 'tdouble') && !is_numeric($item)) {
                continue;
            }

            $name = explode('\\', $field['name']);
            $name = end($name);

            $doc->addField($name, $item);
        }
        unset($item, $value, $type);
        gc_collect_cycles();
    }

    /**
     * Determine if the given object is one of the given type
     * @param string|array $class
     * @param array|string $base Class or list of base classes
     * @return bool
     * @todo copy-paste, needs refactoring
     * @todo This can be handled by PHP built-in Class determination, e.g. InstanceOf
     */
    protected function classIs($class, $base): bool
    {
        if (is_array($base)) {
            foreach ($base as $nextBase) {
                if ($this->classIs($class, $nextBase)) {
                    return true;
                }
            }

            return false;
        }

        // Check single origin
        return $class === $base || ($class instanceof $base);
    }

    /**
     * Given an object and a field definition get the current value of that field on that object
     *
     * @param DataObject|array|SS_List|DataObject $objects - The object to get the value from
     * @param array $field - The field definition to use
     * @return array|string|null - The value of the field, or null if we couldn't look it up for some reason
     * @todo reduced the array_merge need to something more effective
     */
    protected function getValueForField($objects, $field)
    {
        if (!is_array($objects)) {
            $objects = [$objects];
        }

        while ($step = array_shift($field['lookup_chain'])) {
            // If we're looking up this step on an array or SS_List, do the step on every item, merge result
            $next = [];

            foreach ($objects as $item) {
                $item = $this->getItemForStep($step, $item);

                if (is_array($item)) {
                    // @todo remove the merge, it's inefficient
                    $next = array_merge($next, $item);
                } else {
                    $next[] = $item;
                }

                // To lower memory footprint, if the item is a DO, destroy it after use
                if ($item instanceof DataObject) {
                    $item->destroy();
                }
            }

            $objects = $next;
        }

        return $objects;
    }

    /**
     * Find the item for the current ste
     * This can be a DataList or ArrayList, or a string
     * @param $step
     * @param $item
     * @return array
     */
    protected function getItemForStep($step, $item): array
    {
        if ($step['call'] === 'method') {
            $method = $step['method'];
            $item = $item->$method();
        } else {
            $property = $step['property'];
            $item = $item->$property;
        }

        if ($item instanceof SS_List) {
            $item = $item->toArray();
        }

        return $item;
    }

    /**
     * @return SearchIntrospection
     */
    public function getIntrospection(): SearchIntrospection
    {
        return $this->introspection;
    }

    /**
     * @param ArrayList|DataList|null $items
     * @return DocumentFactory
     */
    public function setItems($items): DocumentFactory
    {
        $this->items = $items;

        return $this;
    }

    /**
     * @param bool $debug
     * @return DocumentFactory
     */
    public function setDebug(bool $debug): DocumentFactory
    {
        $this->debug = $debug;

        return $this;
    }

    /**
     * @param string $class
     * @return DocumentFactory
     */
    public function setClass(string $class): DocumentFactory
    {
        $this->class = $class;

        return $this;
    }
}
