<?php
namespace Elastica;

use Elastica\Exception\DeprecatedException;
use Elastica\Exception\InvalidException;
use Elastica\Exception\NotFoundException;
use Elastica\Exception\RuntimeException;
use Elastica\Type\Mapping;

/**
 * Elastica type object.
 *
 * elasticsearch has for every types as a substructure. This object
 * represents a type inside a context
 * The hierarchy is as following: client -> index -> type -> document
 *
 * @author   Nicolas Ruflin <spam@ruflin.com>
 */
class Type implements SearchableInterface
{
    /**
     * Index.
     *
     * @var \Elastica\Index Index object
     */
    protected $_index = null;

    /**
     * Type name.
     *
     * @var string Type name
     */
    protected $_name = '';

    /**
     * @var array|string A callable that serializes an object passed to it
     */
    protected $_serializer;

    /**
     * Creates a new type object inside the given index.
     *
     * @param \Elastica\Index $index Index Object
     * @param string          $name  Type name
     */
    public function __construct(Index $index, $name)
    {
        $this->_index = $index;
        $this->_name = $name;
    }

    /**
     * Adds the given document to the search index.
     *
     * @param \Elastica\Document $doc Document with data
     *
     * @return \Elastica\Response
     */
    public function addDocument(Document $doc)
    {
        $path = urlencode($doc->getId());

        $type = Request::PUT;

        // If id is empty, POST has to be used to automatically create id
        if (empty($path)) {
            $type = Request::POST;
        }

        $options = $doc->getOptions(
            array(
                'version',
                'version_type',
                'routing',
                'percolate',
                'parent',
                'ttl',
                'timestamp',
                'op_type',
                'consistency',
                'replication',
                'refresh',
                'timeout',
            )
        );

        $response = $this->request($path, $type, $doc->getData(), $options);

        $data = $response->getData();
        // set autogenerated id to document
        if (($doc->isAutoPopulate()
                || $this->getIndex()->getClient()->getConfigValue(array('document', 'autoPopulate'), false))
            && $response->isOk()
        ) {
            if (!$doc->hasId()) {
                if (isset($data['_id'])) {
                    $doc->setId($data['_id']);
                }
            }
            if (isset($data['_version'])) {
                $doc->setVersion($data['_version']);
            }
        }

        return $response;
    }

    /**
     * @param $object
     * @param Document $doc
     *
     * @throws Exception\RuntimeException
     *
     * @return Response
     */
    public function addObject($object, Document $doc = null)
    {
        if (!isset($this->_serializer)) {
            throw new RuntimeException('No serializer defined');
        }

        $data = call_user_func($this->_serializer, $object);
        if (!$doc) {
            $doc = new Document();
        }
        $doc->setData($data);

        return $this->addDocument($doc);
    }

    /**
     * Update document, using update script. Requires elasticsearch >= 0.19.0.
     *
     * @link https://www.elastic.co/guide/en/elasticsearch/reference/current/docs-update.html
     *
     * @param \Elastica\Document|\Elastica\Script $data    Document with update data
     * @param array                               $options array of query params to use for query. For possible options check es api
     *
     * @throws \Elastica\Exception\InvalidException
     *
     * @return \Elastica\Response
     */
    public function updateDocument($data, array $options = array())
    {
        if (!($data instanceof Document) && !($data instanceof Script)) {
            throw new \InvalidArgumentException('Data should be a Document or Script');
        }

        if (!$data->hasId()) {
            throw new InvalidException('Document or Script id is not set');
        }

        $id = urlencode($data->getId());

        return $this->getIndex()->getClient()->updateDocument(
            $id,
            $data,
            $this->getIndex()->getName(),
            $this->getName(),
            $options
        );
    }

    /**
     * Uses _bulk to send documents to the server.
     *
     * @param array|\Elastica\Document[] $docs Array of Elastica\Document
     *
     * @return \Elastica\Bulk\ResponseSet
     *
     * @link https://www.elastic.co/guide/en/elasticsearch/reference/current/docs-bulk.html
     */
    public function updateDocuments(array $docs)
    {
        foreach ($docs as $doc) {
            $doc->setType($this->getName());
        }

        return $this->getIndex()->updateDocuments($docs);
    }

    /**
     * Uses _bulk to send documents to the server.
     *
     * @param array|\Elastica\Document[] $docs Array of Elastica\Document
     *
     * @return \Elastica\Bulk\ResponseSet
     *
     * @link https://www.elastic.co/guide/en/elasticsearch/reference/current/docs-bulk.html
     */
    public function addDocuments(array $docs)
    {
        foreach ($docs as $doc) {
            $doc->setType($this->getName());
        }

        return $this->getIndex()->addDocuments($docs);
    }

