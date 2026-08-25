(function () {
	'use strict';

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
	var statusEl = document.getElementById('usc-chat-status');
	var messagesEl = document.getElementById('usc-chat-messages');
	var form = document.getElementById('usc-chat-form');
	var input = document.getElementById('usc-chat-input');
	var sendBtn = document.getElementById('usc-chat-send');
	var signinEl = document.getElementById('usc-chat-signin');

	var conversationUuid = null;
	var lastMessageId = 0;
	var pollTimer = null;
	var open = false;
	var sending = false;
	var pendingIdempotency = null;

	function setStatus(text, isError) {
		statusEl.textContent = text || '';
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
	}

	function appendMessage(msg) {
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
			setStatus('');
			var list = res.data.messages || [];
			for (var i = 0; i < list.length; i++) {
				appendMessage(list[i]);
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
			return conversationUuid;
		});
	}

	function openPanel() {
		open = true;
		panel.hidden = false;
		launcher.setAttribute('aria-expanded', 'true');
		launcher.setAttribute('aria-label', cfg.i18n.close);

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

		ensureConversation()
			.then(function () {
				clearMessages();
				lastMessageId = 0;
				return poll();
			})
			.then(function () {
				if (!messagesEl.childNodes.length) {
					setStatus(cfg.i18n.empty, false);
				}
				startPolling();
				input.focus();
			})
			.catch(function (err) {
				setStatus(err && err.message ? err.message : cfg.i18n.errorGeneric, true);
			});
	}

	function closePanel() {
		open = false;
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
})();
