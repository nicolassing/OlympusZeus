<?php

namespace Takeatea\TeaThemeOptions;

use Elastica\Client;
use Elastica\Document;
use Elastica\Exception\NotFoundException;
use Elastica\Filter\BoolFilter;
use Elastica\Filter\Term;
use Elastica\Query;
use Elastica\Query\QueryString;
use Elastica\Suggest;
use Elastica\Suggest\Term as SuggestTerm;
use Elastica\Type\Mapping;

/**
 * TEA ELASTICSEARCH
 */

if (!defined('TTO_CONTEXT')) {
    die('You are not authorized to directly access to this page');
}


//----------------------------------------------------------------------------//

/**
 * Tea Elasticsearch
 *
 * To get its own Search
 *
 * @since 1.5.2.14
 *
 */
class TeaElasticsearch
{
    //Define protected/private vars
    protected $config = array();
    protected $client = null;
    protected $index = null;

    /**
     * Constructor.
     *
     * @param boolean $hook Define if we need to call hooks
     *
     * @since 1.4.3.10
     */
    public function __construct($hook = true)
    {
        //Get custom data
        $ctn = TeaThemeOptions::getConfigs('elastic');
        $this->setConfig($ctn);

        //Check index
        if (isset($ctn['status']) && 200 == $ctn['status']) {
            //Add WP Hooks
            if ($hook && TTO_IS_ADMIN) {
                add_action('save_post', array(&$this, '__save_post'));
                add_action('delete_post', array(&$this, '__delete_post'));
                add_action('trash_post', array(&$this, '__delete_post'));
            }
            else if ($hook) {
                add_action('pre_get_posts', array(&$this, '__search_process'), 500, 2);
                add_filter('the_posts', array(&$this, '__search_results'));
                add_action('template_redirect', array(&$this, '__search_template'));
            }
        }
    }

    //------------------------------------------------------------------------//

    /**
     * HOOK FUNCTIONS
     **/

    /**
     * Hook on deleting post.
     *
     * @param number $post_id Contains post ID
     *
     * @since 1.4.0
     */
    public function __delete_post($post_id)
    {
        //Check post param
        if (is_object($post_id)) {
            //Got the WP post object with all datas
            $post = $post_id;
        }
        else {
            //Got only the post ID, so we need to retrieve the entire object
            $post = get_post($post_id);
        }

        //Get datas
        $ctn = $this->getConfig();

        //Check post integrity
        if (null == $post || !array_key_exists($post->post_type, $ctn['index_post'])) {
            return;
        }

        //Maybe we need to delete post?
        $this->elasticaDeletePost($post);
    }

    /**
     * Hook on saving post.
     *
     * @param number $post_id Contains post ID
     *
     * @since 1.4.0
     */
    public function __save_post($post_id)
    {
        //Check post param
        if (is_object($post_id)) {
            //Got the WP post object with all datas
            $post = $post_id;
        }
        else {
            //Got only the post ID, so we need to retrieve the entire object
            $post = get_post($post_id);
        }

        //Do not need to update Elastica Client on revisions and autosaves
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || (defined('DOING_AJAX') && DOING_AJAX)) {
            return;
        }

        //Get datas
        $ctn = $this->getConfig();

        //Check post integrity
        if (null == $post || !array_key_exists($post->post_type, $ctn['index_post'])) {
            return;
        }

