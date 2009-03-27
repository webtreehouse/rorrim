<?php

/**
 * @author Chris Hagerman (chris@webtreehouse.com)
 * @copyright copyright 2009, Chris Hagerman, released under the GPL.
 * @license http://www.gnu.org/licenses/gpl.html
 * @version 0.5
 * @package Rorrim
 *
 * This example demonstrates how to retrieve a single web page and save a local
 * copy of any images, javascript, and css files.
 */

function __autoload($class)
{
  $filename = str_replace('_', DIRECTORY_SEPARATOR, $class) . '.php';
  require_once $filename;
}

try
{
	// Create a new asset and retrieve its contents
	$page = new Rorrim_Asset("http://google.com/");
	$page->retrieve();

	// Find all of the linked assets on the page
	foreach ($page->children as $child)
	{
		// Retrieve anything that's not a link to another web page
		if ($child['relation'] != 'anchor')
		{
			// Get the contents and save to our output directory
			if (!$child['asset']->retrieved)
			{
				try
				{
					$child['asset']->retrieve();
					$child['asset']->save("ExampleOutput");					
				}
				catch (Exception $e)
				{
					printf("Warning: %s\r\n", $e);
				}
			}
		}
	}

	// Grab a couple of values before we dispose the page
	$title = $page->getPageTitle();
	$url = $page->source;
	
	// Save the page to the output directory
	$page->save("ExampleOutput","index.html");
	
	printf("Successfully retrieved '%s' (%s)\r\n", $title, $url);
}
catch (Exception $e)
{
	printf("An unexpected error has occurred. %s\r\n", $e);
}

?>