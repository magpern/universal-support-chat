<?php
/**
 * Operator-facing Support Chat settings admin page (ADR-0015).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Administration\Settings;

use UniversalSupportChat\Administration\Diagnostics\DiagnosticsPage;
use UniversalSupportChat\Administration\Hub\HubPage;
use UniversalSupportChat\ChannelContract\Auth\PeerRecord;
use UniversalSupportChat\ChannelContract\Auth\PeerRepository;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;
use UniversalSupportChat\Core\Configuration\Settings;
use UniversalSupportChat\TelegramDispatch\TelegramDispatchService;

/**
 * A real operator settings screen (ADR-0015 §2). It exposes only the six
 * configuration keys the plugin already owns, through the existing
 * `Settings` option group and the existing `CapabilityRegistrar::MANAGE`
 * capability, using standard WordPress Settings API conventions.
 *
 * It does not register the setting (that stays in `Settings::register()`),
 * does not add any option, default, schema, or capability, and never
 * changes a stored value except when an operator submits the form.
 */
final class SupportChatSettingsPage {

	/**
	 * Submenu page slug (`admin.php?page=<SLUG>`).
	 */
	public const SLUG = 'universal-support-chat-settings';

	private const SECTION_GENERAL      = 'universal_support_chat_settings_general';
	private const SECTION_PRESENTATION = 'universal_support_chat_settings_presentation';
	private const SECTION_LIFECYCLE    = 'universal_support_chat_settings_lifecycle';
	private const SECTION_TELEGRAM     = 'universal_support_chat_settings_telegram';
	private const SECTION_DATA_REMOVAL = 'universal_support_chat_settings_data_removal';

	/**
	 * Admin script handle for the avatar media picker (D5).
	 */
	private const MEDIA_SCRIPT_HANDLE = 'universal-support-chat-settings-media';

	/**
	 * Hook suffix returned by `add_submenu_page()`, captured so the media
	 * picker assets can be enqueued on this page only.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Settings owner.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Peer store (read-only, for the Telegram adapter status panel).
	 *
	 * @var PeerRepository
	 */
	private PeerRepository $peers;

	/**
	 * Constructor.
	 *
	 * @param Settings       $settings Settings owner.
	 * @param PeerRepository $peers    Peer store (read-only).
	 */
	public function __construct( Settings $settings, PeerRepository $peers ) {
		$this->settings = $settings;
		$this->peers    = $peers;
	}