        //Maybe we need to delete post?
        if ('trash' == $post->post_status) {
            $this->elasticaDeletePost($post);
        }
        //Or simply update post?
        else if ('publish' == $post->post_status) {
            $this->elasticaUpdatePost($post);
        }
    }

    /**
     * Hook on search.
     *
     * @param object $wp_query Contains query post sent by WP core
     *
     * @since 1.4.0
     */
    public function __search_process($wp_query)
    {
        //Check page
        if (!$wp_query->is_main_query() || !is_search() || TTO_IS_ADMIN) {
            return;
        }

        //Get wp_query nulled
        $wp_query->posts = null;
        unset($wp_query->query_vars['author']);
        unset($wp_query->query_vars['title']);
        unset($wp_query->query_vars['content']);
    }

    /**
     * Hook on search.
     *
     * @param array $posts Contains all posts sent by WP core
     * @return array $posts Send an empty array
     *
     * @since 1.4.0
     */
    public function __search_results($posts)
    {
        //Check page
        if (!is_search() || TTO_IS_ADMIN) {
            return $posts;
        }

        //Return nothing to let the template do everything
        return array();
    }

    /**
     * Hook on search.
     *
     * @since 1.4.0
     */
    public function __search_template()
    {
        //Get search datas
        $ctn = $this->getConfig();

        //Check page
        if (is_search() && !TTO_IS_ADMIN && isset($ctn['template']) && 'no' == $ctn['template']) {
            include(TTO_PATH.'/Fields/Elasticsearch/in_search.tpl.php');
            exit;
        }
    }

    //------------------------------------------------------------------------//

    /**
     * ELASTICA FUNCTIONS
     **/

    /**
     * Create Elastica Document for special post.
     *
     * @param object $post Wordpress post object
     * @return array $document Elastica Document indexed with post ID
     *
     * @since 1.4.0
     */
    protected function elasticaAddDocumentPost($post)
    {
        global $blog_id;

        //Create document
        $doc = array(
            'blog_id' => $blog_id
        );

        //Check field 'ID'
        if (isset($post->ID)) {
            $doc['id'] = $post->ID;

            //Get tags
            $tags = get_the_term_list($post->ID, 'post_tag', '', ',', '');
            $tags = strip_tags($tags);

            //Check tags
            if (!empty($tags)) {
                $doc['tags'] = $tags;
            }
        }

        //Check field 'post_parent'
        if (isset($post->post_parent)) {
            $doc['parent'] = $post->post_parent;
        }

        //Check field 'post_title'
        if (isset($post->post_title)) {
            $doc['title'] = $post->post_title;
        }

        //Check field 'post_content'
        if (isset($post->post_content)) {
            $doc['content'] = strip_tags(stripcslashes($post->post_content));
        }

        //Check field 'post_excerpt'
        if (isset($post->post_excerpt)) {
            $doc['excerpt'] = strip_tags(stripcslashes($post->post_excerpt));
        }

        //Check field 'post_author'
        if (isset($post->post_author)) {
            $doc['author'] = $post->post_author;
        }

        //Check field 'post_date'
        if (isset($post->post_date)) {
            $doc['date'] = date('c', strtotime($post->post_date));
        }

        //Return document
        return $doc;
    }

    /**
     * Create Elastica Document for special taxonomy.
     *
     * @param object $taxo Wordpress taxonomy object
     * @return array $document Elastica Document indexed with post ID
     *
     * @since 1.4.0
     */
    protected function elasticaAddDocumentTax($taxo)
    {
        global $blog_id;

        //Create document
        $doc = array(
            'blog_id' => $blog_id
        );

        //Check field 'term_id'
        if (isset($taxo->term_id)) {
            $doc['id'] = $taxo->term_id;
        }

        //Check field 'name'
        if (isset($taxo->name)) {
            $doc['title'] = $taxo->name;
        }

        //Check field 'description'
        if (isset($taxo->description)) {
            $doc['content'] = strip_tags(stripcslashes($taxo->description));
        }

        //Return document
        return $doc;
    }

    /**
     * Create Elastica Analysis.
     *
     * @param object $index Elastica Index
     * @param array $posttypes Array containing all post types
     * @param array $taxonomies Array containing all taxonomies
     * @return object $index Elastica Index
     *
     * @since 1.4.3.10
     */
    protected function elasticaAnalysis($index, $posttypes, $taxonomies)
    {
        //Check integrity
        if (!isset($index) || empty($index)) {
            return null;
        }

        //Check integrity
        if (empty($posttypes) && empty($taxonomies)) {
            return null;
        }

        //Define properties
        $props = array(
            'id' => array(
                'type' => 'integer',
                'include_in_all' => false,
            ),
            'tags' => array(
                'type' => 'string',
                'index' => 'analyzed',
            ),
            'parent' => array(
                'type' => 'integer',
                'index' => 'analyzed',
            ),
            'title' => array(
                'type' => 'string',
                'index' => 'analyzed',
            ),
            'content' => array(
                'type' => 'string',
                'index' => 'analyzed',
            ),
            'excerpt' => array(
                'type' => 'string',
                'index' => 'analyzed',
            ),
            'author' => array(
                'type' => 'integer',
                'index' => 'analyzed',
            ),
            'date' => array(
                'type' => 'date',
                'format' => 'date_time_no_millis',
            ),
            'tags_suggest' => array(
                'type' => 'completion',
                'index_analyzer' => 'simple',
                'search_analyzer' => 'simple',
                'payloads' => false,
            ),
            '_boost' => array(
                'type' => 'float',
                'include_in_all' => false,
            ),
        );

        //Set analysis
        if (isset($posttypes) && !empty($posttypes)) {
            foreach ($posttypes as $k) {
                $index->create(array(
                    'number_of_shards' => 4,
                    'number_of_replicas' => 1,
                    'analysis' => array(
                        'analyzer' => array(
                            'indexAnalyzer' => array(
                                'type' => 'custom',
                                'tokenizer' => 'standard',
                                'filter' => array('lowercase', 'asciifolding', 'filter_' . $k),
                            ),
                            'searchAnalyzer' => array(
                                'type' => 'custom',
                                'tokenizer' => 'standard',
                                'filter' => array('standard', 'lowercase', 'asciifolding', 'filter_' . $k),
                            )
                        ),
                        'filter' => array(
                            'filter_' . $k => array(
                                'type' => 'standard',
                                'language' => TTO_LOCAL,
                                'ignoreCase' => true,
                            )
                        ),
                    ),
                ), true);

                //Define new Type
                $type = $index->getType($k);

                //Define a new Elastica Mapper
                $mapping = new Mapping();
                $mapping->setType($type);
                $mapping->setParam('index_analyzer', 'indexAnalyzer');
                $mapping->setParam('search_analyzer', 'searchAnalyzer');

                //Define boost field
                $mapping->setParam('_boost', array(
                    'name' => '_boost',
                    'null_value' => 1.0
                ));

                //Set mapping
                $mapping->setProperties($props);

                // Send mapping to type
                $mapping->send();
            }
        }

        //Set analysis
        if (isset($taxonomies) && !empty($taxonomies)) {
            foreach ($taxonomies as $t) {
                $index->create(array(
                    'number_of_shards' => 4,
                    'number_of_replicas' => 1,
                    'analysis' => array(
                        'analyzer' => array(
                            'indexAnalyzer' => array(
                                'type' => 'custom',
                                'tokenizer' => 'standard',
                                'filter' => array('lowercase', 'asciifolding', 'filter_' . $t),
                            ),
                            'searchAnalyzer' => array(
                                'type' => 'custom',
                                'tokenizer' => 'standard',
                                'filter' => array('standard', 'lowercase', 'asciifolding', 'filter_' . $t),
                            )
                        ),
                        'filter' => array(
                            'filter_' . $t => array(
                                'type' => 'standard',
                                'language' => TTO_LOCAL,
                                'ignoreCase' => true,
                            )
                        ),
                    ),
                ), true);

                //Define new Type
                $type = $index->getType($t);

                //Define a new Elastica Mapper
                $mapping = new Mapping();
                $mapping->setType($type);
                $mapping->setParam('index_analyzer', 'indexAnalyzer');
                $mapping->setParam('search_analyzer', 'searchAnalyzer');

                //Define boost field
                $mapping->setParam('_boost', array(
                    'name' => '_boost',
                    'null_value' => 1.0
                ));

                //Set mapping
                $mapping->setProperties($props);

                // Send mapping to type
                $mapping->send();
            }
        }

        //Return index
        return $index;
    }

    /**
     * Check Elastica Connection.
     *
     * @param array $ctn Contains all stored datas
     * @return int $status HTTP header status curl code
     *
     * @since 1.4.3.10
     */
    public static function elasticaConnection($ctn)
    {
        //Check if we are in admin panel
        if (!TTO_IS_ADMIN) {
            return;
        }

        //Do we have to check connection?
        if (!isset($ctn['enable']) || 'yes' != $ctn['enable']) {
            return 0;
        }

        //Build url
        $url = 'http://';
        $url .= isset($ctn['server_host']) ? $ctn['server_host'] : 'localhost';
        $url .= isset($ctn['server_port']) ? ':'.$ctn['server_port'].'/' : ':9200/';
        $url .= isset($ctn['index_name']) ? $ctn['index_name'].'/' : '';
        $url .= '_status';

        //Make curl
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $head = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        //Get JSON head
        $json = json_decode($head);

        //Check errors
        if (!$status && null === $json) {
            //Hum... Nothing good over here.
            return 0;
        }
        else if ($status && 404 == $status) {
            //Okay, only the brave, by Diesel
            $error = 'IndexMissingException[['.$ctn['index_name'].'] missing]';
            return isset($json->error) && $error == $json->error ? 404 : 0;
        }
        else if ($status && 200 == $status) {
            //Everything is good, everything is CocaCola!
            return 200;
        }

        //Update datas
        return 0;
    }

    /**
     * Create Elastica Client.
     *
     * @param boolean $write Define if we are writing transactions or reading them
     * @return object $client Elastica Client
     *
     * @since 1.4.3.10
     */
    protected function elasticaCreateClient($write = false)
    {
        //Get Elastica Client
        $client = $this->getClient();

        //Check integrity
        if (isset($client) && !empty($client)) {
            return $client;
        }

        //Get search datas
        $ctn = $this->getConfig();

        //Intensiate new object with server URL
        $client = new Client(array(
            'host' => $ctn['server_host'],
            'port' => $ctn['server_port'],
            'timeout' => $write ? $ctn['write_timeout'] : $ctn['read_timeout']
        ));

        //Define the new Client
        $this->setClient($client);

        //Return the created client
        return $client;
    }

    /**
     * Create Elastica Index.
     *
     * @param object $client Elastica Client
     * @return object $index Elastica Index
     *
     * @since 1.4.3.10
     */
    protected function elasticaCreateIndex($client)
    {
        //Check integrity
        if (!isset($client) || empty($client) || null === $client) {
            return null;
        }

        //Get Elastica Index
        $index = $this->getIndex();

        //Check integrity
        if (isset($index) && !empty($index) && null !== $index) {
            /**
             * @todo destroy old index instead of returning it?
             */
            return $index;
        }

        //Get search datas
        $ctn = $this->getConfig();

        //Update index
        $index = $client->getIndex($ctn['index_name']);

        //Update Index var
        $this->setIndex($index);

        //Return the created client
        return $index;
    }

    /**
     * Delete post from Elastica Client.
     *
     * @param object $post Post to delete
     *
     * @since 1.4.0
     */
    protected function elasticaDeletePost($post)
    {
        //Get index
        $index = $this->getIndex();

        //Get type
        $type = $index->getType($post->post_type);

        //Try to delete post
        try {
            //Delete post by its ID
            $type->deleteById($post->ID);

            //Update counter
            $count = TeaThemeOptions::getConfigs('elastic_index');
            $count = empty($count) ? 0 : $count[0] - 1;

            //Save in DB
            TeaThemeOptions::setConfigs('elastic_index', $count);
        } catch (NotFoundException $ex){}
    }

    /**
     * Update or Add a post into Elastica Client.
     *
     * @param object $post Post to update or add
     * @param object $index Elastica Index
     *
     * @since 1.4.0
     */
    protected function elasticaUpdatePost($post, $index = null)
    {
        //Get index
        $index = null !== $index ? $index : $this->getIndex();

        //Check index
        if (null === $index) {
            return;
        }

        //Try to update or add post
        try {
            //Make the magic
            $doc = $this->elasticaAddDocumentPost($post);
            $type = $index->getType($post->post_type);
            $type->addDocument(new Document($post->ID, $doc));
        } catch (NotFoundException $ex){}
    }

    /**
     * Update or Add a taxonomy into Elastica Client.
     *
     * @param object $tax Taxonomy to update or add
     * @param object $index Elastica Index
     *
     * @since 1.4.0
     */
    protected function elasticaUpdateTax($tax, $index = null)
    {
        //Get index
        $index = null != $index ? $index : $this->getIndex();

        //Check index
        if (null === $index) {
            return;
        }

        //Try to update or add post
        try {
            //Make the magic
            $doc = $this->elasticaAddDocumentTax($tax);
            $type = $index->getType($tax->taxonomy);
            $type->addDocument(new Document($tax->term_id, $doc));
        } catch (NotFoundException $ex){}
    }

    //------------------------------------------------------------------------//

    /**
     * MAIN FUNCTIONS
     **/

    /**
     * Index contents.
     *
     * @internal param bool $idxctn Define it we have to index contents or just create index
     * @return int $count Get number of items indexed
     *
     * @since 1.4.3.10
     */
    public function createElasticsearch()
    {
        //Check page
        if (!TTO_IS_ADMIN) {
            return;
        }

        //Get client and index
        $client = $this->getClient();
        $index = $this->getIndex();

        //Check integrity
        if (!isset($client) || empty($client)) {
            $client = $this->elasticaCreateClient(true);
        }

        //Check integrity
        if (!isset($index) || empty($index)) {
            $index = $this->elasticaCreateIndex($client);
        }

        //Get search datas
        $ctn = $this->getConfig();

        //Get datas for mapping
        $idp = isset($ctn['index_post']) ? $ctn['index_post'] : array();
        $idt = isset($ctn['index_tax']) ? $ctn['index_tax'] : array();

        //Create analysers and mappers for Posts
        $index = $this->elasticaAnalysis($index, $idp, $idt);

        //Update index
        $this->setIndex($index);
    }

    /**
     * Index contents.
     *
     * @param boolean $idxctn Define it we have to index contents or just create index
     * @return int $count Get number of items indexed
     *
     * @since 1.4.3.10
     */
    public function indexContents($idxctn = true)
    {
        //Check page
        if (!TTO_IS_ADMIN) {
            return 0;
        }

        //Get search datas
        $ctn = $this->getConfig();

        //Check if we can index some post types
        if (!isset($ctn['index_post']) || empty($ctn['index_post'])) {
            return 0;
        }

        //Get index
        $index = $this->getIndex();
        $idp = $ctn['index_post'];
        $idt = isset($ctn['index_tax']) && !empty($ctn['index_tax'])
            ? $ctn['index_tax']
            : array();

        //Check index
        if (null === $index || empty($index)) {
            return 0;
        }

        //Do we have to index contents
        if (!$idxctn) {
            return 0;
        }

        //Build args to the next request
        $pargs = array(
            'posts_per_page' => -1,
            'numberposts' => -1,
            'post_type' => $idp,
            'post_status' => 'publish',
            'orderby' => 'post_date',
            'order' => 'DESC',
        );
        $targs = array(
            'orderby' => 'slug',
            'hide_empty' => false,
        );

        //Get all wanted posts
        $posts = get_posts($pargs);
        $count = 0;

        //Iterate on all posts to create documents
        foreach ($posts as $post) {
            //Check post type
            if (!array_key_exists($post->post_type, $idp)) {
                continue;
            }

            //Update document
            $this->elasticaUpdatePost($post, $index);

            //Update counter
            $count++;
        }

        //Check taxonomies
        if (!empty($idt)) {
            //Get all wanted taxonomies
            $taxes = get_terms($idt, $targs);

            //Iterate on all posts to create documents
            foreach ($taxes as $tax) {
                //Check post type
                if (!array_key_exists($tax->taxonomy, $idt)) {
                    continue;
                }

                //Update document
                $this->elasticaUpdateTax($tax, $index);

                //Update counter
                $count++;
            }
        }

        //Refresh index
        $index->refresh();

        //Set and return count
        return $count;
    }

    /**
     * Search children.
     *
     * @param string $type Post type
     * @param int $parent Parent ID to get all children
     * @param string $order Order way
     * @return array $elasticsearches Combine of all results, total and aggregations
     *
     * @since 1.4.3.10
     */
    public function searchChildren($type, $parent, $order = 'desc')
    {
        //Check page
        if (is_search() || TTO_IS_ADMIN) {
            return;
        }

        //Return array
        $return = array(
            'parent' => $parent,
            'total' => 0,
            'results' => array()
        );

        //Check request
        if (empty($parent)) {
            return $return;
        }

        //Get query vars
        $results = array();
        $types = array();
        //$total = 0;

        //Get Elasticsearch datas
        //$ctn = $this->getConfig();
        $index = $this->getIndex();

        //Check index
        if (null === $index || empty($index)) {
            return $return;
        }

        //Create the actual search object with some data.
        $es_query = new Query();

        //Define term
        $es_term = new Term();
        $es_term->setTerm($type.'.parent', $parent);

        //Filter 'And'
        $es_filter = new BoolFilter();
        $es_filter->addMust($es_term);

        //Add filter to the search object
        $es_query->setFilter($es_filter);

        //Add sort
        $es_query->setSort(array($type.'.date' => array('order' => $order)));

        //Search!
        $es_resultset = $index->search($es_query);

        //Retrieve data
        $es_results = $es_resultset->getResults();

        //Check results
        if (null == $es_results || empty($es_results)) {
            return $return;
        }

        //Iterate to retrieve all IDs
        foreach ($es_results as $res) {
            $typ = $res->getType();

            //Save type
            $types[$typ] = $typ;

            //Save datas
            $results[$typ][] = array(
                'id' => $res->getId(),
                'score' => $res->getScore(),
                'source' => $res->getSource(),
            );
        }

        //Get total
        $total = $es_resultset->getTotalHits();

        //Return everything
        $return = array(
            'parent' => $parent,
            'total' => $total,
            'results' => $results
        );
        return $return;
    }

    /**
     * Search contents.
     *
     * @return array $elasticsearches Combine of all results, total and aggregations
     *
     * @since 1.5.0
     */
    public function searchContents()
    {
        //Check page
        if (!is_search() || TTO_IS_ADMIN) {
            return;
        }

        //Return array
        $return = array(
            'query' => array(
                'search' => '',
                'type' => '',
                'paged' => 0,
                'perpage' => 0
            ),
            'total' => 0,
            'types' => array(),
            'results' => array()
        );

        //Get query vars
        $request = isset($_REQUEST) ? $_REQUEST : array();
        $results = array();
        $types = array();
        //$total = 0;

        //Check request
        if (empty($request)) {
            return $return;
        }

        //Get Elasticsearch datas
        //$ctn = $this->getConfig();
        $index = $this->getIndex();

        //Check index
        if (null === $index || empty($index)) {
            return $return;
        }

        //Get search datas
        $search = isset($request['s'])
            ? str_replace('\"', '"', $request['s'])
            : '';

        //Return everything
        if (empty($search)) {
            return $return;
        }

        //Get search datas
        $type = isset($request['type']) ? $request['type'] : '';
        $paged = isset($request['paged']) && !empty($request['paged'])
            ? $request['paged'] - 1
            : 0;
        $perpage = isset($request['perpage'])
            ? $request['perpage']
            : TeaThemeOptions::get_option('posts_per_page', 10);

        //Build query string
        $es_querystring = new QueryString();

        //'And' or 'Or' default: 'Or'
        $es_querystring->setDefaultOperator('OR');
        $es_querystring->setQuery($search);

        //Create the actual search object with some data.
        $es_query = new Query();
        $es_query->setQuery($es_querystring);

        //Define options
        $es_query->setFrom($paged);     //Start
        $es_query->setLimit($perpage);  //How many

        //Search!
        $es_resultset = $index->search($es_query);

        //Retrieve data
        $es_results = $es_resultset->getResults();

        //Check results
        if (null == $es_results || empty($es_results)) {
            $return['query']['search'] = str_replace(' ', '+', $search);
            return $return;
        }

        //Iterate to retrieve all IDs
        foreach ($es_results as $res) {
            $typ = $res->getType();

            //Save type
            $types[$typ] = $typ;

            //Save datas
            $results[$typ][] = array(
                'id' => $res->getId(),
                'score' => $res->getScore(),
                'source' => $res->getSource(),
            );
        }

        //Get total
        $total = $es_resultset->getTotalHits();

        //Return everything
        $return = array(
            'query' => array(
                'search' => str_replace(' ', '+', $search),
                'type' => $type,
                'paged' => $paged,
                'perpage' => $perpage
            ),
            'total' => $total,
            'types' => $types,
            'results' => $results
        );
        return $return;
    }

    /**
     * Search suggest.
     *
     * @param string $type Post type
     * @param int $post Post ID to get all suggestions
     * @param array $tags Array contains all post tags
     * @return array $elasticsearches Combine of all results, total and aggregations
     *
     * @since 1.5.0
     */
    public function searchSuggest($type, $post, $tags)
    {
        //Check page
        if (!is_search() || TTO_IS_ADMIN) {
            return;
        }

        //Return array
        $return = array(
            'post' => $post,
            'tags' => $tags,
            'total' => 0,
            'results' => array()
        );

        //Check request
        if (empty($post)) {
            return $return;
        }

        //Get query vars
        $results = array();
        //$total = 0;

        //Get Elasticsearch datas
        //$ctn = $this->getConfig();
        $index = $this->getIndex();

        //Check index
        if (null === $index || empty($index)) {
            return $return;
        }

        //Create suggestion
        $es_suggest = new Suggest();

        //Iterate on all tags
        foreach ($tags as $k => $tag) {
            //CReate Term with options
            $es_term = new SuggestTerm('tags_suggest_'.$k, '_all');
            $es_term->setText($tag);
            $es_term->setSize(5);
            $es_term->setAnalyzer('simple');

            //Add Term to current suggestion
            $es_suggest->addSuggestion($es_term);
        }

        //Search!
        $es_resultset = $index->search($es_suggest);

        //Retrieve data
        $es_results = $es_resultset->getSuggests();

        //Check results
        if (null == $es_results || empty($es_results)) {
            return $return;
        }

        //Iterate to retrieve all IDs
        foreach ($es_results as $res) {
            //Check suggestions
            if (empty($res[0]['options'])) {
                continue;
            }

            //Iterate on all options
            foreach ($res[0]['options'] as $opt) {
                //Save datas
                $results[$opt['text']] = array(
                    'score' => $opt['score'],
                    'freq' => $opt['freq'],
                );
            }
        }

        //Get total
        $total = $es_resultset->getTotalHits();

        //Return everything
        $return = array(
            'post' => $post,
            'tags' => $tags,
            'total' => $total,
            'results' => $results
        );
        return $return;
    }

    //------------------------------------------------------------------------//

    /**
     * ACCESSORS
     **/

    /**
     * Get Elastica Client object.
     *
     * @return object $client Object of the Elastica Client datas
     *
     * @since 1.4.0
     */
    protected function getClient()
    {
        //Return value
        return $this->client;
    }

    /**
     * Set Elastica Client object.
     *
     * @param object $client Object of the Elastica Client datas
     *
     * @since 1.4.0
     */
    protected function setClient($client)
    {
        //Define value
        $this->client = $client;
    }

    /**
     * Get configs.
     *
     * @return array $search Array of all search datas
     *
     * @since 1.4.3.8
     */
    protected function getConfig()
    {
        //Return value
        $default = $this->getValues();
        $ctn = array_merge($default, $this->config);
        return $ctn;
    }

    /**
     * Set configs.
     *
     * @param array $config Array of all new config datas
     *
     * @since 1.4.0
     */
    protected function setConfig($config)
    {
        //Define value
        $this->config = $config;
    }

    /**
     * Get default values.
     *
     * @return array $default Contains all default values
     *
     * @since 1.5.2.14
     */
    public static function getValues()
    {
        return array(
            'enable' => 'no',
            'status' => 0,
            'server_host' => 'localhost',
            'server_port' => '9200',
            'index_name' => 'teasearch',
            'read_timeout' => 5,
            'write_timeout' => 10,
            'template' => 'no',
            'scores' => array(),
            'index_post' => array(),
            'index_tax' => array()
        );
    }

    /**
     * Get fields.
     *
     * @return array $fields Array of all fields
     *
     * @since 1.4.0
     */
    static function getFields()
    {
        //Return value
        return array('title', 'content', 'excerpt', 'date');
    }

    /**
     * Get Index object.
     *
     * @return object $index Object of the Elastica index
     *
     * @since 1.4.0
     */
    protected function getIndex()
    {

        if (null === $this->index) {
            //Get configs
            $ctn = $this->getConfig();

            //Index name
            $idname = isset($ctn['index_name']) ? $ctn['index_name'] : 'teasearch';

            //Set client
            $client = new Client(array(
                'host' => isset($ctn['server_host']) ? $ctn['server_host'] : 'localhost',
                'port' => isset($ctn['server_port']) ? $ctn['server_port'] : 9200,
            ));

            $index = $client->getIndex($idname);
            $this->index = $index;
        }

        //Return value
        return $this->index;
    }

    /**
     * Set Index object.
     *
     * @param object $index Object of the Elastica index
     *
     * @since 1.4.0
     */
    protected function setIndex($index)
    {
        //Define value
        $this->index = $index;
    }
}
