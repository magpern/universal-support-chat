/**
 * Universal Support Chat — Settings page avatar picker (SC-M05 / ADR-0016 D5).
 *
 * Admin-only. Uses the WordPress core media modal (wp.media), guaranteed
 * present because this script declares the `media-editor` handle as its
 * dependency. It stores only an integer attachment id; the server
 * re-validates it as an image on save. "Remove" writes 0.
 */
(function () {
	'use strict';

	if (!window.wp || !wp.media) {
		return;
	}

	var idInput = document.getElementById('usc-widget-avatar-id');
	var preview = document.getElementById('usc-widget-avatar-preview');
	var chooseBtn = document.getElementById('usc-widget-avatar-choose');
	var removeBtn = document.getElementById('usc-widget-avatar-remove');

	if (!idInput || !preview || !chooseBtn || !removeBtn) {
		return;
	}

	var frame = null;

	function setPreview(url) {
		while (preview.firstChild) {
			preview.removeChild(preview.firstChild);
		}
		if (!url) {
			return;
		}
		var img = document.createElement('img');
		img.src = url;
		img.alt = '';
		img.width = 64;
		img.height = 64;
		img.style.borderRadius = '50%';
		img.style.objectFit = 'cover';
		preview.appendChild(img);
	}

	chooseBtn.addEventListener('click', function (e) {
		e.preventDefault();

		if (frame) {
			frame.open();
			return;
		}

		frame = wp.media({
			title: chooseBtn.getAttribute('data-title') || 'Select image',
			library: { type: 'image' },
			multiple: false
		});

		frame.on('select', function () {
			var attachment = frame.state().get('selection').first().toJSON();
			idInput.value = String(attachment.id);
			var sizes = attachment.sizes || {};
			var chosen = sizes.thumbnail || sizes.medium || sizes.full;
			setPreview(chosen ? chosen.url : attachment.url);
		});

		frame.open();
	});

	removeBtn.addEventListener('click', function (e) {
		e.preventDefault();
		idInput.value = '0';
		setPreview('');
	});
})();