    /**
     * Uses _bulk to send documents to the server.
     *
     * @param objects[] $objects
     *
     * @return \Elastica\Bulk\ResponseSet
     *
     * @link https://www.elastic.co/guide/en/elasticsearch/reference/current/docs-bulk.html
     */
    public function addObjects(array $objects)
    {
        if (!isset($this->_serializer)) {
            throw new RuntimeException('No serializer defined');
        }

        $docs = array();
        foreach ($objects as $object) {
            $data = call_user_func($this->_serializer, $object);
            $doc = new Document();
            $doc->setData($data);
            $doc->setType($this->getName());
            $docs[] = $doc;
        }

        return $this->getIndex()->addDocuments($docs);
    }

    /**
     * Get the document from search index.
     *
     * @param string $id      Document id
     * @param array  $options Options for the get request.
     *
     * @throws \Elastica\Exception\NotFoundException
     * @throws \Elastica\Exception\ResponseException
     *
     * @return \Elastica\Document
     */
    public function getDocument($id, $options = array())
    {
        $path = urlencode($id);

        $response = $this->request($path, Request::GET, array(), $options);
        $result = $response->getData();

        if (!isset($result['found']) || $result['found'] === false) {
            throw new NotFoundException('doc id '.$id.' not found');
        }

        if (isset($result['fields'])) {
            $data = $result['fields'];
        } elseif (isset($result['_source'])) {
            $data = $result['_source'];
        } else {
            $data = array();
        }

        $document = new Document($id, $data, $this->getName(), $this->getIndex());
        $document->setVersion($result['_version']);

        return $document;
    }

    /**
     * @param string       $id
     * @param array|string $data
     *
     * @return Document
     */
    public function createDocument($id = '', $data = array())
    {
        $document = new Document($id, $data);
        $document->setType($this);

        return $document;
    }

    /**
     * Returns the type name.
     *
     * @return string Type name
     */
    public function getName()
    {
        return $this->_name;
    }

    /**
     * Sets value type mapping for this type.
     *
     * @param \Elastica\Type\Mapping|array $mapping Elastica\Type\MappingType object or property array with all mappings
     *
     * @return \Elastica\Response
     */
    public function setMapping($mapping)
    {
        $mapping = Mapping::create($mapping);
        $mapping->setType($this);

        return $mapping->send();
    }

    /**
     * Returns current mapping for the given type.
     *
     * @return array Current mapping
     */
    public function getMapping()
    {
        $path = '_mapping';

        $response = $this->request($path, Request::GET);
        $data = $response->getData();

        $mapping = array_shift($data);
        if (isset($mapping['mappings'])) {
            return $mapping['mappings'];
        }

        return array();
    }

    /**
     * Create search object.
     *
     * @param string|array|\Elastica\Query $query   Array with all query data inside or a Elastica\Query object
     * @param int|array                    $options OPTIONAL Limit or associative array of options (option=>value)
     *
     * @return \Elastica\Search
     */
    public function createSearch($query = '', $options = null)
    {
        $search = new Search($this->getIndex()->getClient());
        $search->addIndex($this->getIndex());
        $search->addType($this);
        $search->setOptionsAndQuery($options, $query);

        return $search;
    }

    /**
     * Do a search on this type.
     *
     * @param string|array|\Elastica\Query $query   Array with all query data inside or a Elastica\Query object
     * @param int|array                    $options OPTIONAL Limit or associative array of options (option=>value)
     *
     * @return \Elastica\ResultSet with all results inside
     *
     * @see \Elastica\SearchableInterface::search
     */
    public function search($query = '', $options = null)
    {
        $search = $this->createSearch($query, $options);

        return $search->search();
    }

    /**
     * Count docs by query.
     *
     * @param string|array|\Elastica\Query $query Array with all query data inside or a Elastica\Query object
     *
     * @return int number of documents matching the query
     *
     * @see \Elastica\SearchableInterface::count
     */
    public function count($query = '')
    {
        $search = $this->createSearch($query);

        return $search->count();
    }

    /**
     * Returns index client.
     *
     * @return \Elastica\Index Index object
     */
    public function getIndex()
    {
        return $this->_index;
    }

