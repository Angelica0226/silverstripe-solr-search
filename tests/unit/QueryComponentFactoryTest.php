<?php


namespace Firesphere\SolrSearch\Tests;

use Firesphere\SolrSearch\Factories\QueryComponentFactory;
use Firesphere\SolrSearch\Queries\BaseQuery;
use SilverStripe\Dev\SapphireTest;

class QueryComponentFactoryTest extends SapphireTest
{

    /**
     * @var QueryComponentFactory
     */
    protected $factory;

    protected function setUp()
    {
        $this->factory = new QueryComponentFactory();

        return parent::setUp();
    }

    public function testBuildQuery()
    {
        $index = new \CircleCITestIndex();
        $query = new BaseQuery();
        $clientQuery = $index->getClient()->createSelect();
        $query->addTerm('Home');
        $query->addField('SiteTree_Title');
        $query->addFilter('SiteTree_Title', 'Home');
        $query->addExclude('SiteTree_Title', 'Contact');
        $query->addBoostedField('SiteTree_Content', [], 2);

        $this->factory->setQuery($query);
        $this->factory->setIndex($index);
        $this->factory->setClientQuery($clientQuery);
        $this->factory->setQueryArray(['Home']);

        $this->factory->buildQuery();

        $expected = ['Home', 'SiteTree_Content:Home^2.0'];
        $this->assertEquals($expected, $this->factory->getQueryArray());
        $this->assertEquals(['SiteTree_Title'], $this->factory->getClientQuery()->getFields());
        $this->assertCount(3, $this->factory->getClientQuery()->getFilterQueries());
    }
}
