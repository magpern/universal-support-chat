<?php
/**
 * Visitor conversation REST routes.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Conversations\Rest;

use UniversalSupportChat\AI\Turn\AiResponder;
use UniversalSupportChat\AI\Turn\AiTurnRepository;
use UniversalSupportChat\Availability\AvailabilityService;
use UniversalSupportChat\Conversations\Conversation;
use UniversalSupportChat\Conversations\ConversationMessage;
use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\ConversationStatus;
use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\TelegramDispatch\DispatchEnqueuer;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `universal-support-chat/v1/conversations*` — authenticated cookie + nonce only.
 * Uniform 404 for unknown/unauthorised conversation access.
 */
final class ConversationsController {

	public const ROUTE_NAMESPACE = 'universal-support-chat/v1';

	private const MAX_TEXT_CHARS = 4096;

	/**
	 * Schema availability gate.
	 *
	 * @var SchemaHealth
	 */
	private SchemaHealth $schema_health;

	/**
	 * Conversation repository.
	 *
	 * @var ConversationRepository
	 */
	private ConversationRepository $conversations;

	/**
	 * Message repository.
	 *
	 * @var MessageRepository
	 */
	private MessageRepository $messages;

	/**
	 * Optional Telegram dispatch enqueuer (ADR-0012).
	 *
	 * @var DispatchEnqueuer|null
	 */
	private ?DispatchEnqueuer $dispatch;

	/**
	 * Optional availability service (ADR-0017). When absent the controller
	 * behaves exactly as it did before SC-M06 (every request is "available").
	 *
	 * @var AvailabilityService|null
	 */
	private ?AvailabilityService $availability;

	/**
	 * Optional AI turn repository (ADR-0018, SC-M07). When present, the poll
	 * response carries an `ai_pending` flag so the widget can show an honest
	 * "the assistant is replying" state. Never triggers a provider call —
	 * that is the async worker's job.
	 *
	 * @var AiTurnRepository|null
	 */
	private ?AiTurnRepository $ai_turns;

	/**
	 * Optional AI responder (ADR-0018, SC-M07). When present and the
	 * conversation is AI-eligible, an accepted visitor message is committed
	 * together with a queued `ai_turns` row and a non-blocking worker kick —
	 * still no provider call in this request.
	 *
	 * @var AiResponder|null
	 */
	private ?AiResponder $ai_responder;

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth             $schema_health Schema availability gate.
	 * @param ConversationRepository   $conversations Conversation repository.
	 * @param MessageRepository        $messages      Message repository.
	 * @param DispatchEnqueuer|null    $dispatch      Optional Telegram dispatch enqueuer.
	 * @param AvailabilityService|null $availability   Optional availability service.
	 * @param AiTurnRepository|null    $ai_turns      Optional AI turn repository (SC-M07).
	 * @param AiResponder|null         $ai_responder  Optional AI responder (SC-M07).
	 */
	public function __construct(
		SchemaHealth $schema_health,
		ConversationRepository $conversations,
		MessageRepository $messages,
		?DispatchEnqueuer $dispatch = null,
		?AvailabilityService $availability = null,
		?AiTurnRepository $ai_turns = null,
		?AiResponder $ai_responder = null
	) {
		$this->schema_health = $schema_health;
		$this->conversations = $conversations;
		$this->messages      = $messages;
		$this->dispatch      = $dispatch;
		$this->availability  = $availability;
		$this->ai_turns      = $ai_turns;
		$this->ai_responder  = $ai_responder;
	}

	/**
	 * The visitor-facing author label for a message direction.
	 *
	 * @param string $direction Message direction.
	 */
	private static function author_label( string $direction ): string {
		switch ( $direction ) {
			case ConversationMessage::DIRECTION_VISITOR:
				return 'You';
			case ConversationMessage::DIRECTION_AI:
				return 'AI assistant';
			default:
				return 'Support team';
		}
	}

