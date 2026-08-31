(function () {
	'use strict';

	function init() {
		var cfg = window.uscChatWidget;
		if (!cfg || !cfg.i18n) {
			return;
		}

		var root = document.getElementById('usc-chat-root');
		if (!root) {
			return;
		}
		root.hidden = false;

		var launcher = document.getElementById('usc-chat-launcher');
		var panel = document.getElementById('usc-chat-panel');
		var closeBtn = document.getElementById('usc-chat-close');
		var introEl = document.getElementById('usc-chat-intro');
		var statusEl = document.getElementById('usc-chat-status');
		var messagesEl = document.getElementById('usc-chat-messages');
		var form = document.getElementById('usc-chat-form');
		var input = document.getElementById('usc-chat-input');
		var sendBtn = document.getElementById('usc-chat-send');
		var signinEl = document.getElementById('usc-chat-signin');
		var onlineEl = document.getElementById('usc-chat-online');
		var offlineEl = document.getElementById('usc-chat-offline');

		// Operator greeting: set once during init, before the panel can open,
		// so aria-describedby="usc-chat-intro" resolves to real text the moment
		// focus enters the dialog. Plain text only (ADR-0016) via .textContent.
		if (introEl) {
			introEl.textContent = cfg.greeting || '';
		}

		// ADR-0017: reflect the server-resolved availability state. The "online"
		// pill is shown ONLY when the state is genuinely 'available' and the
		// operator enabled it — never an untrue claim. The offline message is
		// operator-authored plain text, rendered via .textContent.
		function applyAvailability(state) {
			var resolved = state || cfg.availability || 'available';
			var unavailable = resolved === 'unavailable';
			root.setAttribute('data-availability', unavailable ? 'unavailable' : 'available');

			if (offlineEl) {
				offlineEl.textContent = unavailable ? (cfg.offlineMessage || '') : '';
				offlineEl.hidden = !unavailable || !cfg.offlineMessage;
			}

			if (onlineEl) {
				var showPill = !unavailable && !!cfg.showOnlinePill;
				onlineEl.textContent = showPill ? (cfg.i18n.online || '') : '';
				onlineEl.hidden = !showPill;
			}
		}

		applyAvailability(cfg.availability);

		function markHasMessages() {
			if (messagesEl && messagesEl.childNodes.length) {
				root.setAttribute('data-has-messages', '');
			}
		}

		var conversationUuid = null;
		var lastMessageId = 0;
		// Message ids already rendered. `after_id` polling is not enough on its
		// own: the periodic poll and the explicit post-send poll can both be
		// in flight with the same stale `lastMessageId` (e.g. still 0 for the
		// first message of a conversation), so the same row can come back twice
		// before either response advances `lastMessageId`. Dedupe on render.
		var seenMessageIds = {};
		var pollTimer = null;
		var open = false;
		var sending = false;
		var pendingIdempotency = null;
		// Bumped on every open and every close. The async open bootstrap
		// captures the value and bails if it changed, so a close/Escape during
		// bootstrap is never overridden by a late input.focus() or status write.
		var openSession = 0;

		// When sticky, poll()'s routine "clear status" is suppressed so an
		// honest offline confirmation stays visible until an operator replies.
		var stickyStatus = false;

		function setStatus(text, isError, sticky) {
			statusEl.textContent = text || '';
			stickyStatus = !!sticky;
			if (isError) {
				statusEl.classList.add('is-error');
			} else {
				statusEl.classList.remove('is-error');
			}
		}

		function clearMessages() {
			while (messagesEl.firstChild) {
				messagesEl.removeChild(messagesEl.firstChild);
			}
			seenMessageIds = {};
			root.removeAttribute('data-has-messages');
		}

		function appendMessage(msg) {
			if (msg.id) {
				if (seenMessageIds[msg.id]) {
					return;
				}
				seenMessageIds[msg.id] = true;
			}

			var wrap = document.createElement('div');
			var direction = msg.direction === 'visitor' ? 'visitor' : 'operator';
			wrap.className = 'usc-chat__bubble usc-chat__bubble--' + direction;

			var author = document.createElement('span');
			author.className = 'usc-chat__bubble-author';
			author.textContent =
				msg.author_label ||
				(direction === 'visitor' ? cfg.i18n.you : cfg.i18n.supportTeam);

			var text = document.createElement('span');
			text.className = 'usc-chat__bubble-text';
			text.textContent = msg.text || '';

			wrap.appendChild(author);
			wrap.appendChild(text);
			messagesEl.appendChild(wrap);
			messagesEl.scrollTop = messagesEl.scrollHeight;
			markHasMessages();

			if (msg.id && msg.id > lastMessageId) {
				lastMessageId = msg.id;
			}
		}

		function uuidv4() {
			if (window.crypto && crypto.randomUUID) {
				return crypto.randomUUID();
			}
			return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
				var r = (Math.random() * 16) | 0;
				var v = c === 'x' ? r : (r & 0x3) | 0x8;
				return v.toString(16);
			});
		}

		function api(method, path, body) {
			var headers = {
				Accept: 'application/json',
				'Content-Type': 'application/json'
			};
			if (cfg.nonce) {
				headers['X-WP-Nonce'] = cfg.nonce;
			}
			var opts = { method: method, headers: headers, credentials: 'same-origin' };
			if (body) {
				opts.body = JSON.stringify(body);
			}
			return fetch(cfg.restBase + path, opts).then(function (res) {
				return res.json().then(function (data) {
					return { status: res.status, data: data };
				});
			});
		}

		function mapError(status, data) {
			var reason = data && data.reason ? data.reason : '';
			if (status === 401 || reason === 'auth_required') {
				return cfg.i18n.errorAuth;
			}
			if (status === 503 || reason === 'unavailable') {
				return cfg.i18n.errorUnavailable;
			}
			return cfg.i18n.errorGeneric;
		}

		function stopPolling() {
			if (pollTimer) {
				clearInterval(pollTimer);
				pollTimer = null;
			}
		}

		function startPolling() {
			stopPolling();
			if (!conversationUuid) {
				return;
			}
			pollTimer = setInterval(function () {
				if (!open || document.hidden) {
					return;
				}
				poll();
			}, cfg.pollInterval || 4000);
		}

		function poll() {
			if (!conversationUuid) {
				return Promise.resolve();
			}
			var q = lastMessageId > 0 ? '?after_id=' + encodeURIComponent(String(lastMessageId)) : '';
			return api('GET', '/conversations/' + encodeURIComponent(conversationUuid) + q).then(function (res) {
				if (!res.data || !res.data.ok) {
					setStatus(mapError(res.status, res.data), true);
					if (res.status === 401) {
						stopPolling();
					}
					return;
				}
				if (!stickyStatus) {
					setStatus('');
				}
				applyAvailability(res.data.availability);
				var list = res.data.messages || [];
				for (var i = 0; i < list.length; i++) {
					appendMessage(list[i]);
					if (list[i].direction !== 'visitor') {
						setStatus('');
					}
				}
			}).catch(function () {
				setStatus(cfg.i18n.errorGeneric, true);
			});
		}

		function ensureConversation() {
			if (conversationUuid) {
				return Promise.resolve(conversationUuid);
			}
			return api('POST', '/conversations', {}).then(function (res) {
				if (!res.data || !res.data.ok || !res.data.conversation_uuid) {
					throw new Error(mapError(res.status, res.data));
				}
				conversationUuid = res.data.conversation_uuid;
				applyAvailability(res.data.availability);
				return conversationUuid;
			});
		}

		function openPanel() {
			open = true;
			openSession += 1;
			var session = openSession;
			panel.hidden = false;
			launcher.setAttribute('aria-expanded', 'true');
			launcher.setAttribute('aria-label', cfg.i18n.close);

			// Non-modal dialog (ADR-0016 D8): move focus into the panel now, to
			// the close button, before the async conversation bootstrap — never
			// onto a hidden or detached node. No focus trap: keyboard focus may
			// leave the panel to the page as normal.
			if (closeBtn) {
				closeBtn.focus();
			}

			if (!cfg.loggedIn) {
				form.hidden = true;
				signinEl.hidden = false;
				clearMessages();
				signinEl.textContent = '';
				var p = document.createElement('p');
				p.textContent = cfg.i18n.signIn;
				var a = document.createElement('a');
				a.href = cfg.loginUrl;
				a.textContent = cfg.i18n.signInButton;
				signinEl.appendChild(p);
				signinEl.appendChild(a);
				setStatus('');
				return;
			}

			if (!cfg.schemaOk) {
				form.hidden = true;
				signinEl.hidden = true;
				setStatus(cfg.i18n.errorUnavailable, true);
				return;
			}

			signinEl.hidden = true;
			form.hidden = false;
			input.placeholder = cfg.i18n.placeholder;
			sendBtn.textContent = cfg.i18n.send;
			sendBtn.disabled = true;
			setStatus(cfg.i18n.loading || '', false);

			ensureConversation()
				.then(function () {
					if (session !== openSession || !open) {
						return null;
					}
					clearMessages();
					lastMessageId = 0;
					return poll();
				})
				.then(function () {
					if (session !== openSession || !open) {
						return;
					}
					sendBtn.disabled = false;
					if (!messagesEl.childNodes.length) {
						setStatus(cfg.i18n.empty, false);
					}
					startPolling();
					input.focus();
				})
				.catch(function (err) {
					if (session !== openSession || !open) {
						return;
					}
					sendBtn.disabled = false;
					setStatus(err && err.message ? err.message : cfg.i18n.errorGeneric, true);
				});
		}

		function closePanel() {
			open = false;
			openSession += 1;
			panel.hidden = true;
			launcher.setAttribute('aria-expanded', 'false');
			launcher.setAttribute('aria-label', cfg.i18n.open);
			stopPolling();
			launcher.focus();
		}

		function togglePanel() {
			if (open) {
				closePanel();
			} else {
				openPanel();
			}
		}

		launcher.addEventListener('click', togglePanel);
		closeBtn.addEventListener('click', closePanel);

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && open) {
				closePanel();
			}
		});

		window.addEventListener('pagehide', stopPolling);
		document.addEventListener('visibilitychange', function () {
			if (document.hidden) {
				stopPolling();
			} else if (open && cfg.loggedIn && conversationUuid) {
				startPolling();
			}
		});

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			if (sending || !cfg.loggedIn) {
				return;
			}
			var text = (input.value || '').trim();
			if (!text) {
				return;
			}

			sending = true;
			sendBtn.disabled = true;
			sendBtn.textContent = cfg.i18n.sending;
			setStatus('');

			if (!pendingIdempotency) {
				pendingIdempotency = uuidv4();
			}
			var key = pendingIdempotency;

			ensureConversation()
				.then(function (uuid) {
					return api('POST', '/conversations/' + encodeURIComponent(uuid) + '/messages', {
						text: text,
						idempotency_key: key
					});
				})
				.then(function (res) {
					if (!res.data || !res.data.ok) {
						throw new Error(mapError(res.status, res.data));
					}
					input.value = '';
					pendingIdempotency = null;
					applyAvailability(res.data.availability);
					if (res.data.availability === 'unavailable') {
						// Honest offline confirmation (ADR-0017 section 9) — no time
						// estimate; sticky so the routine poll does not clear it.
						setStatus(cfg.i18n.offlineConfirm || '', false, true);
					}
					return poll();
				})
				.catch(function (err) {
					setStatus(err && err.message ? err.message : cfg.i18n.errorGeneric, true);
				})
				.finally(function () {
					sending = false;
					sendBtn.disabled = false;
					sendBtn.textContent = cfg.i18n.send;
				});
		});

		launcher.setAttribute('aria-label', cfg.i18n.open);
	}

	// The shell markup is printed on wp_footer at priority 30 — after
	// wp_print_footer_scripts (priority 20) — so this script can load before
	// its own DOM exists. Defer initialization until the document is parsed.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
