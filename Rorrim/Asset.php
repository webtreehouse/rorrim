<?php

/**
 * @author Chris Hagerman (chris@webtreehouse.com)
 * @copyright copyright 2009, Chris Hagerman, released under the GPL.
 * @license http://www.gnu.org/licenses/gpl.html
 * @version 0.5
 * @package Rorrim
 *
 * The Rorrim_Asset class stores web assets (html, images, javascript, css, etc).
 * The class allows for the detection and retrieval of child assets.
 *
 * @example ../Example.SinglePage.php
 */

class Rorrim_Asset
{
	public $userAgent = 'Mozilla/5.0 (compatible; rorrim; +http://code.google.com/p/rorrim/)';
	public $timeout = 30;
	public $tempDirectory = '/tmp';
	
	public $source = '';
	public $destination = '';
	public $base = '';
	public $type = '';
	public $data = '';
	public $cached = false;
	public $retrieved = false;
	
	public $assets = null;
	public $children = null;
	
	private $_regexURLPieces = '/^((http[s]?|ftp):\/)?\/?([^:\/\s]+)((\/\w+)*\/)([\w\-\.]+[^#?\s]+)(.*)?(#[\w\-]+)?$/si';
	private $_regexBase = '/<\s*base[^\>]*href\s*=\s*[\""\']?([^\""\'\s>]*).*?>/si';
	private $_regexPageTitle = '/<\s*title[^>]*>(?P<context>.*?)<\/title>/si';
	
	private $_regexFindChildren = null;
	
	/**
	 * Constructor. Creates a new Rorrim_Asset object.
	 *
	 * @param string $url      the URL to retrieve
	 * @param array [optional] an array of existing assets
	 *                         to be loaded. This is typically
	 *                         only used internally to attach
	 *                         children to parents.
	 */
	
	function __construct($url, &$assets = null)
	{
		$this->source = $url;
		$this->destination = $url;
		$this->assets = &$assets;
		$this->assets[$this->source] = &$this;
		
		$this->initRegEx();
	}
	
	/**
	 * Sets up an array of RegExs for finding children on the current asset.
	 * @access protected
	 */
	
	protected function initRegEx()
	{
		// TODO: combine css, cssinvert, cssimport into RegEx
		$this->regexFindChildren[anchor] = '/<\s*a[^\>]*(?P<location>href\s*=\s*[\""\']?(?P<url>[^\""\'\s>]*).*?>)(?P<context>.*?)<\/a>/si';
		$this->regexFindChildren[image] = '/<\s*(?P<location>img[^\>]*src\s*=\s*[\""\']?(?P<url>[^\""\'\s>]*))/si';
		$this->regexFindChildren[background] = '/<\s*(?P<location>[body|table|th|tr|td][^\>]*background\s*=\s*[\""\']?(?P<url>[^\""\'\s>]*))/si';
		$this->regexFindChildren[input] = '/<\s*input[^\>]*(?P<location>src\s*=\s*[\""\']?(?P<url>[^\""\'\s>]*))/si';
		$this->regexFindChildren[css] = '/<\s*link[^\>]*stylesheet[^\>]*[^\>]*(?P<location>href\s*=\s*[\""\']?(?P<url>[^\""\'\s>]*))/si';
		$this->regexFindChildren[cssinvert] = '/<\s*link[^\>]*(?P<location>href\s*=\s*[\""\']?(?P<url>[^\""\'\s>]*))[^\>]*stylesheet\s*/si';
		$this->regexFindChildren[cssimport] = '/(?P<location>@\s*import\s*u*r*l*\s*[\""\'\(]?\s?(?P<url>[^\""\'\s\)\;>]*))/si';
		$this->regexFindChildren[cssurl] = '/(?P<location>url\((?P<url>[^\)]+))/si';
		$this->regexFindChildren[javascript] = '/<\s*script[^\>]*(?P<location>src\s*=\s*[\""\']?(?P<url>[^\""\'\s>]*))/si';
	}
	
	/**
	 * Retrieves the asset. If the asset is an image, it will be cached to the temp
	 * directory to conserve system memory.
	 *
	 * @param boolean $autoGetChildren [optional] if true, will retrieve the children
	 *                                            automatically if the page isn't an
	 *                                            image.
	 * @return string                             the asset's raw data
	 * @access public
	 */
	
