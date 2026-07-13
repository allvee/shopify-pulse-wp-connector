<?php
/**
 * Settings store: defaults, and the one-time forward migration that splits the
 * old single "catalog" switch into independent category/brand/product controls.
 *
 * @package ShopifyPulse
 */

class Test_Settings extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( SHOPIFY_PULSE_OPTION );
		parent::tear_down();
	}

	public function test_defaults_are_present() {
		delete_option( SHOPIFY_PULSE_OPTION );
		$s = new Shopify_Pulse_Settings();
		$this->assertSame( 1, $s->get( 'active' ) );
		$this->assertSame( 0, $s->get( 'enable_category_sync' ) );
		$this->assertSame( 'both', $s->get( 'product_sync_dir' ) );
		$this->assertSame( 'block', $s->get( 'fraud_action' ) );
	}

	public function test_legacy_catalog_switch_migrates_to_granular() {
		update_option( SHOPIFY_PULSE_OPTION, array(
			'enable_catalog_sync' => 1,
			'catalog_sync_dir'    => 'pull',
		) );

		$s = new Shopify_Pulse_Settings();

		foreach ( array( 'category', 'brand', 'product' ) as $e ) {
			$this->assertSame( 1, $s->get( "enable_{$e}_sync" ), "$e enabled from legacy catalog switch" );
			$this->assertSame( 'pull', $s->get( "{$e}_sync_dir" ), "$e direction inherited" );
		}
	}

	public function test_fresh_install_does_not_enable_granular() {
		update_option( SHOPIFY_PULSE_OPTION, array( 'active' => 1, 'sid' => 'x' ) );
		$s = new Shopify_Pulse_Settings();
		$this->assertSame( 0, $s->get( 'enable_category_sync' ) );
		$this->assertSame( 0, $s->get( 'enable_brand_sync' ) );
	}

	public function test_get_api_base_and_configured() {
		update_option( SHOPIFY_PULSE_OPTION, array(
			'api_base'      => 'https://api.admin.example.com/',
			'sid'           => 'store1',
			'client_id'     => 'wapp_1',
			'client_secret' => 'secret',
		) );
		$s = new Shopify_Pulse_Settings();
		$this->assertSame( 'https://api.admin.example.com', $s->get_api_base() );
		$this->assertTrue( $s->is_configured() );
	}
}
