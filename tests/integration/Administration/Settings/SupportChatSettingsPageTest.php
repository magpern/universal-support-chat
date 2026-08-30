<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Administration\Settings;

use UniversalSupportChat\Administration\Settings\SupportChatSettingsPage;
use UniversalSupportChat\ChannelContract\Auth\PeerRecord;
use UniversalSupportChat\ChannelContract\Auth\PeerRepository;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;
use UniversalSupportChat\Core\Configuration\Settings;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\TelegramDispatch\TelegramDispatchService;
use WP_UnitTestCase;

/**
 * ADR-0015 §2: the real operator Settings page.
 */
final class SupportChatSettingsPageTest extends WP_UnitTestCase {

	private const CAP_FILTER   = 'option_page_capability_universal_support_chat_settings_group';
	private const FAKE_PUB_KEY = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

	private Settings $settings;
	private PeerRepository $peers;

	public function set_up(): void {
		parent::set_up();

		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		( new CapabilityRegistrar() )->grant_to_administrator();

		$this->settings = new Settings();
		$this->peers    = new PeerRepository( new SchemaHealth() );

		delete_option( Settings::OPTION_NAME );
		$this->truncate_peers();
	}

	public function tear_down(): void {
		delete_option( Settings::OPTION_NAME );
		$this->truncate_peers();
		parent::tear_down();
	}

	private function truncate_peers(): void {
		global $wpdb;
		$table = $wpdb->prefix . Migrator::CHANNEL_PEERS_TABLE;
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup.
	}

	private function page(): SupportChatSettingsPage {
		return new SupportChatSettingsPage( $this->settings, $this->peers );
	}

