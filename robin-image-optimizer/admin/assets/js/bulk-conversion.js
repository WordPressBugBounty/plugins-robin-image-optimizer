jQuery(function ($) {
    /**
     * Factory function to create a bulk conversion handler for a specific format.
     *
     * @param {string} format - The conversion format ('webp' or 'avif')
     * @param {string} buttonSelector - jQuery selector for the start button
     * @returns {Object} - Bulk conversion handler object
     */
    function createBulkConversion(format, buttonSelector) {
        return {
            format: format,
            inprogress: false,
            serverDown: false,
            i18n: {},
            settings: {},
            startConvertButton: $(buttonSelector),
            startOptButton: $('#wrio-start-optimization'),
            startWebpButton: $('#wrio-start-conversion'),
            startAvifButton: $('#wrio-start-avif-conversion'),

            init: function () {
                this.i18n = wrio_l18n_bulk_page;
                this.settings = wrio_settings_bulk_page;

                if (this.startConvertButton.length) {
                    this.registerEvents();
                    this.checkInitialRunningState();
                }
            },

            checkInitialRunningState: function () {
                // If this conversion button is already running on page load, disable other buttons
                if (this.startConvertButton.hasClass('wio-running')) {
                    this.startOptButton.prop('disabled', true);
                    // Disable the other conversion button (not this one)
                    if (this.format === 'webp') {
                        this.startAvifButton.prop('disabled', true);
                    } else {
                        this.startWebpButton.prop('disabled', true);
                    }
                }
            },

            registerEvents: function () {
                var self = this;
                this.startConvertButton.on('click', function () {

                    if ($(this).hasClass('wio-running')) {
                        self.startOptButton.prop('disabled', false);
                        self.startWebpButton.prop('disabled', false);
                        self.startAvifButton.prop('disabled', false);
                        self.stop();
                        return;
                    }

                    self.showModal();

                    return false;
                });
            },

            showModal: function () {
                var self = this;
                var infosModal = $('#wrio-tmpl-' + this.format + '-conversion');

                // Fall back to webp template if format-specific one doesn't exist
                if (!infosModal.length) {
                    infosModal = $('#wrio-tmpl-webp-conversion');
                }

                if (!infosModal.length) {
                    console.log('[Error]: Html template for modal not found.');
                    return;
                }

                var modalTitle = this.format === 'avif'
                    ? (this.i18n.modal_avif_conversion_title || this.i18n.modal_conversion_title)
                    : this.i18n.modal_conversion_title;

                // Swal Information before loading the optimize process.
                swal({
                    title: modalTitle,
                    html: infosModal.html(),
                    type: '',
                    customClass: 'wrio-modal wrio-modal-optimization-way',
                    showCancelButton: true,
                    showCloseButton: true,
                    padding: 0,
                    width: 740,
                    confirmButtonText: this.i18n.modal_conversion_manual_button,
                    cancelButtonText: this.i18n.modal_conversion_cron_button,
                    reverseButtons: true,
                }).then(function (result) {

                    self.startOptButton.prop('disabled', true);
                    self.startWebpButton.prop('disabled', true);
                    self.startAvifButton.prop('disabled', true);
                    self.startConvertButton.prop('disabled', false); // Re-enable current button for stop
                    self.process();

                    window.onbeforeunload = function () {
                        return self.i18n.leave_page_warning;
                    }

                }, function (dismiss) {
                    if (dismiss === 'cancel') { // you might also handle 'close' or 'timer' if you used those
                        self.startOptButton.prop('disabled', true);
                        self.startWebpButton.prop('disabled', true);
                        self.startAvifButton.prop('disabled', true);
                        self.startConvertButton.prop('disabled', false); // Re-enable current button for stop
                        self.process('cron');
                    } else {
                        throw dismiss;
                    }
                });

            },

            /**
             * Start conversion
             * @param {string} type - 'cron' or undefined for manual
             */
            process: function (type) {
                var self = this;
                this.inprogress = true;

                var sendData = {
                    'action': 'wrio-bulk-conversion-process',
                    'scope': this.settings.scope,
                    'format': this.format,
                    'multisite': 0,
                    '_wpnonce': this.settings.conversion_nonce,
                };

                this.setButtonStyleRun(type);

                if ('cron' === type) {
                    this.startConvertButton.addClass('wrio-cron-mode');

                    sendData['action'] = 'wrio-' + this.format + '-cron-start';

                    $.post(ajaxurl, sendData, function (response) {
                        if (!response || !response.success) {
                            console.log('[Error]: Failed ajax request (Start cron).');
                            console.log(sendData);
                            console.log(response);

                            if (response.data && response.data.error_message) {
                                self.throwError(response.data.error_message);
                            }
                        } else {
                            if (response.data && response.data.stop) {
                                self.stop();
                            }
                        }
                    }).fail(function (xhr, status, error) {
                        console.log(xhr);
                        console.log(status);
                        console.log(error);

                        self.throwError(error);
                    });

                    return;
                }

                this.showMessage(this.i18n.conversion_inprogress.replace("%s", parseInt($('#wio-unoptimized-num').text())));

                sendData['reset_current_errors'] = 1;

                this.sendRequest(sendData);
            },

            stop: function () {
                var self = this;
                this.inprogress = false;

                window.onbeforeunload = null;
                self.setButtonStyleStop();
                self.destroyMessages();

                if (this.startConvertButton.hasClass('wrio-cron-mode')) {
                    this.startConvertButton.removeClass('wrio-cron-mode');

                    $.post(ajaxurl, {
                        'action': 'wrio-' + this.format + '-cron-stop',
                        '_wpnonce': self.settings.conversion_nonce,
                        'scope': self.settings.scope
                    }, function (response) {
                        if (!response || !response.success) {
                            console.log('[Error]: Failed ajax request (Stop cron).');
                            console.log(response);

                            if (response.data && response.data.error_message) {
                                self.throwError(response.data.error_message);
                            }
                        } else {
                            self.startOptButton.prop('disabled', false);
                            self.startWebpButton.prop('disabled', false);
                            self.startAvifButton.prop('disabled', false);
                        }
                    }).fail(function (xhr, status, error) {
                        console.log(xhr);
                        console.log(status);
                        console.log(error);

                        self.throwError(error);
                    });
                }

            },

            complete: function () {
                this.inprogress = false;
                window.onbeforeunload = null;
                this.setButtonStyleComplete();
            },

            setButtonStyleRun: function (mode) {

                this.startConvertButton.addClass('wio-running');

                if ("cron" === mode) {
                    this.startConvertButton.text(this.i18n.modal_conversion_cron_button_stop);
                    return;
                }

                this.startConvertButton.text(this.i18n.button_stop);
            },

            setButtonStyleComplete: function () {
                this.showMessage(this.i18n.conversion_complete);
                this.startConvertButton.text(this.i18n.button_completed);
                this.startConvertButton.removeClass('wio-running');
                this.startConvertButton.prop('disabled', true);
                this.startOptButton.prop('disabled', false);
                this.startWebpButton.prop('disabled', false);
                this.startAvifButton.prop('disabled', false);
            },

            setButtonStyleStop: function () {
                this.startConvertButton.removeClass('wio-running');
                var buttonText = this.format === 'avif'
                    ? (this.i18n.avif_button_start || 'Convert to AVIF')
                    : this.i18n.webp_button_start;
                this.startConvertButton.text(buttonText);
            },

            showMessage: function (text) {
                var contanier = $('.wio-page-statistic'),
                    message;

                if (contanier.find('.wrio-statistic-message').length) {
                    message = contanier.find('.wrio-statistic-message');
                } else {
                    message = $('<div>');
                    message.addClass('wrio-statistic-message');
                    contanier.append(message);
                }

                message.html(text);
            },

            throwError: function (error_message) {
                this.stop();

                var noticeId = $.wbcr_factory_templates_759.app.showNotice(error_message, 'danger');

                setTimeout(function () {
                    $.wbcr_factory_templates_759.app.hideNotice(noticeId);
                }, 10000);
            },

            destroyMessages: function () {
                $('.wio-page-statistic').find('.wrio-statistic-message').empty();
            },

            sendRequest: function (data) {
                var self = this;

                if (!this.inprogress) {
                    return;
                }

                $.post(ajaxurl, data, function (response) {
                    if (!self.inprogress) {
                        return;
                    }

                    if (!response || !response.success) {
                        console.log('[Error]: Failed ajax request (Try to optimize images).');
                        console.log(response);

                        if (response.data && response.data.error_message) {
                            self.throwError(response.data.error_message);
                        }

                        return;
                    }

                    data.reset_current_errors = 0;

                    if (!response.data.end) {
                        $('#wio-total-unoptimized').text(parseInt(response.data.remain));
                        self.showMessage(self.i18n.conversion_inprogress.replace("%s", parseInt(response.data.remain)));
                        self.sendRequest(data);
                    } else {
                        $('#wio-total-unoptimized').text(response.data.remain);
                        self.complete();
                    }

                    redraw_statistics(response.data.statistic);

                    if (response.data.last_optimized) {
                        self.updateLog(response.data.last_optimized);
                    }
                    if (response.data.last_converted) {
                        self.updateLog(response.data.last_converted);
                    }
                }).fail(function (xhr, status, error) {
                    console.log(xhr);
                    console.log(status);
                    console.log(error);

                    self.throwError(error);
                });
            },

            updateLog: function (new_item_data) {
                var self = this;

                var limit = 100,
                    tableEl = $('.wrio-optimization-progress .wrio-table');

                if (!tableEl.length || !new_item_data) {
                    return;
                }

                // Clear empty table state
                if ($('.wrio-table-container-empty').length) {
                    $('.wrio-table-container-empty').addClass('wrio-table-container').removeClass('wrio-table-container-empty');
                    if (tableEl.find('tbody').length) {
                        tableEl.find('tbody').empty();
                    }
                }

                $.each(new_item_data, function (index, value) {
                    var trEl = $('<tr>'),
                        tdEl = $('<td>'),
                        webpSize = value.webp_size ? value.webp_size : '-',
                        avifSize = value.avif_size ? value.avif_size : '-';

                    if (tableEl.find('.wrio-row-id-' + value.id).length) {
                        tableEl.find('.wrio-row-id-' + value.id).remove();
                    }

                    trEl.addClass('flash').addClass('wrio-table-item').addClass('wrio-row-id-' + value.id);

                    if ('error' === value.type) {
                        trEl.addClass('wrio-error');
                    }

                    var preview = $('<img width="40" height="40" src="' + value.thumbnail_url + '" alt="">'),
                        previewUrl = $('<a href="' + value.url + '" target="_blank">' + value.file_name + '</a>');

                    tableEl.prepend(trEl);

                    trEl.append(tdEl.clone().append(preview));
                    trEl.append(tdEl.clone().append(previewUrl));

                    if ('error' === value.type) {
                        var colspan = value.scope !== 'custom-folders' ? '7' : '6';
                        trEl.append(tdEl.clone().attr('colspan', colspan).text("Error: " + value.error_msg));
                    } else {
                        trEl.append(tdEl.clone().text(value.original_size));
                        trEl.append(tdEl.clone().text(value.optimized_size));
                        trEl.append(tdEl.clone().text(webpSize));
                        trEl.append(tdEl.clone().text(avifSize));
                        trEl.append(tdEl.clone().text(value.original_saving));

                        if ("custom-folders" !== self.settings.scope) {
                            trEl.append(tdEl.clone().text(value.thumbnails_count));
                        }

                        trEl.append(tdEl.clone().text(value.total_saving));
                    }
                });

                if (tableEl.find('tr').length > limit) {
                    var diff = tableEl.find('tr').length - limit;

                    for (var i = 0; i < diff; i++) {
                        tableEl.find('tr:last').remove();
                    }
                }
            }
        };
    }

    // Create WebP conversion handler
    var bulkConversionWebp = createBulkConversion('webp', '#wrio-start-conversion');

    // Create AVIF conversion handler
    var bulkConversionAvif = createBulkConversion('avif', '#wrio-start-avif-conversion');

    $(document).ready(function () {
        bulkConversionWebp.init();
        bulkConversionAvif.init();
        $('[data-toggle="tooltip"]').tooltip();
    });

    var ajaxUrl = ajaxurl;
    var ai_data;

    function redraw_statistics(statistic) {
        // Update WebP stats - chart data attributes
        $('#wio-webp-chart').attr('data-unoptimized', statistic.unconverted)
            .attr('data-optimized', statistic.converted)
            .attr('data-errors', statistic.webp_error);

        // Update WebP percent display
        $('#wio-overview-chart-percent-webp').text(statistic.webp_percent_line);

        // Update WebP legend
        $('#wio-webp-pending').text(statistic.unconverted);
        $('#wio-webp-converted').text(statistic.converted);
        $('#wio-webp-error').text(statistic.webp_error);
        $('#wio-webp-done').text(statistic.converted);

        // Update AVIF stats (if available)
        if (statistic.avif_unconverted !== undefined) {
            $('#wio-avif-chart').attr('data-unoptimized', statistic.avif_unconverted)
                .attr('data-optimized', statistic.avif_converted)
                .attr('data-errors', statistic.avif_error);

            // Update AVIF percent display
            $('#wio-overview-chart-percent-avif').text(statistic.avif_percent_line);

            // Update AVIF legend
            $('#wio-avif-pending').text(statistic.avif_unconverted);
            $('#wio-avif-converted').text(statistic.avif_converted);
            $('#wio-avif-error').text(statistic.avif_error);
            $('#wio-avif-done').text(statistic.avif_converted);
        }

        var credits = $('.wrio-premium-user-balance');
        if (credits.attr('data-server') !== "server_5") {
            credits.text(statistic.credits);
        }

        if (window.wio_chart_webp) {
            window.wio_chart_webp.data.datasets[0].data[0] = statistic.webp_error; // errors
            window.wio_chart_webp.data.datasets[0].data[1] = statistic.converted; // optimized
            window.wio_chart_webp.data.datasets[0].data[2] = statistic.unconverted; // unoptimized
            window.wio_chart_webp.update();
        }

        if (window.wio_chart_avif && statistic.avif_unconverted !== undefined) {
            window.wio_chart_avif.data.datasets[0].data[0] = statistic.avif_error; // errors
            window.wio_chart_avif.data.datasets[0].data[1] = statistic.avif_converted; // optimized
            window.wio_chart_avif.data.datasets[0].data[2] = statistic.avif_unconverted; // unoptimized
            window.wio_chart_avif.update();
        }

        if ($('#wio-overview-chart-percent-webp').text() == '100') {
            window.onbeforeunload = null;
        }
    }

});
