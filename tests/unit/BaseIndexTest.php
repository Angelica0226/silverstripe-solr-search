<?php


namespace Firesphere\SolrSearch\Tests;

use CircleCITestIndex;
use Firesphere\SolrSearch\Helpers\Synonyms;
use Firesphere\SolrSearch\Indexes\BaseIndex;
use Firesphere\SolrSearch\Queries\BaseQuery;
use Firesphere\SolrSearch\Results\SearchResult;
use Firesphere\SolrSearch\Stores\FileConfigStore;
use Firesphere\SolrSearch\Tasks\SolrConfigureTask;
use Firesphere\SolrSearch\Tasks\SolrIndexTask;
use Page;
use Psr\Log\NullLogger;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\NullHTTPRequest;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\Debug;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\PaginatedList;
use SilverStripe\Security\DefaultAdminService;
use SilverStripe\View\ArrayData;
use Solarium\Component\Result\Highlighting\Highlighting;
use Solarium\Core\Client\Client;

class BaseIndexTest extends SapphireTest
{
    /**
     * @var BaseIndex
     */
    protected $index;

    public function testConstruct()
    {
        $this->assertInstanceOf(Client::class, $this->index->getClient());
        $this->assertCount(1, $this->index->getClasses());
        $this->assertCount(2, $this->index->getFulltextFields());
        $this->assertContains(SiteTree::class, $this->index->getClasses());
    }

    public function testInit()
    {
        $this->assertNull($this->index->init());
        $this->assertNotEmpty($this->index->getFulltextFields());
        $this->assertNotEmpty($this->index->getFieldsForIndexing());
        $expected = [
            'Title',
            'Content',
            'SubsiteID',
            'Created',
            'ParentID',
        ];

        $this->assertEquals($expected, array_values($this->index->getFieldsForIndexing()));

        $index = new TestIndexTwo();

        $this->assertCount(3, $index->getFulltextFields());
        $this->assertCount(1, $index->getFacetFields());
    }

    public function testFacetFields()
    {
        /** @var Page $parent */
        $parent = Page::get()->filter(['Title' => 'Home'])->first();
        $page1 = Page::create(['Title' => 'Test 1', 'ParentID' => $parent->ID, 'ShowInSearch' => true]);
        $id1 = $page1->write();
        $page1->publishRecursive();
        $page2 = Page::create(['Title' => 'Test 2', 'ParentID' => $parent->ID, 'ShowInSearch' => true]);
        $id2 = $page2->write();
        $page2->publishRecursive();
        $task = new SolrIndexTask();
        $index = new TestIndex();
        $request = new HTTPRequest('GET', 'dev/tasks/SolrIndexTask', ['index' => TestIndex::class]);
        $task->run($request);
        $facets = $index->getFacetFields();
        $this->assertEquals([
            'Title' => 'Parent',
            'Field' => 'SiteTree.ParentID'
        ], $facets[SiteTree::class]);
        $query = new BaseQuery();
        $query->addTerm('Test');
        $clientQuery = $index->buildSolrQuery($query);
        $this->arrayHasKey('facet-Parent', $clientQuery->getFacetSet()->getFacets());
        $result = $index->doSearch($query);
        $this->assertInstanceOf(ArrayData::class, $result->getFacets());
        $parents = $result->getFacets();
        $this->assertCount(1, $parents->Parent);
        Page::get()->byID($id1)->delete();
        Page::get()->byID($id2)->delete();
    }

    public function testGetSynonyms()
    {
        $this->assertEquals(Synonyms::getSynonymsAsString(), $this->index->getSynonyms());

        $this->assertEmpty(trim($this->index->getSynonyms(false)));
    }

    public function testIndexName()
    {
        $this->assertEquals('TestIndex', $this->index->getIndexName());
    }

    public function testUploadConfig()
    {
        $config = [
            'mode' => 'file',
            'path' => '.solr'
        ];

        /** @var FileConfigStore $configStore */
        $configStore = Injector::inst()->create(FileConfigStore::class, $config);

        $this->index->uploadConfig($configStore);

        $this->assertFileExists(Director::baseFolder() . '/.solr/TestIndex/conf/schema.xml');
        $this->assertFileExists(Director::baseFolder() . '/.solr/TestIndex/conf/synonyms.txt');
        $this->assertFileExists(Director::baseFolder() . '/.solr/TestIndex/conf/stopwords.txt');

        $xml = file_get_contents(Director::baseFolder() . '/.solr/TestIndex/conf/schema.xml');
        $this->assertContains(
            '<field name=\'SiteTree_Title\' type=\'string\' indexed=\'true\' stored=\'true\' multiValued=\'false\'/>',
            $xml
        );

        $original = $configStore->getConfig();
        $configStore->setConfig([]);
        $this->assertEquals([], $configStore->getConfig());
        // Unhappy path, the config is not updated
        $this->assertNotEquals($original, $configStore->getConfig());
    }

