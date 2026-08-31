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
use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\ChannelContract\Auth\PeerRecord;
use UniversalSupportChat\ChannelContract\Auth\PeerRepository;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;
use UniversalSupportChat\Core\Configuration\Settings;
use UniversalSupportChat\Privacy\Classification;
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
	private const SECTION_AVAILABILITY = 'universal_support_chat_settings_availability';
	private const SECTION_LIFECYCLE    = 'universal_support_chat_settings_lifecycle';
	private const SECTION_TELEGRAM     = 'universal_support_chat_settings_telegram';
	private const SECTION_DATA_REMOVAL = 'universal_support_chat_settings_data_removal';

	/**
	 * Weekday storage keys, in display order.
	 */
	private const WEEKDAYS = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

	/**
	 * Interval slots offered per weekday on the form.
	 */
	private const SCHEDULE_SLOTS = 3;

	/**
	 * Exception rows offered on the form.
	 */
	private const EXCEPTION_ROWS = 5;

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
	 * Audit logger for successful availability-config changes (ADR-0017), or null.
	 *
	 * @var AuditLogger|null
	 */
	private ?AuditLogger $audit;

	/**
	 * Constructor.
	 *
	 * @param Settings         $settings Settings owner.
	 * @param PeerRepository   $peers    Peer store (read-only).
	 * @param AuditLogger|null $audit    Optional audit logger.
	 */
	public function __construct( Settings $settings, PeerRepository $peers, ?AuditLogger $audit = null ) {
		$this->settings = $settings;
		$this->peers    = $peers;
		$this->audit    = $audit;
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

		// ADR-0017: record a safe INTERNAL audit event when a save actually
		// changes the weekly schedule or the date exceptions. `updated_option`
		// fires only on a real change; `added_option` covers the first save.
		add_action(
			'updated_option',
			function ( $option, $old_value, $value ): void {
				if ( Settings::OPTION_NAME === $option ) {
					$this->audit_availability_changes(
						is_array( $old_value ) ? $old_value : array(),
						is_array( $value ) ? $value : array()
					);
				}
			},
			10,
			3
		);
		add_action(
			'added_option',
			function ( $option, $value ): void {
				if ( Settings::OPTION_NAME === $option ) {
					$this->audit_availability_changes( $this->settings->defaults(), is_array( $value ) ? $value : array() );
				}
			},
			10,
			2
		);
	}

	/**
	 * Records `availability.schedule_updated` / `availability.exceptions_updated`
	 * when a save changed the corresponding stored value. Context carries only
	 * a change marker — never schedule times, exception dates, copy, or any
	 * identifier (ADR-0017 Security and privacy impact).
	 *
	 * @param array<string, mixed> $old Previous option array.
	 * @param array<string, mixed> $updated New option array.
	 */
	private function audit_availability_changes( array $old, array $updated ): void {
		if ( null === $this->audit ) {
			return;
		}

		$events = array(
			'availability_schedule'   => 'availability.schedule_updated',
			'availability_exceptions' => 'availability.exceptions_updated',
		);

		$user_id    = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		$actor_type = $user_id > 0 ? 'operator' : 'system';

		foreach ( $events as $key => $action ) {
			if ( ( $old[ $key ] ?? null ) === ( $updated[ $key ] ?? null ) ) {
				continue;
			}

			$this->audit->record(
				$action,
				$actor_type,
				$user_id,
				array( 'changed' => 'yes' ),
				array( 'changed' => Classification::PUBLIC ),
				Classification::INTERNAL
			);
		}
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
			self::SECTION_AVAILABILITY,
			__( 'Support availability', 'universal-support-chat' ),
			static function (): void {
				echo '<p>' . esc_html__( 'When the support team is available, evaluated in the site timezone. Outside these hours the widget honestly shows the team as offline and a visitor can still leave a message that becomes a normal conversation. Times are 24-hour HH:MM; leave a slot blank to skip it, and leave a whole day blank to close it. An invalid time is rejected and your previous schedule is kept.', 'universal-support-chat' ) . '</p>';
			},
			self::SLUG
		);

		add_settings_field(
			'availability_schedule',
			__( 'Weekly schedule', 'universal-support-chat' ),
			array( $this, 'render_availability_schedule' ),
			self::SLUG,
			self::SECTION_AVAILABILITY
		);
		add_settings_field(
			'availability_exceptions',
			__( 'Date exceptions', 'universal-support-chat' ),
			array( $this, 'render_availability_exceptions' ),
			self::SLUG,
			self::SECTION_AVAILABILITY
		);
		add_settings_field(
			'availability_offline_message',
			__( 'Offline message', 'universal-support-chat' ),
			array( $this, 'render_availability_offline_message' ),
			self::SLUG,
			self::SECTION_AVAILABILITY
		);
		add_settings_field(
			'availability_online_indicator',
			__( '“We’re online” indicator', 'universal-support-chat' ),
			array( $this, 'render_availability_online_indicator' ),
			self::SLUG,
			self::SECTION_AVAILABILITY
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
	 * Renders the weekly schedule grid. Field names build the canonical
	 * `{ mon: [ { start, end }, … ], … }` shape that `Settings::sanitize()`
	 * validates atomically.
	 */
	public function render_availability_schedule(): void {
		$stored = $this->settings->get()['availability_schedule'];
		$name   = Settings::OPTION_NAME . '[availability_schedule]';

		$labels = array(
			'mon' => __( 'Monday', 'universal-support-chat' ),
			'tue' => __( 'Tuesday', 'universal-support-chat' ),
			'wed' => __( 'Wednesday', 'universal-support-chat' ),
			'thu' => __( 'Thursday', 'universal-support-chat' ),
			'fri' => __( 'Friday', 'universal-support-chat' ),
			'sat' => __( 'Saturday', 'universal-support-chat' ),
			'sun' => __( 'Sunday', 'universal-support-chat' ),
		);

		echo '<table class="widefat striped" style="max-width:640px;"><tbody>';

		foreach ( self::WEEKDAYS as $day ) {
			$intervals = isset( $stored[ $day ] ) ? array_values( $stored[ $day ] ) : array();

			echo '<tr><th scope="row" style="width:8rem;">' . esc_html( $labels[ $day ] ) . '</th><td>';

			for ( $slot = 0; $slot < self::SCHEDULE_SLOTS; $slot++ ) {
				$start = isset( $intervals[ $slot ]['start'] ) ? (string) $intervals[ $slot ]['start'] : '';
				$end   = isset( $intervals[ $slot ]['end'] ) ? (string) $intervals[ $slot ]['end'] : '';

				printf(
					'<span style="display:inline-block;margin:0 1rem 0.35rem 0;white-space:nowrap;">'
					. '<input type="time" aria-label="%1$s" name="%2$s[%3$s][%4$d][start]" value="%5$s" /> &ndash; '
					. '<input type="time" aria-label="%6$s" name="%2$s[%3$s][%4$d][end]" value="%7$s" /></span>',
					esc_attr( sprintf( /* translators: %s: weekday. */ __( '%s opening time', 'universal-support-chat' ), $labels[ $day ] ) ),
					esc_attr( $name ),
					esc_attr( $day ),
					(int) $slot,
					esc_attr( $start ),
					esc_attr( sprintf( /* translators: %s: weekday. */ __( '%s closing time', 'universal-support-chat' ), $labels[ $day ] ) ),
					esc_attr( $end )
				);
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Renders the date-exception rows. Field names build the form's row
	 * shape (`[ { date, mode, start, end }, … ]`), converted to the
	 * canonical date map by `ExceptionSet::from_array()`.
	 */
	public function render_availability_exceptions(): void {
		$stored = $this->settings->get()['availability_exceptions'];
		$name   = Settings::OPTION_NAME . '[availability_exceptions]';

		$rows = array();
		foreach ( $stored as $date => $value ) {
			if ( 'closed' === $value ) {
				$rows[] = array(
					'date'  => (string) $date,
					'mode'  => 'closed',
					'start' => '',
					'end'   => '',
				);
			} elseif ( isset( $value[0]['start'] ) ) {
				$rows[] = array(
					'date'  => (string) $date,
					'mode'  => 'hours',
					'start' => (string) $value[0]['start'],
					'end'   => (string) $value[0]['end'],
				);
			}
		}

		echo '<table class="widefat striped" style="max-width:640px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Date', 'universal-support-chat' ) . '</th>';
		echo '<th>' . esc_html__( 'Then', 'universal-support-chat' ) . '</th>';
		echo '<th>' . esc_html__( 'Special hours', 'universal-support-chat' ) . '</th>';
		echo '</tr></thead><tbody>';

		for ( $i = 0; $i < self::EXCEPTION_ROWS; $i++ ) {
			$row  = $rows[ $i ] ?? array(
				'date'  => '',
				'mode'  => 'closed',
				'start' => '',
				'end'   => '',
			);
			$base = $name . '[' . (int) $i . ']';

			echo '<tr><td>';
			printf( '<input type="date" name="%s[date]" value="%s" />', esc_attr( $base ), esc_attr( $row['date'] ) );
			echo '</td><td>';
			printf(
				'<select name="%s[mode]"><option value="closed"%s>%s</option><option value="hours"%s>%s</option></select>',
				esc_attr( $base ),
				selected( $row['mode'], 'closed', false ),
				esc_html__( 'Closed all day', 'universal-support-chat' ),
				selected( $row['mode'], 'hours', false ),
				esc_html__( 'Open only these hours', 'universal-support-chat' )
			);
			echo '</td><td>';
			printf(
				'<input type="time" aria-label="%s" name="%s[start]" value="%s" /> &ndash; <input type="time" aria-label="%s" name="%s[end]" value="%s" />',
				esc_attr__( 'Exception opening time', 'universal-support-chat' ),
				esc_attr( $base ),
				esc_attr( $row['start'] ),
				esc_attr__( 'Exception closing time', 'universal-support-chat' ),
				esc_attr( $base ),
				esc_attr( $row['end'] )
			);
			echo '</td></tr>';
		}

		echo '</tbody></table>';
		printf( '<p class="description">%s</p>', esc_html__( 'A “Closed all day” exception overrides the weekly schedule for that date. “Open only these hours” replaces that date’s weekly hours. Clear a row’s date to remove it.', 'universal-support-chat' ) );
	}

	/**
	 * Renders the offline-message textarea (plain multiline text, ≤ 500).
	 */
	public function render_availability_offline_message(): void {
		$this->textarea(
			'availability_offline_message',
			__( 'Shown in the widget when the team is offline. Plain text; no time estimate or promise. Leave blank to use the default wording.', 'universal-support-chat' ),
			500
		);
	}

	/**
	 * Renders the online-indicator checkbox with its hidden `0` companion.
	 */
	public function render_availability_online_indicator(): void {
		$this->checkbox(
			'availability_online_indicator',
			__( 'Show a subtle “We’re online” badge in the widget header — only ever while the team is genuinely available.', 'universal-support-chat' )
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
