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
class MongoiumDocumentException extends \Exception { }
class SavingException extends MongoiumQueryException {}

/**
 * 
 */
class Document implements \ArrayAccess {
	/**
	 *
	 */
	private $__data = array();
	
	/**
	 *
	 */
	private $__collection = null;

	/**
	 *
	 */
	private $__timezone = null;
	
	/**
	 *
	 */
	private $__saved = true;
	
	/**
	 *
	 */
	private $__new = false;

	/**
	 *
	 */
	private static $timezone;
	
	/**
	 *
	 */
	public function __construct($collection, $data = array(), $raw = false, $saved = false, $timezone = null) {
		// Name of the collection
		$this->__collection = $collection;

		// Timezone
		$this->__timezone = $timezone ?: self::getTimezone();
		
		// Set the data
		if ( true == $raw )
			$this->__data = $this->__data + (array) $data;
		else foreach( $data as $parameter => $value )
			$this->__set($parameter, $value);
	
		// Set the states
		$this->__saved = $saved;
		$this->__new = !$raw;
	}

	/**
	 *
	 */
	public static function setTimezone($timezone) {
		// Set timezone
		static::$timezone = $timezone;
	}

	/**
	 *
	 */
	public static function getTimezone() {
		// Get timezone
		return static::$timezone;
	}
	
	/**
	 *
	 */
	public function &__get($parameter) {
		// Return requested array if not a MongoDB reference
		if ( array_key_exists( $parameter, $this->__data ) && is_array( $this->__data[$parameter] ) && !\MongoDBRef::isRef( $this->__data[$parameter] ) ):
			// Save is needed
			$this->__saved = false;

			// Return array
    		return $this->__data[$parameter];
    	endif;

		// MongoId is requested, but didn't exists
		if ( 'id' == $parameter && false == array_key_exists( '_id', $this->__data ) )
			$value = $this->__set('_id', new \MongoId());
		// String representation of MongoId is requested
		elseif ( 'id' == $parameter )
			$value = $this->__data['_id'];
		// Simple parameter is requested
		elseif ( array_key_exists( $parameter, $this->__data ) )
			$value = $this->__data[$parameter];
		// Parameter was not found
		else
			$value = null;

		// Return a DataTime object instand of a MongoDate object
		if ( $value instanceof \MongoDate ):
			$value = new \DateTime("@{$value->sec}");
			$value->setTimezone($this->__timezone);
		endif;

		// Return model if value is database reference
		if ( is_array( $value ) && \MongoDBRef::isRef( $value ) ):
			$value = $this->__data[$parameter] = Query::init($value['$ref'])->is('_id', new \MongoId($value['$id']))->findOne();
		endif;
		
		// Return null if parameter has not been found
		return $value;
	}

	/**
	 *
	 */
	public function &offsetGet($parameter) {
		return $this->__get($parameter);
	}
	
	/**
	 *
	 */
	public function __set($parameter, $value) {
		// Transform 'id' to a MongoId
		if ( 'id' == $parameter || '_id' == $parameter ):
			$parameter = '_id';
			$value = ( $value instanceof \MongoId ) ? $value : new \MongoId($value);
		endif;
		
		// Transform DateTime objects to MongoDate
		if ( $value instanceof \DateTime )
			$value = new \MongoDate($value->getTimestamp());
	
		// Set the saved state to false and save the parameter
		$this->__saved = false;
		$this->__data[$parameter] = &$value;
		
		// Return the value
		return $value;
	}

	/**
	 *
	 */
	public function offsetSet($parameter, $value) {
		return $this->__set($parameter, $value);
	}
	
	/**
	 *
	 */
	public function __isset($parameter) {
		// Translate 'id' to '_id' and check if it exists
		if ( 'id' == $parameter && isset( $this->__data['_id'] ) )
			return true;
			
		// Check if the parameter exists
		if ( isset( $this->__data[$parameter] ) )
			return true;

		// Return false if the parameter has not been found
		return false;
	}

	/**
	 *
	 */
	public function offsetExists($parameter) {
		return $this->__isset($parameter);
	}

	/**
	 *
	 */
	public function __unset($parameter) {
		// Delete parameter
		if ( array_key_exists( $parameter, $this->__data ) )
			unset($this->__data[$parameter]);
	}

	/**
	 *
	 */
	public function offsetUnset($parameter) {
		return $this->__unset($parameter);
	}

	/**
	 * __toString()
	 *
	 * Returns string version (a MongoDB reference as JSON) of the Model.
	 */
	public function __toString() {
		return json_encode(\MongoDBRef::create($this->__collection, $this->__get('id')));
	}

	/**
	 * __toArray([bool $resolveDBRefs])
	 *
	 * Returns model data as an array. If $resolveDBRefs is true (defaults) when
	 * all database reference will be solved, otherwise only string representation
	 * will be included.
	 */
	public function __toArray($resolveDBRefs = true) {
		// Get model data
		$data = array();
		foreach ( array_keys( $this->__data ) as $key ):
			// Get data for key
			$data[$key] = $this->__get($key);

			// Resolve DB references
			if ( $resolveDBRefs && $data[$key] instanceof \Mongoium\Document ):
				$data[$key] = $data[$key]->__toArray();
			elseif ( $data[$key] instanceof \Mongoium\Document ):
				$data[$key] = $data[$key]->__toString();
			endif;
		endforeach;

		// Return data
		return $data;
	}

	/**
	 * __toDBRef()
	 *
	 * Returns the model as a database reference (DBRef).
	 */
	public function __toDBRef() {
		// Create database reference
		return \MongoDBRef::create($this->__collection, $this->id);
	}
	
	/**
	 *
	 */
	public function isNewRecord() {
		// Return if the record is new
		return $this->__new;
	}
	
	/**
	 *
	 */
	public function isSaved() {
		// Return if the record has been saved
		return $this->__saved;
	}

	/**
	 *
	 */
	public function getCollection() {
		// Return the collection name of this model
		return $this->__collection;
	}
	
	/**
	 *
	 */
	public function delete() {
		// Delete the item
		$state = Connection::getCollection($this->__collection)->remove(array('_id' => $this->__data['_id']));
		
		// Destory the model
		if ( true == $state ):
			$this->__saved = true;
			$this->__new = true;
			$this->__data = array();
		endif;
		
		return $state;
	}
	
	/**
	 *
	 */
	public function save() {
		// Model is already saved
		if ( true == $this->__saved )
			return true;
		
		// Create or update database references
		$data = $this->__data;
		foreach ( $data as $key => $value ):
			if ( $value instanceof \Mongoium\Document ):
				// Save model
				$value->save();

				// Create database reference
				$data[$key] = \MongoDBRef::create($value->__collection, $value->id);
			endif;
		endforeach;
		
		// Update or create?
		if ( false == $this->__new )
			$state = $this->__update($data);
		else
			$state = $this->__create($data);
		
		// Check if nothing failed
		if ( false == $state )
			throw new SavingException();
			
		// Set the state of the model
		$this->__saved = true;
		$this->__new = false;
		
		// Everything fine.
		return true;
	}
	
	/**
	 *
	 */
	private function __update($data) {
		// Update
		$result = Connection::getCollection($this->__collection)->update(array('_id' => $this->__data['_id']), $data);
		
		// Return the result
		return $result;
	}
	
	/**
	 *
	 */
	private function __create($data) {	
		// Insert into the collection
		$result = Connection::getCollection($this->__collection)->insert($data);
		$this->__data['_id'] = $data['_id'];
		
		// Return the result
		return $result;
	}
}
