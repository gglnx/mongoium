<?php
/**
 * @package     Mongoium
 * @version     1.0-$Id$
 * @link        http://github.com/gglnx/mongoium
 * @author      Dennis Morhardt <info@dennismorhardt.de>
 * @copyright   Copyright 2013, Dennis Morhardt
 *
 * Permission is hereby granted, free of charge, to any person
 * obtaining a copy of this software and associated documentation
 * files (the "Software"), to deal in the Software without
 * restriction, including without limitation the rights to use,
 * copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the
 * Software is furnished to do so, subject to the following
 * conditions:
 *
 * The above copyright notice and this permission notice shall be
 * included in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES
 * OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
 * NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
 * HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
 * WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
 * OTHER DEALINGS IN THE SOFTWARE.
 */

/**
 * Namespace
 */
namespace Mongoium;

/**
 * Exceptions
 */
class MongoiumQueryException extends \Exception { }
class NothingFoundException extends MongoiumQueryException {}
class WrongSubqueryTypeException extends MongoiumQueryException {}

/**
 * 
 */
class Query implements \IteratorAggregate {
	/**
	 *
	 */
	public $query = array(
		'fields' => array(),
		'sort' => array(),
		'options' => array(),
		'limit' => 0,
		'skip' => 0
	);
	
	/**
	 *
	 */
	protected $collection;

	/**
	 *
	 */
	protected $collectionName;

	/**
	 * 
	 */
	protected $documentClass;
	
	/**
	 *
	 */
	public function __construct($collection, $documentClass = '\Mongoium\Document') {
		// Set collection name
		$this->collectionName = $collection;
		$this->collection = Connection::getCollection($this->collectionName);
		$this->documentClass = $documentClass;
		
		return $this;
	}

	/**
	 *
	 */
	public static function init($collection, $documentClass = '\Mongoium\Document') {
		return new self($collection, $documentClass);
	}
	
	/**
	 *
	 */
	public function getIterator() {
		return new \ArrayIterator($this->select());
	}

	/**
	 * 
	 */
	public function find() {
		// Optimize query
		$query = $this->optimizeQuery();

		// Open the query
		$query = $this->collection->find($query, $this->query['options']);

		// Sort entries
		$query->sort($this->query['sort']);
		
		// Limit entries
		if ( isset( $this->query['limit'] ) && 0 !== $this->query['limit'] )
			$query->limit($this->query['limit']);
		
		// Skip entries
		if ( isset( $this->query['skip'] ) && 0 !== $this->query['skip'] )
			$query->skip($this->query['skip']);
	
		// Return Document objects
		return array_map(function($document) {
			$documentClass = $this->documentClass;
			return new $documentClass($this->collectionName, $document, true, true);
		}, iterator_to_array($query));
	}

	/**
	 *
	 */
	public function findOne() {
		// Optimize query
		$query = $this->optimizeQuery();

		// Open the query
		$document = $this->collection->findOne($query, $this->query['options']);

		// Nothing found
		if ( null === $document )
			throw new NothingFoundException("No document was found matching to your query, collection is {$this->collectionName}.");

		// Return Document object
		$documentClass = $this->documentClass;
		return new $documentClass($this->collectionName, $document, true, true);
	}
	
	/**
	 * findById(mixed $id)
	 *
	 * Finds a document by ID. ID can be string or a MongoID object. Stortcut for 
	 * ->is('id', $id)->findOne();
	 *
	 */
	public function findById($id) {
		// Set ID
		$this->is('id', $id);
		
		// Find document
		return $this->findOne();
	}
	
	/**
	 * count()
	 */
	public function count() {
		// Optimize query
		$query = $this->optimizeQuery();

		// Return count
		return $this->collection->count($query, $this->query['limit'], $this->query['skip']);
	}

	/**
	 * countAll()
	 */
	public function countAll() {
		// Optimize query
		$query = $this->optimizeQuery();

		// Return count
		return $this->collection->count($query);
	}
	
	/**
	 * remove([bool $justOne])
	 *
	 * Sends the query to database and executes it. All or just one (if $justOne is true) found documents
	 * will be deleted. If it was successful true will be returned, else false.
	 */
	public function remove($justOne = false) {
		return Connection::getCollection($this->collection)->remove($this->query['fields'], $justOne);
	}
	
	/**
	 * sq(string $type = ['and', 'or', 'nor'], callback $subquery)
	 *
	 * Add a subquery like 'and', 'or' or 'nor' (XOR) to the query. 'not' is currently not supported.
	 * Uses the same Query API as the main query. The main query object is returned.
	 *
	 * $query->sq('or', function($q) {
	 *	 // One of these fields must match to find documents
	 *	 $q->is('field1', 'value1');
	 *	 $q->is('field2', 'value2');
	 *
	 *	 return $q;
	 * });
	 */
	public function sq($type, $subquery) {
		// Check if subquery type exists
		if ( false == in_array( $type, array( 'and', 'or', 'nor' ) ) )
			throw new WrongSubqueryTypeException();

		// Run subquery
		$q = $subquery(new self($this->model, $this->collection, $this->single));

		// Add query
		$this->query['fields']['$' . $type] = $q->query['fields'];

		return $this;
	}

	/**
	 * is(string $field, mixed $value)
	 *
	 * Checks if $field is equal to $value, which can be string, numeric, array, object or
	 * an operator (for that use the operator functions). The query object is returned.
	 */
	public function is($field, $value) {
		// Translate 'id' to '_id'
		if ( 'id' == $field && !is_array( $value ) ):
			$field = '_id';
			$value = ( $value instanceof \MongoId ) ? $value : new \MongoId($value);
		endif;
		
		// Create Mongo reference if value is a model
		if ( $value instanceof \Mongoium\Document )
			$value = \MongoDBRef::create($value->getCollection(), $value->id);
		
		// Add field to the query
		$this->query['fields'][] = array($field => $value);
		return $this;
	}
	