	public function retrieve($autoGetChildren = true)
	{
		if (!$this->retrieved)
		{
			// Retrieve the Asset
			$c = new Rorrim_Curl();
			$c->get($this->source, $this->userAgent, $user->timeout);
						
			if ($c->status['error'])
			{
			  throw new Exception("Error returned from cURL: " . $c->status['error']);
			}
		
			if ($c->status['http_code'] != 200)
			{
				throw new Exception("Could not find asset: " . $this->source);
			}

			// Save response
			$this->source = $c->status['url'];
			$this->data = $c->response;
		
			// Calculate the destination filename (an MD5 of the full URL)
			preg_match_all($this->_regexURLPieces, $this->source, $matches);
			$extension = end(explode('.', $matches[6][0]));
		
			// Only do certain extensions to prevent malicious code execution from remote sites
			if ($extension != 'bmp' && $extension != 'gif' && $extension != 'jpg' && $extension != 'png' && $extension != 'css' && $extension != 'js')
			{
				$this->destination = md5($this->source) . '.html';
			}
			else
			{
				$this->destination = md5($this->source) . '.' . $extension;
			}
		
			// Detect asset type
			if ($c->headers['Content-Type'] && substr(end($c->headers['Content-Type']),0,5) == 'image')
			{
				$this->type = 'image';
				$this->cache();
			}
			else
			{
				$this->type = 'text';
			}
		
			$this->base = $this->getBase();
			$this->retrieved = true;
			
			if ($autoGetChildren == true && $this->type != 'image')
			{
				$this->getChildren();
			}
		
			return $this->source;
		}
		else
		{
			throw new Exception("Asset has already been retrieved.");
		}
	}
	
	/**
	 * Retrieves the title of the asset if it contains a <title> tag. Otherwise, the
	 * page URL will be returned.
	 *
	 * @return string    the asset's page title
	 * @access public
	 */
	
	public function getPageTitle()
	{
		if ($this->retrieved)
		{
			if ($this->type == 'text')
			{
				preg_match_all($this->_regexPageTitle, $this->data, $matches);

				if (is_array($matches) && !empty($matches))
				{
					if ($matches[context][0])
					{
						return $matches[context][0];
					}
				}
			}
			
			return $this->source;
		}
		else
		{
			throw new Exception("Asset was not retrieved.");
		}
	}
	
	/**
	 * Caches an asset by storing it to the temp directory to conserve system memory.
	 *
	 * @access protected
	 */
	
	protected function cache()
	{
		$fp = fopen($this->tmpDirectory . DIRECTORY_SEPARATOR . $this->destination, 'w');
		fwrite($fp, $this->data);
		fclose($fp);
		
		$this->data = null;
		$this->cached = true;
	}
	
	/**
	 * Uncaches an asset by retrieving it from the temp directory and placing it back in
	 * system memory for final processing.
	 *
	 * @access protected
	 */
	
	protected function uncache()
	{
		$fp = fopen($this->tmpDirectory . DIRECTORY_SEPARATOR . $this->destination, 'r');
		$this->data = fread($fp, filesize($this->tmpDirectory . DIRECTORY_SEPARATOR . $this->destination));
		fclose($fp);
		
		unlink($this->tmpDirectory . DIRECTORY_SEPARATOR . $this->destination);
		$this->cached = false;
	}
	
	/**
	 * Saves the asset. If no filename is specified, it will auto assign an MD5 hash
	 * of the full URL.
	 *
	 * @param string $path                the folder to store the asset
	 * @param string $filename [optional] the filename to use in-place of the MD5 hash
	 * @return string                     the path to the asset
	 * @access public
	 */
	
	public function save($path, $filename = null)
	{
		if ($this->retrieved)
		{
			// Handle custom filename
			if ($filename)
			{
				$this->destination = $filename;
			}
			
			// Update any asset links
			if ($this->type == 'text')
			{
				$this->updateChildren();
			}
			
			if ($this->cached)
			{
				$this->uncache();
			}
			
			// Write the asset to the path
			$fp = fopen($path . DIRECTORY_SEPARATOR . $this->destination, 'w');
			fwrite($fp, $this->data);
			fclose($fp);
			
			$this->data = null;
		
			return $this->destination;
		}
		else
		{
			throw new Exception("Asset was not retrieved or has been already saved.");
		}
	}
	
	/**
	 * Finds any children of the current asset. Will automatically create new assets for
	 * the children and convert relative URLs.
	 *
	 * @return array containing all the children of the current asset
	 * @access public
	 */
	
