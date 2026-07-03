<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Meta;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Meta\TemplateResolver;

#[CoversClass( TemplateResolver::class )]
class TemplateResolverTest extends TestCase {

	private TemplateResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new TemplateResolver();
	}

	public static function resolve_cases(): array {
		$context = array(
			'title'    => 'Vintage Watch',
			'sitename' => 'Acme Auctions',
			'sep'      => '–',
		);

		return array(
			'all tokens present'          => array( '%%title%% %%sep%% %%sitename%%', $context, 'Vintage Watch – Acme Auctions' ),
			'missing token removed'       => array( '%%title%% %%sep%% %%price%%', $context, 'Vintage Watch –' ),
			'unknown token removed'       => array( '%%title%% %%bogus%%', $context, 'Vintage Watch' ),
			'no tokens'                   => array( 'Static text', $context, 'Static text' ),
			'whitespace collapsed'        => array( '%%missing%%  %%title%%  ', $context, 'Vintage Watch' ),
			'empty template'              => array( '', $context, '' ),
			'empty context value removed' => array( '%%excerpt%% %%title%%', array_merge( $context, array( 'excerpt' => '' ) ), 'Vintage Watch' ),
			'uppercase token matches case-insensitively' => array( '%%TITLE%% %%sep%% %%Sitename%%', $context, 'Vintage Watch – Acme Auctions' ),
		);
	}

	#[DataProvider( 'resolve_cases' )]
	public function test_resolve( string $template, array $context, string $expected ): void {
		$this->assertSame( $expected, $this->resolver->resolve( $template, $context ) );
	}

	public function test_resolve_does_not_recurse_into_replaced_values(): void {
		$this->assertSame(
			'%%sitename%%',
			$this->resolver->resolve( '%%title%%', array( 'title' => '%%sitename%%', 'sitename' => 'X' ) )
		);
	}
}
