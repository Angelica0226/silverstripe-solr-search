<?php


namespace Firesphere\SearchConfig\Indexes;

use Firesphere\SearchConfig\Interfaces\ConfigStore;
use Firesphere\SearchConfig\Queries\BaseQuery;
use Firesphere\SearchConfig\Results\SearchResult;
use Firesphere\SearchConfig\Services\SchemaService;
use Firesphere\SearchConfig\Services\SolrCoreService;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\Debug;
use SilverStripe\Dev\Deprecation;
use SilverStripe\ORM\ArrayList;
use SilverStripe\SiteConfig\SiteConfig;
use Solarium\Core\Client\Client;
use Solarium\QueryType\Select\Query\Query;
use Solarium\QueryType\Select\Result\Result;

abstract class BaseIndex
{
    /**
     * @var array
     */
    protected $class = [];

    /**
     * @var array
     */
    protected $fulltextFields = [];

    /**
     * @var array
     */
    protected $boostedFields = [];

    /**
     * @var array
     */
    protected $filterFields = [];

    /**
     * @var array
     */
    protected $sortFields = [];

    /**
     * @var array
     */
    protected $facetFields = [];

    /**
     * @var array
     */
    protected $copyFields = [
        '_text' => [
            '*'
        ],
    ];

    protected $defaultField = '_text';

    /**
     * @var SchemaService
     */
    protected $schemaService;

    public function __construct()
    {
        $this->schemaService = Injector::inst()->get(SchemaService::class);
        $this->schemaService->setIndex($this);
        $this->schemaService->setStore(Director::isDev());

        $this->init();
    }

    /**
     * Stub for backward compatibility
     * Required to initialise the fields if not from config.
     * @return mixed
     * @todo work from config first
     */
    abstract public function init();

    /**
     * @param BaseQuery $query
     * @return SearchResult|Result
     */
    public function doSearch($query)
    {
        // @todo make filterQuerySelects instead of a single query

        $config = Config::inst()->get(SolrCoreService::class, 'config');
        $config['endpoint'] = $this->getConfig($config['endpoint']);
        $client = new Client($config);

        // Build the actual query parameters
        $clientQuery = $this->buildSolrQuery($query, $client);
        // Build class filtering
        $this->buildClassFilter($query, $clientQuery);
        // Add highlighting
        $clientQuery->getHighlighting()->setFields($query->getHighlight());
        // Setup the facets
        $this->buildFacets($query, $clientQuery);

        $result = $client->select($clientQuery);

        $result = new SearchResult($result, $query);

        return $result;
    }

    /**
     * @param BaseQuery $query
     * @param Client $client
     * @return Query
     */
    protected function buildSolrQuery($query, $client)
    {
        $clientQuery = $client->createSelect();

        $q = [];
        foreach ($query->getTerms() as $search) {
            $q[] = $search['text'];
        }

        $clientQuery->setQuery(implode(' ', $q));

        foreach ($query->getFields() as $field => $value) {
            $clientQuery->createFilterQuery($field)->setQuery($field . ':' . $value);
        }

        return $clientQuery;
    }

    /**
     * Add filtered queries based on class hierarchy
     * @param BaseQuery $query
     * @param Query $clientQuery
     * @return Query
     */
    public function buildClassFilter($query, $clientQuery)
    {
        foreach ($query->getClasses() as $class) {
            $clientQuery->createFilterQuery('classes')
                ->setQuery('ClassHierarchy:' . str_replace('\\', '\\\\', $class));
        }

        return $clientQuery;
    }

    /**
     * Upload config for this index to the given store
     *
     * @param ConfigStore $store
     */
    public function uploadConfig($store)
    {
        // @todo use types/schema/elevate rendering
        // Upload the config files for this index
        // Create a default schema which we can manage later
        $schema = (string)$this->schemaService->generateSchema();
        $store->uploadString(
            $this->getIndexName(),
            'schema.xml',
            $schema
        );

        // Upload additional files
        foreach (glob($this->schemaService->getExtrasPath() . '/*') as $file) {
            if (is_file($file)) {
                $store->uploadFile($this->getIndexName(), $file);
            }
        }

        $synonyms = $this->getSynonyms();

        $store->uploadString(
            $this->getIndexName(),
            'synonyms.txt',
            $synonyms
        );
    }

    /**
     * Add synonyms. Public to be extendable
     * @return string
     */
    public function getSynonyms()
    {
        return SiteConfig::current_site_config()->SearchSynonyms;
    }

    /**
     * @return string
     */
    abstract public function getIndexName();

    /**
     * Build a full config for all given endpoints
     * This is to add the current index to e.g. an index or select
     * @param array $endpoints
     * @return array
     */
    public function getConfig($endpoints)
    {
        foreach ($endpoints as $host => $endpoint) {
            $endpoints[$host]['core'] = $this->getIndexName();
        }

        return $endpoints;
    }