	private function as_manager(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function render(): string {
		$this->as_manager();
		$page = $this->page();
		$page->register_fields();

		ob_start();
		$page->render();

		return (string) ob_get_clean();
	}

	// ---- menu + capability ----

	public function test_submenu_is_registered_under_the_support_chat_hub_with_manage(): void {
		global $submenu;
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test isolation of a core global.

		$this->as_manager();
		$this->page()->add_menu();

		$entry = null;
		foreach ( $submenu['universal-support-chat-hub'] ?? array() as $row ) {
			if ( SupportChatSettingsPage::SLUG === $row[2] ) {
				$entry = $row;
			}
		}

		$this->assertNotNull( $entry );
		$this->assertSame( CapabilityRegistrar::MANAGE, $entry[1] );
		$this->assertSame( 'Settings', $entry[0] );
	}

	public function test_capability_filter_is_added_in_register_not_on_admin_init(): void {
		remove_all_filters( self::CAP_FILTER );
		$this->assertFalse( has_filter( self::CAP_FILTER ) );

		$this->page()->register();

		// Present immediately — no admin_init needed.
		$this->assertNotFalse( has_filter( self::CAP_FILTER ) );
		$this->assertSame(
			CapabilityRegistrar::MANAGE,
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- WordPress core hook `option_page_capability_{group}`.
			apply_filters( self::CAP_FILTER, 'manage_options' )
		);
	}

	public function test_fields_are_registered_only_after_admin_init(): void {
		global $wp_settings_sections, $wp_settings_fields;
		unset( $wp_settings_sections[ SupportChatSettingsPage::SLUG ], $wp_settings_fields[ SupportChatSettingsPage::SLUG ] );

		$page = $this->page();
		$page->register();

		$this->assertArrayNotHasKey( SupportChatSettingsPage::SLUG, (array) $wp_settings_sections );

		$page->register_fields(); // what the admin_init hook does

		$this->assertArrayHasKey( SupportChatSettingsPage::SLUG, $wp_settings_sections );
		$this->assertArrayHasKey( SupportChatSettingsPage::SLUG, $wp_settings_fields );
	}

	public function test_render_denies_a_user_without_manage(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( \WPDieException::class );
		$this->page()->render();
	}

	// ---- form contract ----

	public function test_form_carries_the_settings_api_nonce_and_option_group(): void {
		$html = $this->render();

		$this->assertStringContainsString( '<form method="post" action="options.php">', $html );
		$this->assertStringContainsString( 'universal_support_chat_settings_group', $html );
		$this->assertStringContainsString( '_wpnonce', $html );
		$this->assertStringContainsString( 'Support Chat Settings', $html );
	}

	public function test_all_six_option_keys_are_rendered_as_controls(): void {
		$html = $this->render();

		foreach (
			array(
				'widget_enabled',
				'conversation_inactive_days',
				'conversation_archived_body_days',
				'conversation_purge_days',
				'telegram_dispatch_enabled',
				'remove_data_on_uninstall',
			) as $key
		) {
			$this->assertStringContainsString( 'name="' . Settings::OPTION_NAME . '[' . $key . ']"', $html, $key );
		}
	}

	// ---- SC-M05: Widget presentation section (ADR-0016) ----

	public function test_widget_presentation_section_is_registered(): void {
		global $wp_settings_sections;

		$this->as_manager();
		$this->page()->register_fields();

		$this->assertArrayHasKey(
			'universal_support_chat_settings_presentation',
			$wp_settings_sections[ SupportChatSettingsPage::SLUG ]
		);
		$this->assertSame(
			'Widget presentation',
			$wp_settings_sections[ SupportChatSettingsPage::SLUG ]['universal_support_chat_settings_presentation']['title']
		);
	}

	public function test_presentation_fields_render_as_the_right_controls(): void {
		$html = $this->render();

		$this->assertMatchesRegularExpression(
			'/<input type="text"[^>]*maxlength="80"[^>]*name="' . preg_quote( Settings::OPTION_NAME . '[widget_title]', '/' ) . '"/',
			$html
		);
		$this->assertMatchesRegularExpression(
			'/<textarea[^>]*maxlength="500"[^>]*name="' . preg_quote( Settings::OPTION_NAME . '[widget_greeting]', '/' ) . '"/',
			$html
		);
		$this->assertStringContainsString(
			'<input type="hidden" id="usc-widget-avatar-id" name="' . Settings::OPTION_NAME . '[widget_avatar_attachment_id]"',
			$html
		);
		$this->assertStringContainsString( 'id="usc-widget-avatar-choose"', $html );
		$this->assertStringContainsString( 'id="usc-widget-avatar-remove"', $html );
	}

	public function test_greeting_default_is_shown_when_unset(): void {
		$this->assertStringContainsString( 'Hi — how can we help?', $this->render() );
	}

	public function test_script_in_title_round_trips_escaped_into_the_field_value(): void {
		$saved = $this->settings->sanitize( array( 'widget_title' => '<script>alert(1)</script>Team' ) );
		update_option( Settings::OPTION_NAME, $saved + $this->settings->defaults() );

		$html = $this->render();

		$this->assertStringContainsString( 'value="Team"', $html );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
	}

	public function test_media_picker_loads_only_on_the_settings_page_hook(): void {
		$this->as_manager();
		$page = $this->page();
		$page->add_menu();

		$prop = new \ReflectionProperty( SupportChatSettingsPage::class, 'hook_suffix' );
		$prop->setAccessible( true );
		$hook = (string) $prop->getValue( $page );
		$this->assertNotSame( '', $hook );

		$page->enqueue_media_picker( 'index.php' );
		$this->assertFalse( wp_script_is( 'universal-support-chat-settings-media', 'enqueued' ) );

		$page->enqueue_media_picker( $hook );
		$this->assertTrue( wp_script_is( 'universal-support-chat-settings-media', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'media-editor', 'enqueued' ) );
	}

	public function test_every_checkbox_has_a_hidden_zero_companion(): void {
		$html = $this->render();

		foreach ( array( 'widget_enabled', 'telegram_dispatch_enabled', 'remove_data_on_uninstall' ) as $key ) {
			$name = Settings::OPTION_NAME . '[' . $key . ']';
			$this->assertStringContainsString( '<input type="hidden" name="' . $name . '" value="0" />', $html, $key );
			$this->assertStringContainsString( '<input type="checkbox" name="' . $name . '" value="1"', $html, $key );
		}
	}

	public function test_retention_fields_are_number_inputs_with_min_one(): void {
		$html = $this->render();

		foreach ( array( 'conversation_inactive_days', 'conversation_archived_body_days', 'conversation_purge_days' ) as $key ) {
			$this->assertMatchesRegularExpression(
				'/<input type="number" min="1" step="1"[^>]*name="' . preg_quote( Settings::OPTION_NAME . '[' . $key . ']', '/' ) . '"/',
				$html,
				$key
			);
		}
	}

	// ---- data removal setting ----

	public function test_data_removal_section_is_visible_with_label_and_warning(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'Data removal', $html );
		$this->assertStringContainsString( 'Remove all Support Chat data when the plugin is uninstalled', $html );
		$this->assertStringContainsString( 'only if and when the plugin is later uninstalled', $html );
		$this->assertStringContainsString( 'saving this page never deletes anything', $html );
	}

