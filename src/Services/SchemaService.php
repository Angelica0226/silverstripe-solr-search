<?php


namespace Firesphere\SolrSearch\Services;

use Exception;
use Firesphere\SolrSearch\Helpers\SearchIntrospection;
use Firesphere\SolrSearch\Helpers\Statics;
use Firesphere\SolrSearch\Indexes\BaseIndex;
use GuzzleHttp\Client;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\View\ViewableData;

class SchemaService extends ViewableData
{

    /**
     * @var bool
     */
    protected $store = false;
    /**
     * @var string ABSOLUTE Path to template
     */
    protected $template;

    /**
     * @var string ABSOLUTE Path to types.ss template
     */
    protected $typesTemplate;
    /**
     * @var BaseIndex
     */
    protected $index;

    /**
     * @var SearchIntrospection
     */
    protected $introspection;

    /**
     * SchemaService constructor.
     */
    public function __construct()
    {
        parent::__construct();
        $this->introspection = Injector::inst()->get(SearchIntrospection::class);
    }

    /**
     * @return BaseIndex
     */
    public function getIndex()
    {
        return $this->index;
    }

    /**
     * @param BaseIndex $index
     * @return SchemaService
     */
    public function setIndex($index)
    {
        $this->index = $index;
        // Add the index to the introspection as well, there's no need for a separate call here
        // We're loading this core, why would we want the introspection from a different index?
        $this->introspection->setIndex($index);

        return $this;
    }

    /**
     * @return string
     */
    public function getIndexName()
    {
        return $this->index->getIndexName();
    }

    /**
     * @return string
     */
    public function getDefaultField()
    {
        return $this->index->getDefaultField();
    }

    public function getIDField()
    {
        return SolrCoreService::ID_FIELD;
    }

    /**
     * @return ArrayList
     * @throws Exception
     */
    public function getFulltextFieldDefinitions()
    {
        $return = ArrayList::create();
        foreach ($this->index->getFulltextFields() as $field) {
            $this->getFieldDefinition($field, $return);
        }

        return $return;
    }

    /**
     * @param $fieldName
     * @param ArrayList $return
     * @param null|string $copyField
     * @throws Exception
     */
    protected function getFieldDefinition($fieldName, &$return, $copyField = null)
    {
        $field = $this->introspection->getFieldIntrospection($fieldName);
        $typeMap = Statics::getTypeMap();
        $boostedFields = $this->index->getBoostedFields();
        foreach ($field as $name => $options) {
            // Temporary short-name solution until the Introspection is properly solved
            $name = explode('\\', $name);
            $name = end($name);
            // Boosted fields are always stored
            $store = ($this->store || array_key_exists($fieldName, $boostedFields)) ? 'true' : 'false';
            $item = [
                'Field'       => $name,
                'Type'        => $typeMap[$options['type']],
                'Indexed'     => 'true',
                'Stored'      => $store,
                'MultiValued' => $options['multi_valued'] ? 'true' : 'false',
                'Destination' => $copyField
            ];
            $return->push($item);
        }
    }

    /**
     * @return ArrayList
     */
    public function getCopyFields()
    {
        $fields = $this->index->getCopyFields();

        $return = ArrayList::create();
        foreach ($fields as $field => $copyFields) {
            $item = [
                'Field' => $field
            ];

            $return->push($item);
        }

        return $return;
    }

    /**
     * @return ArrayList
     * @throws Exception
     */
    public function getCopyFieldDefinitions()
    {
        $copyFields = $this->index->getCopyFields();

        $return = ArrayList::create();

        foreach ($copyFields as $field => $fields) {
            // Allow all fields to be in a copyfield via a shorthand
            if ($fields[0] === '*') {
                $fields = $this->index->getFulltextFields();
            }

            foreach ($fields as $copyField) {
                $this->getFieldDefinition($copyField, $return, $field);
            }
        }

        return $return;
    }

    /**
     * @return ArrayList
     * @throws Exception
     */
    public function getFilterFieldDefinitions()
    {
        $return = ArrayList::create();
        $originalStore = $this->store;
        $this->store = true;
        foreach ($this->index->getFilterFields() as $field) {
            $this->getFieldDefinition($field, $return);
        }

        $this->store = $originalStore;

        return $return;
    }

    /**
     * @return DBHTMLText
     */
    public function getTypes()
    {
        if (!$this->typesTemplate) {
            $solrVersion = $this->getSolrVersion();
            $dir = ModuleLoader::getModule('firesphere/solr-search')->getPath();
            $template = sprintf('%s/Solr/%s/templates/types.ss', $dir, $solrVersion);
            $this->setTypesTemplate($template);
        }

        return $this->renderWith($this->getTypesTemplate());
    }

    /**
     * @return int
     */
    protected function getSolrVersion(): int
    {
        $config = SolrCoreService::config()->get('config');
        $firstEndpoint = array_shift($config['endpoint']);
        $clientConfig = [
            'base_uri' => 'http://' . $firstEndpoint['host'] . ':' . $firstEndpoint['port']
        ];

        $client = new Client($clientConfig);

        $result = $client->get('solr/admin/info/system');
        $result = json_decode($result->getBody(), 1);

        $solrVersion = 5;
        $version = version_compare('5.0.0', $result['lucene']['solr-spec-version']);
        if ($version > 0) {
            $solrVersion = 4;
        }

        return $solrVersion;
    }

    /**
     * @return string
     */
    public function getTypesTemplate()
    {
        return $this->typesTemplate;
    }

    /**
     * @param string $typesTemplate
     * @return SchemaService
     */
    public function setTypesTemplate($typesTemplate)
    {
        $this->typesTemplate = $typesTemplate;

        return $this;
    }

    /**
     * @return DBHTMLText
     */
    public function generateSchema()
    {
        if (!$this->template) {
            $solrVersion = $this->getSolrVersion();
            $dir = ModuleLoader::getModule('firesphere/solr-search')->getPath();
            $template = sprintf('%s/Solr/%s/templates/schema.ss', $dir, $solrVersion);
            $this->setTemplate($template);
        }

        return $this->renderWith($this->getTemplate());
    }

    /**
     * @return string
     */
    public function getTemplate()
    {
        return $this->template;
    }

    /**
     * @param string $template
     * @return SchemaService
     */
    public function setTemplate($template)
    {
        $this->template = $template;

        return $this;
    }

    /**
     * @return string
     */
    public function getExtrasPath()
    {
        // @todo configurable but with default to the current absolute path
        $dir = ModuleLoader::getModule('firesphere/solr-search')->getPath();

        $confDirs = SolrCoreService::config()->get('paths');
        $solrVersion = $this->getSolrVersion();

        return sprintf($confDirs[$solrVersion]['extras'], $dir);
    }

    /**
     * @param bool $store
     */
    public function setStore(bool $store): void
    {
        $this->store = $store;
    }
}
