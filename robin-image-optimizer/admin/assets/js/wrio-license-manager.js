/**
 * License Manager JavaScript
 *
 * Handles license activation, deactivation, sync, and unsubscribe actions.
 * This is a lightweight replacement for clearfy-license-manager.js
 *
 * @package Robin_Image_Optimizer
 */

jQuery(function($) {
	'use strict';

	/**
	 * Handle license action button clicks
	 */
	$(document).on('click', '.wrio-license-btn', function(e) {
		e.preventDefault();

		// Remove any existing notices when user tries again
		$('.wrio-license-notice').remove();

		var $button = $(this),
			$wrapper = $('#wrio-license-wrapper'),
			action = $button.data('action'),
			nonce = $wrapper.data('nonce'),
			loaderUrl = $wrapper.data('loader');

		// Disable all buttons and show loader inside the clicked button
		$('.wrio-license-btn').prop('disabled', true);
		$button.prepend('<img class="wrio-loader" src="' + loaderUrl + '" alt="Loading..." style="height: 16px; vertical-align: middle; margin-right: 8px;">');

		// Build request data
		var data = {
			action: 'wrio_license_action',
			_wpnonce: nonce,
			license_action: action,
			licensekey: ''
		};

		// Include license key for activation
		if (action === 'activate') {
			data.licensekey = $('#license-key').val().trim();

			if (!data.licensekey) {
				showNotice('Please enter a license key.', 'error');
				resetButtons();
				return;
			}
		}

		// Send AJAX request
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			dataType: 'json',
			data: data,
			success: function(response) {
				if (response && response.success) {
					showNotice(response.data.message, 'success');
					// Reload page to show updated license state
					setTimeout(function() {
						window.location.reload();
					}, 1000);
				} else {
					var errorMsg = response && response.data && response.data.message
						? response.data.message
						: 'An error occurred. Please try again.';
					showNotice(errorMsg, 'error');
					resetButtons();
				}
			},
			error: function(xhr, status, error) {
				console.error('WRIO License AJAX Error:', {
					status: xhr.status,
					statusText: xhr.statusText,
					responseText: xhr.responseText,
					error: error
				});

				var errorMsg = 'Connection error. Please check your internet connection and try again.';
				if (xhr.responseText) {
					try {
						var response = JSON.parse(xhr.responseText);
						if (response.data && response.data.message) {
							errorMsg = response.data.message;
						}
					} catch (e) {
						// Response wasn't JSON
					}
				}

				showNotice(errorMsg, 'error');
				resetButtons();
			}
		});
	});

	/**
	 * Reset buttons to their original state
	 */
	function resetButtons() {
		$('.wrio-loader').remove();
		$('.wrio-license-btn').prop('disabled', false);
	}

	/**
	 * Show a notice message
	 *
	 * @param {string} message The message to display
	 * @param {string} type    Notice type: 'success' or 'error'
	 */
	function showNotice(message, type) {
		// Remove any existing notices
		$('.wrio-license-notice').remove();

		var typeClass = type === 'success' ? 'wrio-license-notice--success' : 'wrio-license-notice--error';
		var $notice = $('<div class="wrio-license-notice ' + typeClass + '"><p>' + escapeHtml(message) + '</p></div>');

		// Insert into the error container
		$('#license-form-error-container').html($notice);

		// Auto-dismiss after 5 seconds for success messages
		if (type === 'success') {
			setTimeout(function() {
				$notice.fadeOut(function() {
					$(this).remove();
				});
			}, 5000);
		}
	}

	/**
	 * Escape HTML entities
	 *
	 * @param {string} text Text to escape
	 * @return {string} Escaped text
	 */
	function escapeHtml(text) {
		var map = {
			'&': '&amp;',
			'<': '&lt;',
			'>': '&gt;',
			'"': '&quot;',
			"'": '&#039;'
		};
		return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
	}
});
