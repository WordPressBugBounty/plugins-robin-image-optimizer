/*!
 *
 * $.wbcr_factory_templates_759.app - методы для работы с приложением. Скрыть, показать уведомления.
 * $.wbcr_factory_templates_759.hooks - это иммитация хуков и фильтров аналогично тем, что используются в Wordpress
 *
 * Copyright 2018, Themeisle, https://themeisle.com
 * 
 * @since 2.0.5
 * @pacakge clearfy
 */
(function($) {
	'use strict';

	if( !$.wbcr_factory_templates_759 ) {
		$.wbcr_factory_templates_759 = {};
	}

	//todo: Переопредление для совместимости со старыми версиями плагинов.
	$.wbcr_factory_templates_759.filters = $.wbcr_factory_templates_759.filters || $.wfactory_600.filters;
	//todo: Переопредление для совместимости со старыми версиями плагинов.
	$.wbcr_factory_templates_759.hooks = $.wbcr_factory_templates_759.hooks || $.wfactory_600.hooks;

	$.wbcr_factory_templates_759.app = $.wbcr_factory_templates_759.app || {
		/**
		 * Создает и показывает уведомление внутри интерфейса
		 *
		 * @param {string} message - сообщение об ошибке или предупреждение
		 * @param {string} type - тип уведомления (error, warning, success)
		 */
		showNotice: function(message, type) {
			var noticeContanier = $('<div></div>'),
				noticeInnerWrap = $('<p></p>'),
				dashicon = $('<span></span>'),
				dashiconClass,
				noticeId = this.makeid();

			if( !type ) {
				type = 'warning';
			}

			noticeContanier.addClass('alert', 'wbcr-factory-warning-notice')
				.addClass('alert-' + type).addClass('wbcr-factory-' + type + '-notice');

			noticeContanier.append(noticeInnerWrap);
			noticeContanier.attr('id', 'uq-' + noticeId);

			if( 'success' === type ) {
				dashiconClass = 'dashicons-yes';
			} else if( 'error' === type ) {
				dashiconClass = 'dashicons-no';
			} else {
				dashiconClass = 'dashicons-warning';
			}

			dashicon.addClass('dashicons').addClass(dashiconClass);
			noticeInnerWrap.prepend(dashicon);
			dashicon.after(message);

			$([document.documentElement, document.body]).animate({
				scrollTop: $('.wbcr-factory-content').offset().top - 100
			}, 300, function() {
				noticeContanier.hide();
				$('.wbcr-factory-content').prepend(noticeContanier);
				noticeContanier.fadeIn();

				/**
				 * Хук выполняет проивольную функцию, после того как уведомление отображено
				 * Реализация системы фильтров и хуков в файле libs/clearfy/admin/assests/js/global.js
				 * Пример регистрации хука $.wfactory_600.hooks.add('wbcr/factory_templates_759/updated',
				 * function(noticeId) {});
				 * @param {string} noticeId - id уведомления
				 */
				$.wfactory_600.hooks.run('wbcr/factory_templates_759/showed_notice', [noticeId]);
				$.wfactory_600.hooks.run('wbcr/clearfy/showed_notice', [noticeId]);
			});

			return noticeId;
		},

		/**
		 * Удаляет уведомление из интерфейса
		 *
		 * @param {string} noticeId - id уведомления
		 */
		hideNotice: function(noticeId) {
			var el;
			if( !noticeId ) {
				el = $('.wbcr-factory-content').find('.alert');
			} else {
				el = $('#uq-' + noticeId);
			}

			el.fadeOut(500, function(e) {
				$(e).remove();

				/**
				 * Хук выполняет проивольную функцию, после того как уведомление скрыто
				 * Реализация системы фильтров и хуков в файле libs/clearfy/admin/assests/js/global.js
				 * Пример регистрации хука $.wfactory_600.hooks.add('wbcr/factory_templates_759/updated',
				 * function(noticeId)
				 * {});
				 * @param {string} noticeId - id уведомления
				 */
				$.wfactory_600.hooks.run('wbcr/factory_templates_759/hidded_notice', [noticeId]);
				$.wfactory_600.hooks.run('wbcr/clearfy/hidded_notice', [noticeId]);
			});
		},

		makeid: function() {
			var text = "";
			var possible = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";

			for( var i = 0; i < 32; i++ ) {
				text += possible.charAt(Math.floor(Math.random() * possible.length));
			}

			return text;
		}

	};

	$.wfactory_600.hooks.add('core/components/pre_activate', function(button) {
		if( !$('#WBCR').length ) {
			return false;
		}

		if( button.closest('.alert').length ) {
			button.closest('.alert').remove();
		}

		if( button.closest('.plugin-card').length ) {
			button.closest('.plugin-card').removeClass('plugin-status-deactive');
			button.closest('.plugin-card').find('.delete-now').remove();
		}
	});

	$.wfactory_600.hooks.add('core/components/deactivated', function(button, data, response) {
		if( !$('#WBCR').length ) {
			return false;
		}

		if( button.closest('.plugin-card').length ) {
			button.closest('.plugin-card').addClass('plugin-status-deactive');

			if( response.data['delete_button'] && response.data['delete_button'] !== '' ) {
				button.before($(response.data['delete_button']).addClass('delete-now'));
			}
		}

		if( button.closest('.wbcr-hide-after-action').length ) {
			button.closest('.wbcr-hide-after-action').remove();
		}
	});

	$.wfactory_600.hooks.add('core/components/deleted', function(button) {
		if( !$('#WBCR').length ) {
			return false;
		}

		let button_i18n = button.data('i18n');

		button.closest('.plugin-card').find('.install-now').data('plugin-action', 'install');
		button.closest('.plugin-card').find('.install-now').attr('data-plugin-action', 'install');
		button.closest('.plugin-card').find('.install-now').removeClass('button-primary').addClass('button-default');
		button.closest('.plugin-card').find('.install-now').text(button_i18n.install);

		if( button.closest('.plugin-card').length ) {
			button.closest('.plugin-card').addClass('plugin-status-deactive');
			button.remove();
		}
	});

	$.wfactory_600.hooks.add('core/components/activation_error', function(plugin, button, response) {
		if( !($('#WBCR').length && $.wbcr_factory_templates_759) ) {
			return false;
		}

		button.closest('.plugin-card').addClass('plugin-status-deactive');

		if( response.data && response.data.error_message ) {
			$.wbcr_factory_templates_759.app.showNotice(response.data.error_message, 'danger');
		}
	});

	$.wfactory_600.hooks.add('core/components/update_error', function(button, data, response) {
		if( !($('#WBCR').length && $.wbcr_factory_templates_759) ) {
			return false;
		}

		if( response.data && response.data.error_message ) {
			$.wbcr_factory_templates_759.app.showNotice(response.data.error_message, 'danger');
		}

	});

	$.wfactory_600.hooks.add('core/components/activated', function(button, data, response) {
		if( !$('#WBCR').length ) {
			return false;
		}

		button.closest('.plugin-card').removeClass('plugin-status-deactive');
	});

	$.wfactory_600.hooks.add('core/components/ajax_error', function(xhr, ajaxOptions, thrownError) {
		if( !($('#WBCR').length && $.wbcr_factory_templates_759) ) {
			return false;
		}

		$.wbcr_factory_templates_759.app.showNotice('Error: [' + thrownError + '] Status: [' + xhr.status + '] Error massage: [' + xhr.responseText + ']', 'danger');
	});

})(jQuery);