	/**
	 * Registers the page.
	 *
	 * The `option_page_capability_*` filter is added here, synchronously, so
	 * it is in place before `options.php` authorises a Settings API save —
	 * it is deliberately NOT deferred to `admin_init` (ADR-0015 §2).
	 * `admin_init` is used only to register the sections and fields.
	 */
	public function register(): void {
		add_filter(
			'option_page_capability_' . Settings::OPTION_GROUP,
			static function (): string {
				return CapabilityRegistrar::MANAGE;
			}
		);

		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_fields' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_media_picker' ) );
	}

	/**
	 * Adds the Settings submenu under the existing Support Chat menu.
	 */
	public function add_menu(): void {
		$hook_suffix = add_submenu_page(
			HubPage::SLUG,
			__( 'Support Chat Settings', 'universal-support-chat' ),
			__( 'Settings', 'universal-support-chat' ),
			CapabilityRegistrar::MANAGE,
			self::SLUG,
			array( $this, 'render' )
		);

		$this->hook_suffix = is_string( $hook_suffix ) ? $hook_suffix : '';
	}

	/**
	 * Enqueues the WordPress core media modal and the page-scoped avatar
	 * picker script — on this Settings page only (D5). `settings-media.js`
	 * declares `media-editor` as its dependency, so `wp.media` is present.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_media_picker( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script(
			self::MEDIA_SCRIPT_HANDLE,
			plugins_url( 'assets/js/settings-media.js', UNIVERSAL_SUPPORT_CHAT_PLUGIN_FILE ),
			array( 'media-editor' ),
			UNIVERSAL_SUPPORT_CHAT_VERSION,
			true
		);
	}

	/**
	 * Registers the Settings API sections and fields (on `admin_init`).
	 */
	public function register_fields(): void {
		add_settings_section(
			self::SECTION_GENERAL,
			__( 'General', 'universal-support-chat' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Front-end chat widget.', 'universal-support-chat' ) . '</p>';
			},
			self::SLUG
		);

		add_settings_field(
			'widget_enabled',
			__( 'Enable chat widget', 'universal-support-chat' ),
			array( $this, 'render_widget_enabled' ),
			self::SLUG,
			self::SECTION_GENERAL
		);

		add_settings_section(
			self::SECTION_PRESENTATION,
			__( 'Widget presentation', 'universal-support-chat' ),
			static function (): void {
				echo '<p>' . esc_html__( 'How the front-end chat widget introduces itself. All fields are plain text — HTML and Markdown are stripped. Leave the title blank to show the default “Support chat”.', 'universal-support-chat' ) . '</p>';
			},
			self::SLUG
		);

		add_settings_field(
			'widget_title',
			__( 'Widget title', 'universal-support-chat' ),
			array( $this, 'render_widget_title' ),
			self::SLUG,
			self::SECTION_PRESENTATION
		);
		add_settings_field(
			'widget_greeting',
			__( 'Greeting message', 'universal-support-chat' ),
			array( $this, 'render_widget_greeting' ),
			self::SLUG,
			self::SECTION_PRESENTATION
		);
		add_settings_field(
			'widget_avatar_attachment_id',
			__( 'Avatar image', 'universal-support-chat' ),
			array( $this, 'render_widget_avatar' ),
			self::SLUG,
			self::SECTION_PRESENTATION
		);

		add_settings_section(
			self::SECTION_LIFECYCLE,
			__( 'Conversation lifecycle', 'universal-support-chat' ),
			static function (): void {
				echo '<p>' . esc_html__( 'How long conversations and their message bodies are kept. Values are in days; a value below 1 is restored to the current setting when you save.', 'universal-support-chat' ) . '</p>';
			},
			self::SLUG
		);

		add_settings_field(
			'conversation_inactive_days',
			__( 'Days of inactivity before a conversation is closed', 'universal-support-chat' ),
			array( $this, 'render_conversation_inactive_days' ),
			self::SLUG,
			self::SECTION_LIFECYCLE
		);
		add_settings_field(
			'conversation_archived_body_days',
			__( 'Days after archiving before message bodies are cleared', 'universal-support-chat' ),
			array( $this, 'render_conversation_archived_body_days' ),
			self::SLUG,
			self::SECTION_LIFECYCLE
		);
		add_settings_field(
			'conversation_purge_days',
			__( 'Days before a closed conversation is permanently purged', 'universal-support-chat' ),
			array( $this, 'render_conversation_purge_days' ),
			self::SLUG,
			self::SECTION_LIFECYCLE
		);

		add_settings_section(
			self::SECTION_TELEGRAM,
			__( 'Telegram adapter', 'universal-support-chat' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Mirroring of Support Chat messages to a paired Telegram adapter. This page has no pairing, credential, bot, or transport controls.', 'universal-support-chat' ) . '</p>';
			},
			self::SLUG
		);

		add_settings_field(
			'telegram_dispatch_enabled',
			__( 'Mirror new messages to the Telegram adapter', 'universal-support-chat' ),
			array( $this, 'render_telegram_dispatch_enabled' ),
			self::SLUG,
			self::SECTION_TELEGRAM
		);
		add_settings_field(
			'telegram_adapter_status',
			__( 'Adapter status', 'universal-support-chat' ),
			array( $this, 'render_telegram_adapter_status' ),
			self::SLUG,
			self::SECTION_TELEGRAM
		);

		add_settings_section(
			self::SECTION_DATA_REMOVAL,
			__( 'Data removal', 'universal-support-chat' ),
			static function (): void {
				echo '<p>' . esc_html__( 'When enabled, all Support Chat plugin data — conversations, messages, notes, the audit log, pairing keys, and these settings — is permanently deleted, but only if and when the plugin is later uninstalled from the Plugins screen. Deactivating or updating the plugin never deletes anything, and saving this page never deletes anything. Leave this off unless you are deliberately decommissioning Support Chat.', 'universal-support-chat' ) . '</p>';
			},
			self::SLUG
		);

		add_settings_field(
			'remove_data_on_uninstall',
			__( 'Remove all Support Chat data when the plugin is uninstalled', 'universal-support-chat' ),
			array( $this, 'render_remove_data_on_uninstall' ),
			self::SLUG,
			self::SECTION_DATA_REMOVAL
		);
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-support-chat' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Support Chat Settings', 'universal-support-chat' ) . '</h1>';

		settings_errors();

		echo '<form method="post" action="options.php">';
		settings_fields( Settings::OPTION_GROUP );
		do_settings_sections( self::SLUG );
		submit_button();
		echo '</form>';

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . HubPage::SLUG ) ),
			esc_html__( 'View conversations →', 'universal-support-chat' )
		);

		echo '</div>';
	}

	/**
	 * Renders the widget-enabled checkbox with its hidden `0` companion.
	 */
	public function render_widget_enabled(): void {
		$this->checkbox( 'widget_enabled', __( 'Show the chat widget on the site front end.', 'universal-support-chat' ) );
	}

	/**
	 * Renders the widget-title text input (plain text, ≤ 80 chars).
	 */
	public function render_widget_title(): void {
		$this->text(
			'widget_title',
			__( 'Shown in the widget header. Leave blank for the default “Support chat”.', 'universal-support-chat' ),
			80
		);
	}

	/**
	 * Renders the greeting textarea (plain multiline text, ≤ 500 chars).
	 */
	public function render_widget_greeting(): void {
		$this->textarea(
			'widget_greeting',
			__( 'The opening message a visitor sees when they open the widget. Plain text; line breaks are kept.', 'universal-support-chat' ),
			500
		);
	}

	/**
	 * Renders the avatar image control: a hidden attachment-id input, a
	 * thumbnail preview, and the core media picker / remove buttons (D5).
	 * The "Remove" button stores `0`. Server-side validation
	 * (`wp_attachment_is_image()`) is authoritative regardless.
	 */
	public function render_widget_avatar(): void {
		$values = $this->settings->get();
		$id     = (int) $values['widget_avatar_attachment_id'];
		$name   = Settings::OPTION_NAME . '[widget_avatar_attachment_id]';
		$url    = $id > 0 ? wp_get_attachment_image_url( $id, 'thumbnail' ) : false;

		printf(
			'<input type="hidden" id="usc-widget-avatar-id" name="%1$s" value="%2$d" />',
			esc_attr( $name ),
			absint( $id )
		);

		echo '<div id="usc-widget-avatar-preview" class="usc-widget-avatar-preview">';
		if ( is_string( $url ) && '' !== $url ) {
			printf(
				'<img src="%s" alt="" width="64" height="64" style="border-radius:50%%;object-fit:cover;" />',
				esc_url( $url )
			);
		}
		echo '</div>';

		printf(
			'<button type="button" class="button" id="usc-widget-avatar-choose">%s</button> '
			. '<button type="button" class="button" id="usc-widget-avatar-remove">%s</button>',
			esc_html__( 'Choose image', 'universal-support-chat' ),
			esc_html__( 'Remove', 'universal-support-chat' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'An optional image shown next to the title. Images only; decorative. Choose from your Media Library.', 'universal-support-chat' )
		);
	}

	/**
	 * Renders the inactive-days number input.
	 */
	public function render_conversation_inactive_days(): void {
		$this->number( 'conversation_inactive_days', __( 'A conversation with no new message for this many days is closed automatically.', 'universal-support-chat' ) );
	}

	/**
	 * Renders the archived-body-days number input.
	 */
	public function render_conversation_archived_body_days(): void {
		$this->number( 'conversation_archived_body_days', __( 'After a conversation is archived, its message bodies are cleared this many days later. Metadata is retained until purge.', 'universal-support-chat' ) );
	}

	/**
	 * Renders the purge-days number input.
	 */
	public function render_conversation_purge_days(): void {
		$this->number( 'conversation_purge_days', __( 'A closed conversation and all its rows are permanently deleted this many days after closing.', 'universal-support-chat' ) );
	}

	/**
	 * Renders the Telegram-dispatch checkbox with its hidden `0` companion.
	 */
	public function render_telegram_dispatch_enabled(): void {
		$this->checkbox( 'telegram_dispatch_enabled', __( 'When on, new visitor and operator messages are queued for delivery to the paired Telegram adapter.', 'universal-support-chat' ) );
	}

	/**
	 * Renders the read-only Telegram adapter status panel (ADR-0015 §4).
	 *
	 * Built only from Support-Chat-owned in-process data: the saved dispatch
	 * flag and the local `channel_peers` row. It shows no credentials,
	 * tokens, routes, identifiers, timestamps, or errors, and no controls.
	 */
	public function render_telegram_adapter_status(): void {
		$values   = $this->settings->get();
		$dispatch = empty( $values['telegram_dispatch_enabled'] )
			? __( 'disabled', 'universal-support-chat' )
			: __( 'enabled', 'universal-support-chat' );

		$peer = $this->peers->find_by_peer_id( TelegramDispatchService::PEER_ID );

		echo '<p>';
		echo esc_html__( 'Dispatch:', 'universal-support-chat' ) . ' <strong>' . esc_html( $dispatch ) . '</strong><br />';
		echo esc_html__( 'Adapter pairing:', 'universal-support-chat' ) . ' <strong>' . esc_html( self::pairing_label( $peer ) ) . '</strong>';
		echo '</p>';

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . DiagnosticsPage::SLUG ) ),
			esc_html__( 'Full adapter diagnostics →', 'universal-support-chat' )
		);
	}

	/**
	 * Renders the uninstall data-removal checkbox with its hidden `0`
	 * companion. Visible, final, and clearly warned (ADR-0015 §2).
	 */
	public function render_remove_data_on_uninstall(): void {
		$this->checkbox(
			'remove_data_on_uninstall',
			__( 'Permanently delete all Support Chat data if this plugin is uninstalled. Has no effect on save, deactivation, or plugin updates.', 'universal-support-chat' )
		);
	}

	/**
	 * Maps a peer record (or its absence) to a plain operator-facing label.
	 *
	 * @param PeerRecord|null $peer The `universal-telegram` peer row, or null.
	 */
	private static function pairing_label( ?PeerRecord $peer ): string {
		if ( null === $peer ) {
			return __( 'not paired', 'universal-support-chat' );
		}

		switch ( $peer->pairing_state() ) {
			case 'revoked':
				return __( 'pairing revoked', 'universal-support-chat' );
			case 'expired':
				return __( 'pairing expired', 'universal-support-chat' );
			case 'paired_disabled':
				return __( 'paired (disabled)', 'universal-support-chat' );
			default:
				return __( 'paired', 'universal-support-chat' );
		}
	}

	/**
	 * Prints a boolean checkbox field: a hidden `0` companion immediately
	 * followed by the checkbox, so the key is always present in the POST.
	 *
	 * @param string $key         Option array key.
	 * @param string $description Field description text.
	 */
	private function checkbox( string $key, string $description ): void {
		$values  = $this->settings->get();
		$checked = ! empty( $values[ $key ] );
		$name    = Settings::OPTION_NAME . '[' . $key . ']';

		printf(
			'<input type="hidden" name="%1$s" value="0" />'
			. '<label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
			esc_attr( $name ),
			checked( $checked, true, false ),
			esc_html( $description )
		);
	}

	/**
	 * Prints a single-line plain-text field with a maxlength hint.
	 *
	 * @param string $key         Option array key.
	 * @param string $description Field description text.
	 * @param int    $maxlength   Character cap (mirrors `Settings::sanitize()`).
	 */
	private function text( string $key, string $description, int $maxlength ): void {
		$values = $this->settings->get();
		$name   = Settings::OPTION_NAME . '[' . $key . ']';

		printf(
			'<input type="text" class="regular-text" maxlength="%1$d" name="%2$s" value="%3$s" />'
			. '<p class="description">%4$s</p>',
			absint( $maxlength ),
			esc_attr( $name ),
			esc_attr( (string) ( $values[ $key ] ?? '' ) ),
			esc_html( $description )
		);
	}

	/**
	 * Prints a multi-line plain-text field with a maxlength hint.
	 *
	 * @param string $key         Option array key.
	 * @param string $description Field description text.
	 * @param int    $maxlength   Character cap (mirrors `Settings::sanitize()`).
	 */
	private function textarea( string $key, string $description, int $maxlength ): void {
		$values = $this->settings->get();
		$name   = Settings::OPTION_NAME . '[' . $key . ']';

		printf(
			'<textarea class="large-text" rows="3" maxlength="%1$d" name="%2$s">%3$s</textarea>'
			. '<p class="description">%4$s</p>',
			absint( $maxlength ),
			esc_attr( $name ),
			esc_textarea( (string) ( $values[ $key ] ?? '' ) ),
			esc_html( $description )
		);
	}

	/**
	 * Prints a positive-integer number field.
	 *
	 * @param string $key         Option array key.
	 * @param string $description Field description text.
	 */
	private function number( string $key, string $description ): void {
		$values = $this->settings->get();
		$name   = Settings::OPTION_NAME . '[' . $key . ']';

		printf(
			'<input type="number" min="1" step="1" class="small-text" name="%1$s" value="%2$d" />'
			. '<p class="description">%3$s</p>',
			esc_attr( $name ),
			(int) ( $values[ $key ] ?? 0 ),
			esc_html( $description )
		);
	}
}