	public function test_data_removal_checkbox_reflects_stored_value_and_defaults_off(): void {
		$name = Settings::OPTION_NAME . '[remove_data_on_uninstall]';

		$off = $this->render();
		$this->assertStringContainsString(
			'<input type="checkbox" name="' . $name . '" value="1" ' . checked( false, true, false ) . ' />',
			$off
		);

		update_option( Settings::OPTION_NAME, array( 'remove_data_on_uninstall' => true ) + $this->settings->defaults() );
		$on = $this->render();
		$this->assertStringContainsString(
			'<input type="checkbox" name="' . $name . '" value="1" ' . checked( true, true, false ) . ' />',
			$on
		);
	}

	public function test_no_hidden_preserve_field_for_remove_data_on_uninstall(): void {
		$html = $this->render();
		$name = Settings::OPTION_NAME . '[remove_data_on_uninstall]';

		// Exactly one hidden input for this key — the value="0" companion —
		// never a hidden field echoing the current stored value.
		$this->assertSame( 1, substr_count( $html, '<input type="hidden" name="' . $name . '"' ) );
		$this->assertStringContainsString( '<input type="hidden" name="' . $name . '" value="0" />', $html );
	}

	// ---- save behaviour: never deletes data ----

	public function test_saving_settings_does_not_drop_tables_or_run_the_uninstaller(): void {
		global $wpdb;
		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;

		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe.

		$saved = $this->settings->sanitize(
			array(
				'widget_enabled'            => '0',
				'telegram_dispatch_enabled' => '1',
				'remove_data_on_uninstall'  => '1',
			)
		);
		update_option( Settings::OPTION_NAME, $saved );

		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'no table dropped on save' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe.
		$this->assertTrue( $this->settings->get()['remove_data_on_uninstall'] );
		$this->assertFalse( $this->settings->get()['widget_enabled'] );
	}

	// ---- Telegram adapter status panel ----

	public function test_telegram_panel_shows_dispatch_state_and_not_paired(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'Adapter status', $html );
		$this->assertStringContainsString( 'Dispatch:', $html );
		$this->assertStringContainsString( 'disabled', $html );
		$this->assertStringContainsString( 'not paired', $html );
	}

	public function test_telegram_panel_reports_paired_and_leaks_no_secrets(): void {
		$this->peers->create(
			TelegramDispatchService::PEER_ID,
			self::FAKE_PUB_KEY,
			'usc_test_key_id_abcdef',
			array( 'ingest_operator_reply' ),
			null,
			null,
			'universal-telegram/v1/support-chat'
		);

		$html = $this->render();

		$this->assertStringContainsString( 'paired', $html );

		foreach ( array( 'usc_test_key_id_abcdef', 'universal-telegram/v1/support-chat', self::FAKE_PUB_KEY ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $html );
		}
	}

	public function test_pairing_label_helper_covers_all_states(): void {
		$method = new \ReflectionMethod( SupportChatSettingsPage::class, 'pairing_label' );
		$method->setAccessible( true );

		$this->assertSame( 'not paired', $method->invoke( null, null ) );
		$this->assertSame( 'paired', $method->invoke( null, $this->peer( PeerRecord::STATUS_ACTIVE, null ) ) );
		$this->assertSame( 'paired (disabled)', $method->invoke( null, $this->peer( PeerRecord::STATUS_DISABLED, null ) ) );
		$this->assertSame( 'pairing revoked', $method->invoke( null, $this->peer( PeerRecord::STATUS_REVOKED, null ) ) );
		$this->assertSame( 'pairing expired', $method->invoke( null, $this->peer( PeerRecord::STATUS_ACTIVE, '2000-01-01 00:00:00' ) ) );
	}

	private function peer( string $status, ?string $expires_at ): PeerRecord {
		return new PeerRecord(
			1,
			TelegramDispatchService::PEER_ID,
			self::FAKE_PUB_KEY,
			'key-id',
			array(),
			null,
			$status,
			'2024-01-01 00:00:00',
			null,
			null,
			$expires_at,
			null,
			null
		);
	}
}