    /**
     * $options is not used anymore, added for backward compatibility
     * @param $class
     * @param array $options
     * @return $this
     */
    public function addClass($class, $options = array())
    {
        if (count($options)) {
            Deprecation::notice('5', 'Options are not used anymore');
        }
        $this->class[] = $class;

        return $this;
    }

    /**
     * @param $filterField
     * @return $this
     */
    public function addFilterField($filterField)
    {
        $this->filterFields[] = $filterField;

        return $this;
    }

    /**
     * Extra options is not used, it's here for backward compatibility
     * @param $field
     * @param array $extraOptions
     * @param int $boost
     * @return $this
     */
    public function addBoostedField($field, $extraOptions = [], $boost = 2)
    {
        if (!in_array($field, $this->getFulltextFields(), true)) {
            $this->addFulltextField($field);
        }

        $boostedFields = $this->getBoostedFields();
        $boostedFields[$field] = $boost;
        $this->setBoostedFields($boostedFields);

        return $this;
    }

    /**
     * @return array
     */
    public function getFulltextFields()
    {
        return $this->fulltextFields;
    }

    /**
     * @param array $fulltextFields
     * @return $this
     */
    public function setFulltextFields($fulltextFields)
    {
        $this->fulltextFields = $fulltextFields;

        return $this;
    }

    /**
     * @param string $fulltextField
     * @return $this
     */
    public function addFulltextField($fulltextField)
    {
        $this->fulltextFields[] = $fulltextField;

        return $this;
    }

    /**
     * @return array
     */
    public function getBoostedFields()
    {
        return $this->boostedFields;
    }

    /**
     * @param array $boostedFields
     * @return $this
     */
    public function setBoostedFields($boostedFields)
    {
        $this->boostedFields = $boostedFields;

        return $this;
    }

    /**
     * @param $sortField
     * @return $this
     */
    public function addSortField($sortField)
    {
        $this->addFulltextField($sortField);

        $this->sortFields[] = $sortField;

        $this->setSortFields(array_unique($this->getSortFields()));

        return $this;
    }

    /**
     * @return array
     */
    public function getSortFields()
    {
        return $this->sortFields;
    }

    /**
     * @param array $sortFields
     * @return BaseIndex
     */
    public function setSortFields($sortFields)
    {
        $this->sortFields = $sortFields;

        return $this;
    }

    /**
     * @param $field
     * @return $this
     */
    public function addFacetField($field, $options)
    {
        $this->facetFields[] = $field;

        if (!in_array($field, $this->getFilterFields(), true)) {
            $this->addFilterField($field);
        }

        return $this;
    }

    /**
     * @param string $field Name of the copyfield
     * @param array $options Array of all fields that should be copied to this copyfield
     * @return $this
     */
    public function addCopyField($field, $options)
    {
        $this->copyFields[$field] = $options;

        if (!in_array($field, $this->getFulltextFields(), true)) {
            $this->addFulltextField($field);
        }

        return $this;
    }

    /**
     * @return array
     */
    public function getFilterFields()
    {
        return $this->filterFields;
    }

    /**
     * @param array $filterFields
     * @return $this
     */
    public function setFilterFields($filterFields)
    {
        $this->filterFields = $filterFields;

        return $this;
    }

    /**
     * @return array
     */
    public function getClass()
    {
        return $this->class;
    }

    /**
     * @param array $class
     * @return BaseIndex
     */
    public function setClass($class)
    {
        $this->class = $class;

        return $this;
    }

    /**
     * @return array
     */
    public function getFacetFields()
    {
        return $this->facetFields;
    }

    /**
     * @param array $facetFields
     * @return BaseIndex
     */
    public function setFacetFields($facetFields)
    {
        $this->facetFields = $facetFields;

        return $this;
    }

    /**
     * @return array
     */
    public function getCopyFields()
    {
        return $this->copyFields;
    }

    /**
     * @param array $copyField
     * @return $this
     */
    public function setCopyFields($copyField)
    {
        $this->copyFields = $copyField;

        return $this;
    }

    /**
     * @return string
     */
    public function getDefaultField()
    {
        return $this->defaultField;
    }

    /**
     * @param string $defaultField
     * @return BaseIndex
     */
    public function setDefaultField($defaultField)
    {
        $this->defaultField = $defaultField;

        return $this;
    }

    /**
     * @param $query
     * @param Query $clientQuery
     */
    protected function buildFacets($query, Query $clientQuery)
    {
        $facets = $clientQuery->getFacetSet();
        foreach ($query->getFacetFields() as $field => $config) {
            $facets->createFacetField($config['Title'])->setField($config['Field']);
        }
        $facets->setMinCount($query->getFacetsMinCount());
    }
}
