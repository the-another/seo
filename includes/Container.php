<?php
/**
 * Container Class
 *
 * Dependency injection container with lazy loading and hook management.
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO;

use Exception;

/**
 * Class Container
 *
 * Service container following WooCommerce-style patterns with lazy loading.
 */
class Container {

	/**
	 * Container instance.
	 *
	 * @var Container|null
	 */
	private static ?Container $instance = null;

	/**
	 * Registered services (factories or instances).
	 *
	 * @var array<string, mixed>
	 */
	private array $services = array();

	/**
	 * Service factories for lazy instantiation.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = array();

	/**
	 * Instantiated singleton services.
	 *
	 * @var array<string, object>
	 */
	private array $singletons = array();

	/**
	 * Hook manager instance.
	 *
	 * @var HookManager
	 */
	private HookManager $hook_manager;

	/**
	 * Private constructor to prevent direct instantiation.
	 */
	private function __construct() {
		$this->hook_manager = new HookManager();
	}

	/**
	 * Get the container instance.
	 *
	 * @return Container Container instance.
	 */
	public static function get_instance(): Container {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get a service from the container.
	 *
	 * @param string $key Service key.
	 * @return mixed Service instance.
	 *
	 * @throws Exception If service not found.
	 */
	public function get( string $key ): mixed {
		if ( isset( $this->singletons[ $key ] ) ) {
			return $this->singletons[ $key ];
		}

		if ( isset( $this->factories[ $key ] ) ) {
			$instance = call_user_func( $this->factories[ $key ], $this );

			if ( isset( $this->services[ $key ]['singleton'] ) && $this->services[ $key ]['singleton'] ) {
				$this->singletons[ $key ] = $instance;
			}

			return $instance;
		}

		if ( isset( $this->services[ $key ] ) ) {
			return $this->services[ $key ];
		}

		throw new Exception( esc_html( sprintf( 'Service %s not found in container', $key ) ) );
	}

	/**
	 * Check if a service exists in the container.
	 *
	 * @param string $key Service key.
	 * @return bool True if service exists, false otherwise.
	 */
	public function has( string $key ): bool {
		return isset( $this->services[ $key ] ) || isset( $this->factories[ $key ] );
	}

	/**
	 * Register a service factory.
	 *
	 * @param string   $key       Service key.
	 * @param callable $factory   Factory function that receives Container as parameter.
	 * @param bool     $singleton Whether to treat as singleton (default: true).
	 * @return void
	 */
	public function register( string $key, callable $factory, bool $singleton = true ): void {
		$this->factories[ $key ] = $factory;
		$this->services[ $key ]  = array( 'singleton' => $singleton );
		unset( $this->singletons[ $key ] );
	}

	/**
	 * Register a direct service instance.
	 *
	 * @param string $key      Service key.
	 * @param mixed  $instance Service instance.
	 * @return void
	 */
	public function set( string $key, mixed $instance ): void {
		$this->services[ $key ] = $instance;
	}

	/**
	 * Get the hook manager instance.
	 *
	 * @return HookManager Hook manager instance.
	 */
	public function get_hook_manager(): HookManager {
		return $this->hook_manager;
	}

	/**
	 * Deregister all hooks managed by the container.
	 *
	 * @return void
	 */
	public function deregister_all_hooks(): void {
		$this->hook_manager->deregister_all();
	}

	/**
	 * Prevent cloning of the instance.
	 */
	private function __clone() {
	}

	/**
	 * Prevent unserialization of the instance.
	 *
	 * @throws Exception Always, to prevent unserialization.
	 */
	public function __wakeup() {
		throw new Exception( 'Cannot unserialize singleton' );
	}
}