	public function getChildren()
	{
		if ($this->retrieved)
		{
			// Only handle text
			if ($this->type == 'text')
			{
				foreach ($this->regexFindChildren as $type => $regex)
				{
					preg_match_all($regex, $this->data, $matches);

					if (is_array($matches) && !empty($matches))
					{
						for($i=0;$i<count($matches[0]);$i++)
						{
							if (substr($matches[url][$i], 0, 7) != 'mailto:' && substr($matches[url][$i], 0, 11) != 'javascript:' && substr($matches[url][$i], 0, 1) != '#' && trim($matches[url][$i]) != "")
							{
								$url = $this->addURL($matches[url][$i]);
								if (!$this->assets[$url])
								{
									new Rorrim_Asset($url, &$this->assets);
								}
							
								$this->children[count($this->children)]["relation"] = $type;
								$this->children[count($this->children)-1]["match"] = str_replace($matches[url][$i], $url, $matches[location][$i]);
								$this->children[count($this->children)-1]["asset"] = &$this->assets[$url];
								$this->children[count($this->children)-1]["context"] = $matches[context][$i];

								$this->data = str_replace($matches[location][$i], $this->children[count($this->children)-1]["match"], $this->data);
							}
						}
					}
				}
				return $this->children;
			}
			else
			{
				throw new Exception("Method not allowed. Asset is an image.");
			}
		}
		else
		{
			throw new Exception("Asset was not retrieved.");
		}
	}
	
	/**
	 * Finds any children of the current asset. Will update the original URL
	 * to the new URL.
	 *
	 * @access protected
	 */
	
	protected function updateChildren()
	{
		if ($this->retrieved)
		{
			// Only handle text
			if ($this->type == 'text')
			{
				if ($this->children)
				{
					foreach($this->children as $child)
					{
						$this->data = str_replace
						(
							$child[match],
							str_replace
							(
								$child[asset]->source,
								$child[asset]->destination,
								$child[match]
							),
							$this->data
						);
					}
				}
			}
			else
			{
				throw new Exception("Method not allowed. Asset is an image.");
			}
		}
		else
		{
			throw new Exception("Asset was not retrieved.");
		}
	}
	
	/**
	 * Gets the base of the current asset. Will read and remove any <base> tags from
	 * the asset.
	 *
	 * @return string the asset base
	 * @access protected
	 */
	
	protected function getBase()
	{
		// Check to see if we have a <base> tag first...
		if ($this->type == 'text')
		{
			// Find Base HREF
			preg_match_all($this->_regexBase, preg_replace('/<!--(.|\s)*?-->/', '', $this->data), $matches);

			if (is_array($matches) && !empty($matches) && $matches[0][0] != '')
			{
				// Get rid of it
				$this->data = str_replace($matches[0][0], '', $this->data);
				return $matches[1][0];
			}
		}
		
		// Figure out base from the URL...
		$filename = end(explode('/',$this->source));
		if ($filename)
		{
			// http://www.example.com
			if (strlen($this->source) - strlen($filename) <= 8)
			{
				return $this->source . '/';
			}
			// http://www.example.com/test.htm
			else if (substr($this->source, strlen($this->source) - 1, 1) != '/')
			{
				return substr($this->source, 0, strlen($this->source) - strlen($filename));
			}
			// http://www.example.com/directory/
			else
			{
				return $this->source;
			}
		}
		else
		{
			return $this->source;
		}
	}
	
	/**
	 * Adds a URL to the asset's base to handle relative links.
	 *
	 * @param string $url the URL to add to the base
	 * @return string     the full URL
	 * @access protected
	 */
	
	protected function addURL($url)
	{
		// Clean Up Excess
		$url = trim($url);
		$url = trim($url, "\"");
		$url = trim($url, "'");
		
		$base = $this->base;

		// Figure out Relative Links
		while ($finished == false)
		{
			if (substr($url,0,3) == '../')
			{
				$baseToRemove = end(explode('/',substr($base, 0, strlen($base)-1)));
				$base = substr($base, 0, strlen($base) - strlen($baseToRemove) - 1);
				$url = substr($url, 3);
			}
			else
			{
				$finished = true;
			}
		}

		if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0)
		{
			$url = $url;
		}
		else if (strpos($url, '/') === 0)
		{
			$url = substr($base, 0, strpos($base,'/',strpos($base,'/')+2)) . $url;
		}
		else
		{
			$url = $base . $url;
		}

		return $url;
	}
}

?>