    /**
     * @param \Elastica\Document $document
     *
     * @return \Elastica\Response
     */
    public function deleteDocument(Document $document)
    {
        $options = $document->getOptions(
            array(
                'version',
                'version_type',
                'routing',
                'parent',
                'replication',
                'consistency',
                'refresh',
                'timeout',
            )
        );

        return $this->deleteById($document->getId(), $options);
    }

    /**
     * Uses _bulk to delete documents from the server.
     *
     * @param array|\Elastica\Document[] $docs Array of Elastica\Document
     *
     * @return \Elastica\Bulk\ResponseSet
     *
     * @link https://www.elastic.co/guide/en/elasticsearch/reference/current/docs-bulk.html
     */
    public function deleteDocuments(array $docs)
    {
        foreach ($docs as $doc) {
            $doc->setType($this->getName());
        }

        return $this->getIndex()->deleteDocuments($docs);
    }

    /**
     * Deletes an entry by its unique identifier.
     *
     * @link https://www.elastic.co/guide/en/elasticsearch/reference/current/docs-delete.html
     *
     * @param int|string $id      Document id
     * @param array      $options
     *
     * @throws \InvalidArgumentException
     * @throws \Elastica\Exception\NotFoundException
     *
     * @return \Elastica\Response Response object
     */
    public function deleteById($id, array $options = array())
    {
        if (empty($id) || !trim($id)) {
            throw new \InvalidArgumentException();
        }

        $id = urlencode($id);

        $response = $this->request($id, Request::DELETE, array(), $options);

        $responseData = $response->getData();

        if (isset($responseData['found']) && false == $responseData['found']) {
            throw new NotFoundException('Doc id '.$id.' not found and can not be deleted');
        }

        return $response;
    }

    /**
     * Deletes the given list of ids from this type.
     *
     * @param array       $ids
     * @param string|bool $routing Optional routing key for all ids
     *
     * @return \Elastica\Response Response  object
     */
    public function deleteIds(array $ids, $routing = false)
    {
        return $this->getIndex()->getClient()->deleteIds($ids, $this->getIndex(), $this, $routing);
    }

    /**
     * Deletes entries in the db based on a query.
     *
     * @param \Elastica\Query|string $query   Query object
     * @param array                  $options Optional params
     *
     * @return \Elastica\Response
     *
     * @link https://www.elastic.co/guide/en/elasticsearch/reference/current/docs-delete-by-query.html
     */
    public function deleteByQuery($query, array $options = array())
    {
        if (is_string($query)) {
            // query_string queries are not supported for delete by query operations
            $options['q'] = $query;

            return $this->request('_query', Request::DELETE, array(), $options);
        }
        $query = Query::create($query);

        return $this->request('_query', Request::DELETE, array('query' => $query->getQuery()->toArray()), $options);
    }

    /**
     * Deletes the index type.
     *
     * @deprecated It is no longer possible to delete the mapping for a type. Instead you should delete the index and recreate it with the new mappings. This method will be removed in further Elastica releases.
     *
     * @throws DeprecatedException It is no longer possible to delete the mapping for a type. Instead you should delete the index and recreate it with the new mappings. This method will be removed in further Elastica releases.
     */
    public function delete()
    {
        throw new DeprecatedException('It is no longer possible to delete the mapping for a type. Instead you should delete the index and recreate it with the new mappings. This method will be removed in further Elastica releases.');
    }

    /**
     * Makes calls to the elasticsearch server based on this type.
     *
     * @param string $path   Path to call
     * @param string $method Rest method to use (GET, POST, DELETE, PUT)
     * @param array  $data   OPTIONAL Arguments as array
     * @param array  $query  OPTIONAL Query params
     *
     * @return \Elastica\Response Response object
     */
    public function request($path, $method, $data = array(), array $query = array())
    {
        $path = $this->getName().'/'.$path;

        return $this->getIndex()->request($path, $method, $data, $query);
    }

    /**
     * Sets the serializer callable used in addObject.
     *
     * @see \Elastica\Type::addObject
     *
     * @param array|string $serializer @see \Elastica\Type::_serializer
     *
     * @return $this
     */
    public function setSerializer($serializer)
    {
        $this->_serializer = $serializer;

        return $this;
    }

    /**
     * Checks if the given type exists in Index.
     *
     * @return bool True if type exists
     */
    public function exists()
    {
        $response = $this->getIndex()->request($this->getName(), Request::HEAD);
        $info = $response->getTransferInfo();

        return (bool) ($info['http_code'] == 200);
    }
}
