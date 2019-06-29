<?php


namespace Firesphere\SolrSearch\Tests;

use CircleCITestIndex;
use Firesphere\SolrSearch\Helpers\Synonyms;
use Firesphere\SolrSearch\Indexes\BaseIndex;
use Firesphere\SolrSearch\Queries\BaseQuery;
use Firesphere\SolrSearch\Results\SearchResult;
use Firesphere\SolrSearch\Stores\FileConfigStore;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\DefaultAdminService;
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
        $this->assertCount(1, $this->index->getFulltextFields());
        $this->assertContains(SiteTree::class, $this->index->getClasses());
    }

    public function testGetSynonyms()
    {
        $this->assertEquals(Synonyms::getSynonymsAsString(), $this->index->getSynonyms());

        $this->assertEmpty($this->index->getSynonyms(false));
    }

    public function testIndexName()
    {
        $this->assertEquals('TestIndex', $this->index->getIndexName());
    }

    public function testUploadConfig()
    {
        $config = [
            'mode' => 'file',
            'path' => Director::baseFolder() . '/.solr'
        ];

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
    }

    public function testEscapeTerms()
    {
        $term = '"test me" help';

        $helper = $this->index->getClient()->createSelect()->getHelper();

        $escaped = $this->index->escapeSearch($term, $helper);
        $this->assertEquals('"\"test me\"" help', $escaped);

        $term = 'help me';

        $this->assertEquals('help me', $this->index->escapeSearch($term, $helper));
    }

    public function testGetFieldsForIndexing()
    {
        $expected = [
            'Content',
            'Title',
            'Created',
            'SubsiteID'
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

        $this->assertContains(SiteTree::class, $result3->getQuery()->getClasses());

        $index = new CircleCITestIndex();
        $query = new BaseQuery();
        $query->addTerm('Home', ['SiteTree_Title'], 5);
        $result4 = $index->doSearch($query);

        $this->assertEquals(['SiteTree_Title:Home^5.0'], $index->getBoostTerms());
        $this->assertEquals(['Home'], $index->getQueryTerms());
        $this->assertEquals(1, $result4->getTotalItems());

        $index = new CircleCITestIndex();
        $query = new BaseQuery();
        $query->addTerm('Home', ['SiteTree.Title'], 3);
        $result4 = $index->doSearch($query);

        $this->assertEquals(['SiteTree_Title:Home^3.0'], $index->getBoostTerms());
        $this->assertEquals(['Home'], $index->getQueryTerms());
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

    protected function setUp()
    {
        $this->index = Injector::inst()->get(TestIndex::class);

        return parent::setUp();
    }
}
