<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Базовый класс для обработки изображений через API сторонних сервисов.
 *
 * todo: add usage example
 *
 * @version       1.0
 */
abstract class WIO_Image_Processor_Abstract {

	/**
	 * @var string Имя сервера
	 */
	protected $server_name;

	/**
	 * Оптимизация изображения
	 *
	 * @param array $params {
	 *                        Параметры оптимизации изображения. Разные сервера могут принимать разные наборы параметров. Ниже список всех возможных.
	 *
	 *      {type} string $image_url УРЛ изображения
	 *      {type} string $image_path Путь к файлу изображения
	 *      {type} string $quality Качество
	 *      {type} string $save_exif Сохранять ли EXIF данные
	 * }
	 *
	 * @return array|WP_Error {
	 *      Результаты оптимизации. Основные параметры. Другие параметры зависят от конкретной раелизации.
	 *
	 *      {type} string $optimized_img_url УРЛ оптимизированного изображения на сервере оптимизации
	 *      {type} int $src_size размер исходного изображения в байтах
	 *      {type} int $optimized_size размер оптимизированного изображения в байтах
	 *      {type} int $optimized_percent На сколько процентов уменьшилось изображение
	 *      {type} bool $not_need_replace Изображение не надо заменять.
	 *      {type} bool $not_need_download Изображение не надо скачивать.
	 * }
	 */
	abstract function process( $params );

	/**
	 * Качество изображения
	 * Метод конвертирует качество из настроек плагина в формат сервиса оптимизации
	 *
	 * @param mixed $quality качество
	 */
	abstract function quality( $quality );

	/**
	 * Проверка наличия ограничения на квоту
	 *
	 * @return bool Возвращает true, если существует ограничение на квоту, иначе false
	 */
	abstract public function has_quota_limit();

	/**
	 * Возвращает URL API сервера
	 *
	 * @return string
	 */
	public function get_api_url() {
		return wrio_get_server_url( $this->server_name );
	}

	/**
	 * Установка лимита квоты
	 *
	 * @param mixed $value Новое значение лимита квоты
	 *
	 * @return void
	 */
	public function set_quota_limit( $value ) {
		WRIO_Plugin::app()->updatePopulateOption( $this->server_name . '_quota_limit', (int) $value );
	}


	/**
	 * Получает лимит квоты для текущего сервера.
	 *
	 * @return int Лимит квоты, установленный для сервера. Если лимит не задан, возвращается 0.
	 */
	public function get_quota_limit() {
		return WRIO_Plugin::app()->getPopulateOption( $this->server_name . '_quota_limit', 0 );
	}

	/**
	 * HTTP запрос к API стороннего сервиса.
	 *
	 * @param string            $type POST|GET
	 * @param string            $url URL для запроса
	 * @param array|string|null $body Параметры запроса. По умолчанию: false.
	 * @param array             $headers Дополнительные заголовки. По умолчанию: false.
	 *
	 * @return string|WP_Error
	 */
	protected function request( $type, $url, $body = null, array $headers = [] ) {

		$args = [
			'method'  => $type,
			'headers' => array_merge(
				[
					'User-Agent' => '',
				],
				$headers
			),
			'body'    => $body,
			'timeout' => 150, // it make take some time for large images and slow Internet connections
		];

		$error_message = sprintf( 'Failed to get content of URL: %s as wp_remote_request()', $url );

		wp_raise_memory_limit( 'image' );
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			WRIO_Plugin::app()->logger->error( sprintf( '%s returned error (%s).', $error_message, $response->get_error_message() ) );

			return $response;
		}

		$response_body = wp_remote_retrieve_body( $response );
		$response_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $response_code ) {
			return $this->log_http_error_response( $error_message, $response_code, $response_body );
		}

		if ( empty( $response_body ) ) {
			WRIO_Plugin::app()->logger->error( sprintf( '%s responded an empty request body.', $error_message ) );

			return new WP_Error( 'http_request_failed', 'Server responded an empty request body.' );
		}

		return $response_body;
	}

	/**
	 * Log a non-200 response and preserve the raw response body when available.
	 *
	 * @param string $error_message Base error message for the request.
	 * @param int    $response_code HTTP response code.
	 * @param string $response_body Raw HTTP response body.
	 *
	 * @return WP_Error
	 */
	protected function log_http_error_response( $error_message, $response_code, $response_body ) {
		if ( ! empty( $response_body ) ) {
			WRIO_Plugin::app()->logger->error( sprintf( '%s responded Http error (%d).', $error_message, $response_code ) );
			WRIO_Plugin::app()->logger->debug( sprintf( '%s response body: %s', $error_message, $this->prepare_response_body_for_log( $response_body ) ) );

			return new WP_Error( 'http_request_failed', $this->append_status_code_to_message( 'Server responded with HTTP error.', $response_code ) );
		}

		WRIO_Plugin::app()->logger->error( sprintf( '%s responded Http error (%d).', $error_message, $response_code ) );

		return new WP_Error( 'http_request_failed', $this->append_status_code_to_message( 'Server responded with HTTP error.', $response_code ) );
	}

	/**
	 * Append an HTTP status code to a user-facing error message.
	 *
	 * @param string $message       Base error message.
	 * @param int    $response_code HTTP response code.
	 *
	 * @return string
	 */
	protected function append_status_code_to_message( $message, $response_code ) {
		$message = trim( (string) $message );

		if ( empty( $response_code ) ) {
			return $message;
		}

		if ( false !== stripos( $message, 'HTTP ' . $response_code ) ) {
			return $message;
		}

		return sprintf( '%1$s (HTTP %2$d)', rtrim( $message, '.' ), (int) $response_code );
	}

	/**
	 * Prepare an HTTP response body for debug logging.
	 *
	 * @param string $response_body Raw HTTP response body.
	 *
	 * @return string
	 */
	protected function prepare_response_body_for_log( $response_body ) {
		$response_body = wp_check_invalid_utf8( (string) $response_body );
		$response_body = trim( wp_strip_all_tags( $response_body ) );

		if ( '' === $response_body ) {
			return '[empty after sanitization]';
		}

		$max_length = 500;

		if ( strlen( $response_body ) > $max_length ) {
			$response_body = substr( $response_body, 0, $max_length ) . '...';
		}

		return $response_body;
	}

	/**
	 * Использует ли сервер отложенную оптимизацию
	 *
	 * @return bool
	 */
	public function isDeferred() {
		return false;
	}

	/**
	 * Проверка отложенной оптимизации изображения
	 *
	 * @param array $optimized_data Параметры отложенной оптимизации. Набор параметров зависит от конкретной реализации
	 *
	 * @return bool|array
	 */
	public function checkDeferredOptimization( $optimized_data ) {
		return false;
	}

	/**
	 * Проверка данных для отложенной оптимизации.
	 *
	 * Проверяет наличие необходимых параметров и соответствие серверу.
	 *
	 * @param array $optimized_data Параметры отложенной оптимизации. Набор параметров зависит от конкретной реализации
	 *
	 * @return bool
	 */
	public function validateDeferredData( $optimized_data ) {
		return false;
	}
}