    public function testGetFieldsForIndexing()
    {
        $expected = [
            'Title',
            'Content',
            'SubsiteID',
            'Created',
            'ParentID',
        ];
        $this->assertEquals($expected, array_values($this->index->getFieldsForIndexing()));
    }

    public function testGetSetClient()
    {
        $client = $this->index->getClient();
        // set client to something stupid
        $this->index->setClient('test');
        $this->assertEquals('test', $this->index->getClient());
        $this->index->setClient($client);
        $this->assertInstanceOf(Client::class, $this->index->getClient());
    }

    public function testDoSearch()
    {
        $index = new CircleCITestIndex();

        $query = new BaseQuery();
        $query->addTerm('Home');

        $result = $index->doSearch($query);
        $this->assertInstanceOf(SearchResult::class, $result);
        $this->assertEquals(1, $result->getTotalItems());

        $admin = singleton(DefaultAdminService::class)->findOrCreateDefaultAdmin();
        $this->loginAs($admin);
        // Result should be the same for now
        $result2 = $index->doSearch($query);
        $this->assertEquals($result, $result2);

        $query->addClass(SiteTree::class);

        $result3 = $index->doSearch($query);
        $request = new NullHTTPRequest();
        $this->assertInstanceOf(PaginatedList::class, $result3->getPaginatedMatches($request));
        $this->assertEquals($result3->getTotalItems(), $result3->getPaginatedMatches($request)->getTotalItems());
        $this->assertInstanceOf(ArrayData::class, $result3->getFacets());
        $this->assertInstanceOf(ArrayList::class, $result3->getSpellcheck());
        $this->assertInstanceOf(Highlighting::class, $result3->getHighlight());

        $result3->setCustomisedMatches([]);
        $this->assertInstanceOf(ArrayList::class, $result3->getMatches());
        $this->assertCount(0, $result3->getMatches());

        $index = new CircleCITestIndex();
        $query = new BaseQuery();
        $query->addTerm('Home', ['SiteTree_Title'], 5);
        $result4 = $index->doSearch($query);

        $this->assertContains('SiteTree_Title:Home^5.0', $index->getQueryFactory()->getBoostTerms());
        $this->assertContains('Home', $index->getQueryTerms());
        $this->assertEquals(1, $result4->getTotalItems());

        $index = new CircleCITestIndex();
        $query = new BaseQuery();
        $query->addTerm('Home', ['SiteTree.Title'], 3);
        $result4 = $index->doSearch($query);

        $this->assertContains('SiteTree_Title:Home^3.0', $index->getQueryFactory()->getBoostTerms());
        $this->assertContains('Home', $index->getQueryTerms());
        $this->assertEquals(1, $result4->getTotalItems());

        $index = new CircleCITestIndex();
        $query = new BaseQuery();
        $query->addTerm('Home', [], 0, true);
        $index->doSearch($query);

        $this->assertContains('Home~', $index->getQueryTerms());

        $index = new CircleCITestIndex();
        $query = new BaseQuery();
        $query->addTerm('Home', [], 0, 2);
        $index->doSearch($query);

        $this->assertContains('Home~2', $index->getQueryTerms());
    }

    public function testGetFieldsForSubsites()
    {
        $this->assertContains('SubsiteID', $this->index->getFilterFields());
    }

    public function testSetFacets()
    {
        $this->index->addFacetField(Page::class, ['Title' => 'Title', 'Field' => 'Content']);

        $expected = [
            SiteTree::class => [
                'Title' => 'Parent',
                'Field' => 'SiteTree.ParentID'
            ],
            Page::class     => [
                'Title' => 'Title',
                'Field' => 'Content'
            ]
        ];
        $this->assertEquals($expected, $this->index->getFacetFields());
    }

    public function testAddCopyField()
    {
        $this->index->addCopyField('myfield', ['Content']);
        $expected = [
            '_text'   => ['*'],
            'myfield' => ['Content']
        ];
        $this->assertEquals($expected, $this->index->getCopyFields());
    }

    protected function setUp()
    {
        $task = new SolrConfigureTask();
        $task->setLogger(new NullLogger());
        $task->run(new NullHTTPRequest());

        $this->index = Injector::inst()->get(TestIndex::class);

        return parent::setUp();
    }
}
