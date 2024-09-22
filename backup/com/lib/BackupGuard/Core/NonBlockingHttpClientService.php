<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

// https://stackoverflow.com/questions/8024821/php-curl-required-only-to-send-and-not-wait-for-response

class NonBlockingHttpClientService {

	private $method = 'GET';
	private $params = [];
	private $port = 80;

	private $host;
	private $path;
	private $post_content;

	public function isPost(): bool
	{
		return ($this->method === 'POST');
	}

	public function setMethodToPost(): NonBlockingHttpClientService
	{
		$this->method = 'POST';

		return $this;
	}

	public function setPort(int $port): NonBlockingHttpClientService
	{
		$this->port = $port;

		return $this;
	}

	public function setParams(array $params): NonBlockingHttpClientService
	{
		$this->params = $params;

		return $this;
	}

	private function handleUrl(string $url): void
	{
		$url = str_replace(['https://', 'http://'], '', $url);

		$url_parts = explode('/', $url);

		if(count($url_parts) < 2) {
			$this->host = $url_parts[0];
			$this->path = '/';
		} else {
			$this->host = $url_parts[0];
			$this->path = str_replace($this->host, '', $url);
		}
	}

	private function handleParams(): void
	{
		if(empty($this->params)) return;

		if($this->isPost()) {
			$this->post_content = http_build_query($this->params);

		} else {
			/*
			if you want to specify the params as an array for GET request, they will just be
			appended to the path as a query string
			*/
			if(strpos($this->path, '?') === false) {
				$this->path .= '?' . ltrim($this->arrayToQueryString($this->params), '&');
			} else {
				$this->path .= $this->arrayToQueryString($this->params);
			}
		}
	}

	private function arrayToQueryString(array $params): string
	{
		$string = '';

		foreach($params as $name => $value) {
			$string .= "&$name=" . urlencode($value);
		}

		return $string;
	}

	public function doRequest(string $url): bool
	{
		$this->handleUrl($url);
		$this->handleParams();

		$host = $this->host;
		$path = $this->path;

		$fp = fsockopen($host,  $this->port, $errno, $errstr, 1);

		if (!$fp) {
			$error_message = __CLASS__ . ": cannot open connection to $host$path : $errstr ($errno)";
			error_log($error_message);

			return false;

		} else {
			fwrite($fp, $this->method . " $path HTTP/1.1\r\n");
			fwrite($fp, "Host: $host\r\n");

			if($this->isPost()) fwrite($fp, "Content-Type: application/x-www-form-urlencoded\r\n");
			if($this->isPost()) fwrite($fp, "Content-Length: " . strlen($this->post_content) . "\r\n");

			fwrite($fp, "Connection: close\r\n");
			fwrite($fp, "\r\n");

			if($this->isPost()) fwrite($fp, $this->post_content);

			return true;
		}
	}
}