	/**
	 * in(string $field, string|array $values)
	 *
	 * Checks if $field is equal to one of the values in $values. The uery object is returned.
	 */
	public function in($field, $values) {
		// Transform $values to an array if needed
		if ( false == is_array( $values ) )
			$values = array($values);

		// Translate ids into MongoIds if needed
		if ( 'id' == $field ):
			$field = '_id';
			$values = array_map(function($value) {
				return ( $value instanceof \MongoId ) ? $value : new \MongoId($value);
			}, $values);
		endif;
		
		// Add operator to the query
		return $this->addOperator('in', $field, $values);
	}
	
	/**
	 * notIn(string $field, string|array $values)
	 *
	 * Checks if $field is not equal to one of the values in $values. The query object is returned.
	 */
	public function notIn($field, $values) {
		// Transform $values to an array if needed
		if ( false == is_array( $values ) )
			$values = array($values);

		// Translate ids into MongoIds if needed
		if ( 'id' == $field ):
			$field = '_id';
			$values = array_map(function($value) {
				return ( $value instanceof \MongoId ) ? $value : new \MongoId($value);
			}, $values);
		endif;
	
		// Add operator to the query
		return $this->addOperator('nin', $field, $values);
	}
	
	/**
	 * isNot(string $field, mixed $value)
	 *
	 * Checks if $field is not equal to $value. The query object is returned.
	 */
	public function isNot($field, $value) {
		// Add operator to the query
		return $this->addOperator('ne', $field, $value);
	}
	
	/**
	 *
	 */
	public function ref($field, $collection, $id) {
		// Add reference to the query
		return $this->is($field, \MongoDBRef::create($collection, $id));
	}
	
	/**
	 *
	 */
	public function gt($field, $value) {
		// Add operator to the query
		return $this->addOperator('gt', $field, $value);
	}
	
	/**
	 *
	 */
	public function gte($field, $value) {
		// Add operator to the query
		return $this->addOperator('gte', $field, $value);
	}
	
	/**
	 *
	 */
	public function lt($field, $value) {
		// Add operator to the query
		return $this->addOperator('lt', $field, $value);
	}
	
	/**
	 *
	 */
	public function lte($field, $value) {
		// Add operator to the query
		return $this->addOperator('lte', $field, $value);
	}
	
	/**
	 *
	 */
	public function range($field, $start, $end) {
		// Add operator to the query
		return $this->addOperator('gt', $field, $start)->addOperator('lt', $field, $end);
	}
	
	/**
	 *
	 */
	public function size($field, $value) {
		// Add operator to the query
		return $this->addOperator('size', $field, $value);
	}
	
	/**
	 *
	 */
	public function exists($field, $exists = true) {
		// Add operator to the query
		return $this->addOperator('exists', $field, $exists);
	}
	
	/**
	 *
	 */
	public function all($field, $values) {
		// Add operator to the query
		return $this->addOperator('all', $field, $values);
	}
	
	/**
	 *
	 */
	public function mod($field, $value) {
		// Add operator to the query
		return $this->addOperator('mod', $field, $value);
	}
	
	/**
	 *
	 */
	public function near($field, $lat, $lng, $maxDistance = null) {
		// Add max distance operator to the query
		if ( null !== $maxDistance )
			$this->addOperator('maxDistance', $field, $maxDistance);
		
		// Add operator to the query
		return $this->addOperator('near', $field, array($lat, $lng));
	}
	
	/**
	 *
	 */
	public function regex($field, $value) {
		// Add regex operator to the query
		$this->is($field, new \MongoRegex($value));
		
		return $this;
	}
	
	/**
	 *
	 */
	public function like($field, $value) {
		// Add like operator to the query
		return $this->regex($field, '/.*' . $value . '.*/i');
	}

	/**
	 *
	 */
	public function jsfunc($function) {
		// Add $where field
		$this->is('$where', new \MongoCode($function));
		
		return $this;
	}
	
	/**
	 *
	 */
	public function exclude($field) {
		$this->query['options'][$field] = 0;
		
		return $this;
	}
	
	/**
	 *
	 */
	public function slice($field, $values) {
		$this->query['options'][$field] = $values;
		
		return $this;
	}
	
	/**
	 *
	 */
	public function sort($field, $ascending = true) {
		$this->query['sort'] = array();
		$this->query['sort'][$field] = $ascending ? 1 : -1;
		
		return $this;
	}
	
	/**
	 *
	 */
	public function limit($count) {
		$this->query['limit'] = $count;
		
		return $this;
	}
	
	/**
	 *
	 */
	public function skip($count) {
		$this->query['skip'] = $count;
		
		return $this;
	}
	
	/**
	 * addOperator(string $operator, string $field, mixed $value)
	 *
	 * Helper function to add operators to the query.
	 */
	private function addOperator($operator, $field, $value) {
		$this->is($field, array('$' . $operator => $value));
		
		return $this;
	}

	/**
	 *
	 */
	private function optimizeQuery() {
		// Optimize query
		$query = array();
		foreach ( $this->query['fields'] as $index => $query_part ):
			// Query part is a subquery
			if ( is_string( $index ) ):
				$query[$index] = $query_part;
			// Query part is a field
			else:
				// Get key for this field
				$key = array_keys($query_part)[0];

				// Add field to query or merge if operators
				if ( isset( $query[$key] ) && is_array( $query[$key] ) ):
					$query[$key] = array_merge($query[$key], $query_part[$key]);
				else:
					$query[$key] = $query_part[$key];
				endif;
			endif;
		endforeach;

		return $query;
	}
}
