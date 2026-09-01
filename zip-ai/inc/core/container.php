<?php
/**
 * Dependency Injection Container.
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Container Class.
 */
class Container {

	/**
	 * The bindings.
	 *
	 * @var array<string,array{concrete:\Closure|string,shared:bool}>
	 */
	protected $bindings = array();

	/**
	 * The shared instances.
	 *
	 * @var array<string,object>
	 */
	protected $instances = array();

	/**
	 * Register a binding with the container.
	 *
	 * @param string               $abstract The abstract type or name.
	 * @param \Closure|string|null $concrete The concrete implementation or closure.
	 * @param bool                 $shared   Whether the binding is shared (singleton).
	 * @return void
	 */
	public function bind( $abstract, $concrete = null, $shared = false ) {
		if ( is_null( $concrete ) ) {
			$concrete = $abstract;
		}

		$this->bindings[ $abstract ] = array(
			'concrete' => $concrete,
			'shared'   => $shared,
		);
	}

	/**
	 * Register a shared binding in the container.
	 *
	 * @param string               $abstract The abstract type or name.
	 * @param \Closure|string|null $concrete The concrete implementation or closure.
	 * @return void
	 */
	public function singleton( $abstract, $concrete = null ) {
		$this->bind( $abstract, $concrete, true );
	}

	/**
	 * Resolve the given type from the container.
	 *
	 * @param string $abstract The abstract type or name.
	 * @return mixed
	 */
	public function make( $abstract ) {
		// Return shared instance if it exists.
		if ( isset( $this->instances[ $abstract ] ) ) {
			return $this->instances[ $abstract ];
		}

		// If not bound, try to instantiate directly.
		if ( ! isset( $this->bindings[ $abstract ] ) ) {
			return new $abstract();
		}

		$concrete = $this->bindings[ $abstract ]['concrete'];
		$object   = null;

		if ( $concrete instanceof \Closure ) {
			$object = $concrete( $this );
		} else {
			$object = $this->make( $concrete );
		}

		// Save shared instance.
		if ( $this->bindings[ $abstract ]['shared'] ) {
			/**
			 * Narrowed type for `$object`.
			 *
			 * @var object $object
			 */
			$this->instances[ $abstract ] = $object;
		}

		return $object;
	}

	/**
	 * Check if the given abstract type has been bound.
	 *
	 * @param string $abstract The abstract type or name.
	 * @return bool
	 */
	public function has( $abstract ) {
		return isset( $this->bindings[ $abstract ] ) || isset( $this->instances[ $abstract ] );
	}
}
