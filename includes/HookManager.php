<?php
/**
 * Hook Manager Class
 *
 * Manages WordPress hook registration and deregistration with tracking.
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO;

/**
 * Class HookManager
 *
 * Tracks and manages WordPress hooks for easy registration and deregistration.
 */
class HookManager {

	/**
	 * Registered hooks tracking.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $registered_hooks = array();

	/**
	 * Register a WordPress action hook.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback function.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Number of accepted arguments.
	 * @return void
	 */
	public function register_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		if ( has_action( $hook, $callback ) !== false ) {
			return;
		}

		add_action( $hook, $callback, $priority, $accepted_args );

		$this->registered_hooks[] = array(
			'type'          => 'action',
			'hook'          => $hook,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
	}

	/**
	 * Register a WordPress filter hook.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback function.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Number of accepted arguments.
	 * @return void
	 */
	public function register_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		if ( has_filter( $hook, $callback ) !== false ) {
			return;
		}

		add_filter( $hook, $callback, $priority, $accepted_args );

		$this->registered_hooks[] = array(
			'type'          => 'filter',
			'hook'          => $hook,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
	}

	/**
	 * Deregister all hooks tracked by this manager.
	 *
	 * @return void
	 */
	public function deregister_all(): void {
		foreach ( $this->registered_hooks as $hook_data ) {
			if ( 'action' === $hook_data['type'] ) {
				remove_action( $hook_data['hook'], $hook_data['callback'], $hook_data['priority'] );
			} else {
				remove_filter( $hook_data['hook'], $hook_data['callback'], $hook_data['priority'] );
			}
		}
		$this->registered_hooks = array();
	}

	/**
	 * Get all registered hooks.
	 *
	 * @return array<int, array<string, mixed>> Registered hooks.
	 */
	public function get_registered_hooks(): array {
		return $this->registered_hooks;
	}
}