	/**
	 * Registers WordPress hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/conversations',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_start' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/conversations/mine',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_mine' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/conversations/(?P<conversation_uuid>[0-9a-fA-F\-]{36})/messages',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_post_message' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/conversations/(?P<conversation_uuid>[0-9a-fA-F\-]{36})',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_poll' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Starts or resumes the visitor's active conversation.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function handle_start( WP_REST_Request $request ): WP_REST_Response {
		$user_id = $this->authenticate_session();
		if ( null === $user_id ) {
			return $this->error( 'auth_required', 401 );
		}

		if ( ! $this->schema_health->is_available() ) {
			return $this->error( 'unavailable', 503 );
		}

		$idempotency = $request->get_param( 'idempotency_key' );
		$idempotency = is_string( $idempotency ) && $this->is_uuid( $idempotency ) ? $idempotency : null;

		if ( null !== $idempotency ) {
			$existing = $this->conversations->find_by_start_idempotency_key( $idempotency );
			if ( null !== $existing ) {
				if ( $existing->owner_user_id() !== $user_id ) {
					return $this->not_found();
				}
				return $this->ok(
					array(
						'conversation_uuid' => $existing->uuid(),
						'availability'      => $this->availability_state(),
					)
				);
			}
		}

		$active = $this->conversations->find_active_for_owner( $user_id );
		if ( null !== $active ) {
			return $this->ok(
				array(
					'conversation_uuid' => $active->uuid(),
					'availability'      => $this->availability_state(),
				)
			);
		}

		$created = $this->conversations->create( $user_id, $idempotency );
		if ( null === $created ) {
			return $this->error( 'request_failed', 500 );
		}

		$opened = $this->conversations->transition( $created, ConversationStatus::OPEN );
		$final  = $opened ?? $created;

		return $this->ok(
			array(
				'conversation_uuid' => $final->uuid(),
				'availability'      => $this->availability_state(),
			)
		);
	}

	/**
	 * Returns the visitor's active conversation UUID, if any.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function handle_mine( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$user_id = $this->authenticate_session();
		if ( null === $user_id ) {
			return $this->error( 'auth_required', 401 );
		}

		if ( ! $this->schema_health->is_available() ) {
			return $this->error( 'unavailable', 503 );
		}

		$active = $this->conversations->find_active_for_owner( $user_id );

		return $this->ok(
			array(
				'conversation_uuid' => null !== $active ? $active->uuid() : null,
			)
		);
	}

	/**
	 * Posts a visitor message.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function handle_post_message( WP_REST_Request $request ): WP_REST_Response {
		$user_id = $this->authenticate_session();
		if ( null === $user_id ) {
			return $this->error( 'auth_required', 401 );
		}

		if ( ! $this->schema_health->is_available() ) {
			return $this->error( 'unavailable', 503 );
		}

		$conversation = $this->authorize_owned_conversation( (string) $request['conversation_uuid'], $user_id );
		if ( null === $conversation ) {
			return $this->not_found();
		}

		if ( ConversationStatus::ARCHIVED === $conversation->status() || ConversationStatus::RESOLVED === $conversation->status() ) {
			return $this->error( 'conversation_closed', 409 );
		}

		$text = $request->get_param( 'text' );
		if ( ! is_string( $text ) ) {
			return $this->error( 'invalid_text', 400 );
		}

		$text = trim( wp_check_invalid_utf8( $text ) );
		if ( '' === $text || strlen( $text ) > self::MAX_TEXT_CHARS ) {
			return $this->error( 'invalid_text', 400 );
		}

		$idempotency = $request->get_param( 'idempotency_key' );
		$idempotency = is_string( $idempotency ) && $this->is_uuid( $idempotency ) ? $idempotency : null;

		$create = fn (): ?ConversationMessage => $this->messages->create(
			$conversation->id(),
			ConversationMessage::DIRECTION_VISITOR,
			$text,
			'stored',
			$idempotency
		);

		// Availability is resolved authoritatively on the server (ADR-0017
		// §7); anything the browser believed is presentation only.
		$unavailable = null !== $this->availability && $this->availability->is_unavailable();

		if ( $unavailable ) {
			// Offline ticket: commit the message, its ADR-0012 outbox row
			// (only when dispatch is enabled), and the transition to
			// waiting_for_operator as ONE unit of work. A failed transition
			// rolls the message back — no orphan message in the wrong status.
			$message = $this->persist_visitor_message_offline( $conversation, $create );
		} elseif ( null !== $this->ai_responder && $this->ai_responder->is_eligible( $conversation ) ) {
			// SC-M07 (ADR-0018 §2): commit the visitor message, its ADR-0012
			// outbox row (when dispatch is on), and a queued `ai_turns` row
			// as ONE unit, then fire a non-blocking worker kick. The provider
			// is NOT called in this request.
			$message = $this->ai_responder->persist_with_turn(
				$conversation->uuid(),
				$conversation->id(),
				$user_id,
				$create
			);
		} else {
			// When Telegram dispatch is enabled the message row and its
			// outbox row are written in one transaction (ADR-0012);
			// otherwise this is a plain message create.
			$message = null !== $this->dispatch
				? $this->dispatch->persist_and_enqueue( $conversation->uuid(), $create )
				: $create();
		}

		if ( null === $message ) {
			return $this->error( 'request_failed', 503 );
		}

		if ( ! $unavailable ) {
			if ( ConversationStatus::NEW === $conversation->status() ) {
				$this->conversations->transition( $conversation, ConversationStatus::OPEN );
			} elseif ( ConversationStatus::WAITING_FOR_VISITOR === $conversation->status() ) {
				$this->conversations->transition( $conversation, ConversationStatus::OPEN );
			} else {
				$this->conversations->touch( $conversation );
			}
		}

		return $this->ok(
			array(
				'message_uuid' => $message->uuid(),
				'availability' => $unavailable ? 'unavailable' : 'available',
			)
		);
	}

	/**
	 * Commits a visitor message left while the team is unavailable together
	 * with the conversation transition to `waiting_for_operator`, in one
	 * transaction (ADR-0017 §7). When a dispatch enqueuer is wired the
	 * commit also carries the content-free ADR-0012 outbox row; when it is
	 * not (a lean test harness) the message + transition are still atomic.
	 *
	 * @param Conversation                          $conversation Owned, non-terminal conversation.
	 * @param callable(): (ConversationMessage|null) $create       Creates the visitor message row.
	 */
	private function persist_visitor_message_offline( Conversation $conversation, callable $create ): ?ConversationMessage {
		$transition = function ( ConversationMessage $message ) use ( $conversation ): bool {
			unset( $message );

			if ( ConversationStatus::WAITING_FOR_OPERATOR === $conversation->status() ) {
				$this->conversations->touch( $conversation );

				return true;
			}

			return null !== $this->conversations->transition( $conversation, ConversationStatus::WAITING_FOR_OPERATOR );
		};

		if ( null !== $this->dispatch ) {
			return $this->dispatch->persist_and_enqueue( $conversation->uuid(), $create, $transition );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- transaction control statement.
		$wpdb->query( 'START TRANSACTION' );

		try {
			$message = $create();

			if ( ! $message instanceof ConversationMessage ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- transaction control statement.
				$wpdb->query( 'ROLLBACK' );

				return null;
			}

			if ( ! $transition( $message ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- transaction control statement.
				$wpdb->query( 'ROLLBACK' );

				return null;
			}
		} catch ( \Throwable $exception ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- transaction control statement.
			$wpdb->query( 'ROLLBACK' );

			throw $exception;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- transaction control statement.
		$wpdb->query( 'COMMIT' );

		return $message;
	}

	/**
	 * The resolved availability state as a wire string.
	 */
	private function availability_state(): string {
		return null !== $this->availability && $this->availability->is_unavailable() ? 'unavailable' : 'available';
	}

	/**
	 * Polls visitor-visible conversation state and messages.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function handle_poll( WP_REST_Request $request ): WP_REST_Response {
		$user_id = $this->authenticate_session();
		if ( null === $user_id ) {
			return $this->error( 'auth_required', 401 );
		}

		if ( ! $this->schema_health->is_available() ) {
			return $this->error( 'unavailable', 503 );
		}

		$conversation = $this->authorize_owned_conversation( (string) $request['conversation_uuid'], $user_id );
		if ( null === $conversation ) {
			return $this->not_found();
		}

		$after = $request->get_param( 'after_id' );
		$after = is_numeric( $after ) ? max( 0, (int) $after ) : 0;

		$messages = $this->messages->list_for_conversation( $conversation->id(), $after, 100 );
		$payload  = array();

		foreach ( $messages as $message ) {
			$payload[] = array(
				'id'             => $message->id(),
				'message_uuid'   => $message->uuid(),
				'direction'      => $message->direction(),
				'author_label'   => self::author_label( $message->direction() ),
				'text'           => $message->plaintext_body(),
				'created_at'     => $message->created_at(),
				'delivery_state' => $message->delivery_state(),
			);
		}

		return $this->ok(
			array(
				'status'       => $conversation->status(),
				'messages'     => $payload,
				'availability' => $this->availability_state(),
				'ai_pending'   => null !== $this->ai_turns && $this->ai_turns->has_pending_turn( $conversation->id() ),
			)
		);
	}

	/**
	 * Authenticates the logged-in visitor via cookie + REST nonce.
	 */
	private function authenticate_session(): ?int {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		$nonce = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_WP_NONCE'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return null;
		}

		return get_current_user_id();
	}

	/**
	 * Returns the conversation only when owned by $user_id.
	 *
	 * @param string $uuid    Conversation UUID.
	 * @param int    $user_id Authenticated user ID.
	 */
	private function authorize_owned_conversation( string $uuid, int $user_id ): ?Conversation {
		if ( ! $this->is_uuid( $uuid ) ) {
			return null;
		}

		$conversation = $this->conversations->find_by_uuid( $uuid );
		if ( null === $conversation ) {
			return null;
		}

		if ( $conversation->owner_user_id() !== $user_id ) {
			return null;
		}

		return $conversation;
	}

	/**
	 * Whether the value is a UUID v1–v5 string.
	 *
	 * @param string $value Candidate UUID.
	 */
	private function is_uuid( string $value ): bool {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value );
	}

	/**
	 * Success envelope.
	 *
	 * @param array<string, mixed> $data Payload.
	 */
	private function ok( array $data ): WP_REST_Response {
		return new WP_REST_Response( array_merge( array( 'ok' => true ), $data ), 200 );
	}

	/**
	 * Error envelope.
	 *
	 * @param string $reason Machine-readable reason.
	 * @param int    $status HTTP status.
	 */
	private function error( string $reason, int $status ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'ok'     => false,
				'reason' => $reason,
			),
			$status
		);
	}

	/**
	 * Uniform not-found response.
	 */
	private function not_found(): WP_REST_Response {
		return $this->error( 'not_found', 404 );
	}
}
