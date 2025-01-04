(function ($) {
    class BulkOptimization {
        constructor(ajaxUrl, i18n, settings) {
            if (!i18n || !settings) {
                console.error('[Error]: Required global variables are missing.');
                return;
            }
            this.ajaxUrl = ajaxUrl;
            this.i18n = i18n;
            this.settings = settings;

            this.totalImages = 0;
            this.countAttachments = 0;
            this.countThumbs = 0;
        }

        /**
         * Initializes the bulk optimization process by chaining multiple calculations.
         * If any error occurs during the process, it will be caught and handled.
         */
        init() {
            this.calculateTotalAttachments()
                .then(() => this.calculateTotalThumbs())
                .then(() => this.calculateTotalImages())
                .catch((error) => this.throwError(error));
        }

        /**
         * Sends an AJAX POST request to the server.
         *
         * @param {string} action - The AJAX action to trigger on the server.
         * @param {Object} additionalData - Additional data to send with the request.
         * @returns {Promise<Object>} - A promise that resolves with the response data from the server.
         * @throws Will throw an error if the response is invalid or the request fails.
         */
        async postAjax(action, additionalData = {}) {
            const data = {
                action: action,
                _wpnonce: this.settings.optimization_nonce,
                ...additionalData,
            };

            try {
                const response = await $.post(this.ajaxUrl, data);

                if (!response || !response.success || !response.data) {
                    console.error('[Error]: Invalid AJAX response.', response);
                    if (response?.data?.error) {
                        console.error(response.data.error);
                    }

                    throw new Error(this.i18n.ajaxError || 'AJAX Error Occurred');
                }

                return response.data;
            } catch (xhr) {
                console.error('[Error]: AJAX Request Failed.', xhr);
                throw xhr;
            }
        }

        /**
         * Calculates the total number of attachments.
         *
         * This method sends an AJAX request to the server to fetch the total
         * number of media attachments and updates the corresponding UI element with the result.
         *
         * @returns {Promise<void>} - A promise that resolves when the calculation is complete.
         */
        async calculateTotalAttachments() {
            try {
                const data = await this.postAjax('wbcr-rio-calculate-total-attachments');

                this.countAttachments = data.found_attachments;

                $('#wio-stat-totals__originals')
                    .removeClass('wio-stat-totals__loading')
                    .text(data.found_attachments);
            } catch (error) {
                this.throwError(error);
            }
        }

        /**
         * Updates the total count of images by summing attachments and thumbnails.
         *
         * This method does not send an AJAX request. Instead, it calculates the total
         * number of found images and updates the corresponding UI element.
         */
        async calculateTotalImages() {
            this.totalImages = this.countAttachments + this.countThumbs;
            $('#wio-stat-totals__totals')
                .removeClass('wio-stat-totals__loading')
                .text(this.totalImages);
        }

        /**
         * Calculates the total number of thumbnails in a paginated manner.
         *
         * This method sends multiple AJAX requests based on the `offset` returned
         * from the server. It stops once the `done` parameter is `true` and accumulates
         * the total number of thumbnails found during the process.
         *
         * @returns {Promise<void>} - A promise that resolves when all requests are complete.
         * @throws Will throw an error if `next_offset` is missing or undefined in the response.
         */
        async calculateTotalThumbs() {
            try {
                let offset = 0;
                let totalThumbs = 0;

                // Sequentially fetch thumbnail counts in batches
                while (true) {
                    const data = await this.postAjax('wbcr-rio-calculate-total-thumbs', { offset });

                    // Update the total thumbnail counter
                    totalThumbs = data.found_thumbs;

                    // Update the thumbnails count in the UI
                    $('#wio-stat-totals__thumbnails')
                        .removeClass('wio-stat-totals__loading')
                        .text(totalThumbs);

                    // Break the loop if the server indicates the process is complete
                    if (data.done) {
                        break;
                    }

                    // Update the offset for the next request
                    offset = data.next_offset;

                    // Validate the offset to avoid infinite loops
                    if (offset === undefined || offset === null) {
                        console.error('[Error]: Missing offset in server response.');
                        throw new Error('Invalid server response: offset is undefined.');
                    }
                }

                this.countThumbs = totalThumbs;

            } catch (error) {
                this.throwError(error);
            }
        }

        /**
         * Handles errors by logging them to the console and displaying an alert.
         *
         * This method provides a standardized way to handle any unexpected errors
         * that occur during the execution of the bulk optimization process.
         *
         * @param {Error|string} error - The error message or object to handle.
         */
        throwError(error) {
            console.error('[Error]:', error);
            alert(this.i18n.generalError || 'An error occurred. Please try again.');
        }
    }

    // Initialize the bulk optimization process on document ready
    $(document).ready(() => {
        const bulkOptimization = new BulkOptimization(
            ajaxurl, // The URL for WordPress AJAX requests
            window.wrio_l18n_bulk_page, // Localization data for the UI
            window.wrio_settings_bulk_page // Settings data for the optimization
        );
        bulkOptimization.init();
    });
})(jQuery);