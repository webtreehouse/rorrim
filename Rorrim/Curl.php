<?php

/**
 * @author Chris Hagerman (chris@webtreehouse.com)
 * @copyright copyright 2009, Chris Hagerman, released under the GPL.
 * @license http://www.gnu.org/licenses/gpl.html
 * @version 0.5
 * @package Rorrim
 *
 * The Rorrim_Curl class provides a rudimentary wrapper to cURL that will extract
 * headers and status information for Rorrim_Asset.
 */

class Rorrim_Curl
{
	public $url;
	public $response;
	public $status;
	public $headers;
	
	/**
	 * Constructor. Creates a new Rorrim_Curl object and verifies the existence of the cURL library.
	 */
	
	function __construct()
	{
		if (!function_exists('curl_init'))
		{
			throw new Exception("PHP has not been built with cURL extension. Cannot continue.");
		}
	}
	
	/**
	 * Retrieves a file from a web server.
	 *
	 * @param string $url       the URL to retrieve
	 * @param string $userAgent the user agent to use
	 * @param string $timeout   the request timeout, in seconds
	 * @param string            the data returned by the host
	 */
	
	function get($url, $userAgent = null, $timeout = null)
	{
		$this->url = $url;
		
		$ch = curl_init();
		
		curl_setopt($ch, CURLOPT_URL, $this->url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
		
		if ($userAgent)
		{
			curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
		}
		if ($timeout)
		{
			curl_setopt($ch, CURLOPT_TIMEOUT,  $timeout);
		}

		$output = curl_exec($ch);
		
		$this->status = curl_getinfo($ch);
		$this->response = $this->stripHeaders($output);
		curl_close ($ch);
		
		return $this->response;
	}
	
	/**
	 * Strips headers if we were redirected a few times
	 *
	 * @param string $response the response from the web server
	 * @return string          the scrubbed response
	 */
	
	protected function stripHeaders($response)
	{
		for ($count=0; $count<=$this->status['redirect_count']; $count++)
		{
			$matches = preg_split("/(\r\n){2,2}/", $response, 2);
			$response = $matches[1];
		}

		$this->parseHeader($matches[0]);
		return $response;
	}
	
	/**
	 * Parses the headers and loads them into an array.
	 *
	 * @param string $header the header received
	 */
	
	protected function parseHeader($header)
	{	
		$matches = preg_split("/(\r\n)+/", $header) ;

		if (preg_match('/^HTTP/', $matches[0]))
		{
			$matches = array_slice($matches, 1) ;
		}

		foreach ($matches as $match)
		{
			$matchData = preg_split("/\s*:\s*/", $match, 2);
			$this->headers[$matchData[0]][] = $matchData[1];
		}
	}
}

?>