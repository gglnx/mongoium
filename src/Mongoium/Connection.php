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
class MongoiumConnectionException extends \Exception { }
class MongoExtensionNotFoundException extends MongoiumConnectionException {}

/**
 * 
 */
class Connection {
	/**
	 *
	 */
	private static $__server = null;
	
	/**
	 *
	 */
	private static $__database = null;
	
	/**
	 *
	 */
	private static $__mongo = null;
	
	/**
	 *
	 */
	private static $__collection = array();

	/**
	 *
	 */
	public static function init($server, $database) {
		// Init the server and database
		self::$__server = $server;
		self::$__database = $database;
	}
	
	/**
	 *
	 */
	public static function connect() {
		// Check if the mongo extension is installed
		if ( false == extension_loaded( 'mongo' ) )
			throw new MongoExtensionNotFoundException();

		// Connect if needed
		if ( false == is_object( self::$__mongo ) ):
			// Try to connect to the MongoDB
			self::$__mongo = new \MongoClient(self::$__server);
		endif;
	}
	
	/**
	 *
	 */
	public static function getCollection($collection) {
		// Connect to MongoDB if needed
		self::connect();
		
		// Select the collection
		if ( false == isset ( self::$__collection[$collection] ) ):
			self::$__collection[$collection] = new \MongoCollection(self::$__mongo->selectDB(self::$__database), $collection);
		endif;
		
		// Return MongoCollection object
		return self::$__collection[$collection];
	}
	
	/**
	 *
	 */
	public static function dropCollection($collection) {
		// Drop the collection
		self::getCollection($collection)->drop();
	}

	/**
	 * 
	 */
	public static function command($cmd) {
		// Connect to MongoDB if needed
		self::connect();

		// Send command to database
		return self::$__mongo->selectDB(self::$__database)->command($cmd);
	}
}
