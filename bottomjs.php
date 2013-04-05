<?php
/**
 * @copyright	Copyright (C) Adam Cox 2013.
 * @author      Adam Cox
 * @license		GNU General Public License version 2 or later; see LICENSE.txt
 * 
 * TODO ignore empty css hrefs
 * TODO single jquery or moo tools
 * TODO expose minify settings using fopen
 * TODO minify tags and external, or just tags
 * TODO minify script tag contents
 * TODO remove empty src and href attributes
 * TODO ignore css for specific media types, print projection etc
 * TODO check for duplicate inline js
 * TODO check for inline css
 */

// no direct access
defined('_JEXEC') or die;

/**
 * Joomla! System JS Load Manipulation Plugin
 *
 * @package		Joomla.Plugin
 * @subpackage	System.bottomjs
 */

class  plgSystemBottomjs extends JPlugin
{
    /**
     *  Contains scripts pulled from the document
     * @var array
     */
    private $scripts = array();
    /**
     * Contains css pulled from the document
     * @var array
     */
    private $css = array();
    /**
     * Stores copy of assets before minification in case of failure
     * @var array
     */
    private $preMinify = array();
    /**
     * Path to the minification library
     * @var string
     */
    private $minifyPath = '/plugins/system/bottomjs/min/?f=';
    /**
     * Backup of the original document as a string
     * @var null
     */
    private $origDoc = '';
    /**
     * The DOMDocument object that is used for processing
     * @var DOMDocument|null
     */
    private $doc = null;
    /**
     * String version of the processed document
     * @var string
     */
    private $newDoc = '';
    /**
     * Reference to JApplication object
     * @var JApplication|null
     */
    private $application = null;
    /**
     * Reference to JDocument object
     * @var JDocument|null
     */
    private $document = null;
    /**
     * List of scripts to ignore in the document
     * @var array
     */
    private $ignoreList = array();

    /**
     * Constructs the plugin object
     *
     * Link types are defined
     * References to application and document are stored
     * @param object $subject
     * @param array $config
     */
	function __construct(&$subject, $config = array())
	{
		define('TYPE_EXTERNAL', 2);
		define('TYPE_INTERNAL', 1);
		define('TYPE_INLINE', 0);
		
		// add the directory path to the minify url
		$this->minifyPath = JURI::base(true).$this->minifyPath;
		
		// set application and document references
		$this->application = JFactory::getApplication();
		$this->document = JFactory::getDocument();
		
		parent::__construct($subject, $config);
	}
	
	/**
	 * Method to catch the onBeforeRender event.
	 *
	 * This is where we catch the document before it is output, strip the tags
	 * and then insert them at the specified point.
	 *
	 * @return  boolean  True on success
	 *
	 * @since   2.5
	 */
	function onBeforeRender()
	{	
		// quit if in application is admin
		if($this->application->isAdmin())
			return;
		
		// get the document		
		$params = array('template' => $this->application->getTemplate(), 'file' => 'index.php', 'directory' => JPATH_THEMES);
		$caching = ($this->application->getCfg('caching') >= 2) ? true : false;
		
		// if the document is empty there is nothing to do here
        /** @var $docStr string */
        if(($docStr = $this->document->render($caching, $params)) === '')
			return;
		
		// take a backup of original document
		$this->origDoc = $docStr;
		
		// create the dom doc object
		$this->doc = $this->createDOMDoc($docStr);
		
		// strip the document of tags
		if((int) $this->params->get('move_js') && $this->stripScripts())
		{
			if((int) $this->params->get('minify_js'))
				$this->minify('scripts');
			
			// insert the scripts at
			$this->insert('scripts');
		}
		
		// move css if set
		if((int) $this->params->get('move_css') && $this->stripCSS())
		{
			if((int) $this->params->get('minify_css'))
				$this->minify('css');
			
			// insert the css
			$this->insert('css');
		}
	}

    /**
     * Creates a DOMDocument object from a string of HTML
     * @param string $string
     * @internal param $string string of HTML to crate a DOMDocument with
     * @return DOMDocument
     */
	 private function createDOMDoc($string='')
	 {
	 	$doc = new DOMDocument;
		$doc->preserveWhiteSpace = false;
		$doc->loadHTML($string);
		
		return $doc;
	 }
	/**
	 * Method to catch the onAfterRender event.
	 *
	 * This is where we catch the document before it is output, strip the tags
	 * and then insert them at the specified point.
	 *
	 * @return  boolean  True on success
	 *
	 * @since   1.5
	 */
	function onAfterRender()
	{
		// quit if in application is admin
		if($this->application->isAdmin())
			return;
		// TODO add as option
		// compress output with gzip
		if (substr_count($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip'))
            ob_start("ob_gzhandler");
        else
            ob_start();
		
		JResponse::setBody($this->newDoc == '' ? $this->doc->saveHTML() : $this->newDoc);
	}

	/**
	 * Method to remove scripts from a document.
	 * 
	 * @return  boolean  True on success
	 *
	 * @since   2.5
	 */
	private function stripScripts()
	{
		// get all of the script tags
		$scripts = $this->doc->getElementsByTagName('script');
		
		// convert the dom node list to an array
		$tmp = array();
		for($i = 0; $i < $scripts->length; $i++)
			$tmp[] = $scripts->item($i);
		$scripts = $tmp;
		
		// loop through instances of script tags in the document
        /** @var $element DOMElement */
        while($element = array_shift($scripts))
		{
			$attr = 'src';
			
			// set the scripts source type
			$type = ($this->scriptEmpty($element, true) ? ($this->isExternal($element, $attr) ? TYPE_EXTERNAL : TYPE_INTERNAL) : TYPE_INLINE);
			$empty = $this->scriptEmpty($element);
			
			$src = $element->getAttribute($attr);
						
			// check whether or not to move empty tags										
			if(((int) $this->params->get('ignore_empty') && $empty) || ($type != TYPE_INLINE && $this->inIgnoreList($src)))
			{
				continue;
			}
			else
			{
				// check if we need to resolve duplicates				
				if((int) $this->params->get('resolve_duplicates', 1))
				{
					//check to see if the script is already in the array
					$found = $type === TYPE_INLINE ? false : $this->resolveDuplicates($src);
					
					if(!$found)
						$this->scripts[] = array('element' => $element->cloneNode(true), 'type' => $type);
					
					$element->parentNode->removeChild($element);				
				}
				else
				{
					// add the script to the array
					$this->scripts[] = array('element' => $element->cloneNode(true), 'type' => $type);
					$element->parentNode->removeChild($element);
				}				
			}
		}

		// if there was nothing to remove then we might not need to continue
		if(empty($this->scripts))
			return false;
		
		// remove all scripts from the document object
		$this->document->_scripts = array();
		
		return true;
	}
	
	/**
	 * Method to check if a script has already been found
     *
     * @param string $src the source attribute value to check
     * @return bool
     */
	private function resolveDuplicates($src)
	{		
		// assume the script is not a duplicate until it is found
		$found = false;
		
		// if there are no scripts in the array the string passed cannot be a duplicate
		if(!count($this->scripts))
			return $found;
		
		// try an exact match first
		foreach($this->scripts as $script)
		{
			if($script['type'] === TYPE_INLINE)
				continue;

            /** @var $element DOMElement */
            $element = $script['element'];
			if($element->getAttribute('src') == $src)
			{
				$found = true;
				break;
			}
		}
		
		return $found;
	}

    /**
     * Method to resolve a local absolute URL into a relative URL
     *
     * @param DOMElement $element
     * @param string $attr
     * @return bool
     */
	 private function resolveLocalURL(DOMElement &$element, $attr)
	 {
	 	// get the source attribute so we can check it
	 	$url = $element->getAttribute($attr);
		$host = JURI::base();
		
		// if the host matches the first part of the url then replace it with nothing and modify the string
		if($resolved = (strpos($url, $host) === 0))
			$element->setAttribute($attr, preg_replace('['.preg_quote($host).']', JURI::base(true).'/', $url));
		
		return !$resolved;
	 }

	/**
	 * Method to catch the remove scripts from a document.
	 * 
	 * @return  boolean  True on success
	 *
	 * @since   2.5
	 */
	private function stripCSS()
	{
		// get all of the link tags
		$css = $this->doc->getElementsByTagName('link');
		
		// convert the dom node list to an array
		// filter out links that are not css
		$tmp = array();
		for($i = 0; $i < $css->length; $i++)
		{
            /** @var $el DOMElement */
            $el = $css->item($i);
			if($el->getAttribute('type') == 'text/css' || $el->getAttribute('rel') == 'stylesheet')
				$tmp[] = $css->item($i);
		}
		$css = $tmp;
		
		// loop through instances of script tags in the document
        /** @var $element DOMElement */
        while($element = array_shift($css))
		{
			// set the css source type
			// external is checked first so tht the url is resolved if applicable
			$external = $this->isExternal($element, 'href');
			$this->css[] = array('element' => $element->cloneNode(true), 'type' => $external ? TYPE_EXTERNAL : TYPE_INTERNAL);	
			$element->parentNode->removeChild($element);
		}
		
		// if there was nothing to remove then we might not need to continue
		if(empty($this->css))
			return false;
		
		// remove all css from the document object
		$this->document->_styleSheets = array();
		
		return true;
	}

    /**
     * Method to catch the insert the scripts into a document.
     *
     * @param string $assets the name of the asset set to insert
     * @param string $insertAt the element to insert the assets into
     * @return string  the processed document
     */
	private function insert($assets, $insertAt='')
	{
		// set the break point
		$insertElement = $this->doc->getElementsByTagName($insertAt == '' ? $assets == 'scripts' ? 'body' : 'head' : $insertAt)->item(0);
		
		// var for tracking inline output
		$middle = '';
		
		// loop assets and combine concurrent javascript tags
		foreach ($this->$assets as $asset)
		{
            /** @var $element DOMElement */
            $element = $asset['element'];
			
			if($assets == 'scripts')
			{
				if($asset['type'] === TYPE_INLINE)
				{
					$middle .= $element->nodeValue;
				}
				else
				{
					if($middle !== '')
					{
						$inline = new DOMElement('script');
						$insertElement->appendChild($inline);
						$inline->setAttribute('type', 'text/javascript');
						$cm = $inline->ownerDocument->createTextNode("\n//");
				        $ct = $inline->ownerDocument->createCDATASection("\n" . $middle . "\n//");
				        $inline->appendChild($cm);
				        $inline->appendChild($ct);
						$middle = '';
					}
					
					if(!(int) $this->params->get('remove_mootools') || !$this->isMootools($element->getAttribute('src')))
					{
						if(isset($asset['import']))
							$insertElement->appendChild($this->doc->importNode($element, true));
						else
							$insertElement->appendChild($element);
					}
				}
			}
			else
			{
				if(isset($asset['import']))
					$insertElement->insertBefore($this->doc->importNode($element, true), $insertElement->firstChild);
				else
					$insertElement->insertBefore($element, $insertElement->firstChild);
			}
		}
		
		$this->newDoc = $this->doc->saveHTML();
	}
	
	/**
	 * Returns true if a script tag has no content and the src attribute is blank
	 * The src attribute can be ignored by setting ignoreSource to true	 * 
	 * @param DOMElement $element the element to check
	 * @param bool $ignoreSource[optional] if true the src attribute will not be checked	 *
	 * @return bool
	 * 
	 * TODO check if the element is actually a script tag
	 */
	private function scriptEmpty(DOMElement $element, $ignoreSource = false)
	{
		if(preg_match('/^$|^\s+$/', $element->nodeValue))
			return $ignoreSource ? true : preg_match('/^$|^\s+$/', $element->getAttribute('src')) ? true : false;
			
		return false;
	}
	
	/**
	 * Checks script src attribute against ignore list
	 * @param string $src the src string to check against the ignore list
	 * @return bool
	 */
	private function inIgnoreList($src)
	{
		if($this->ignoreList === false)
			return false;
		
		//has the ignore list been created already
		if($this->ignoreList === null)
		{
			// set the ignore list
			$this->ignoreList = explode(",", (string) preg_replace('/\s/', '', $this->params->get('ignore_list')));
			if($this->ignoreList[0] === '')
			{
				$this->ignoreList = false;
				return false;
			}
		}
		
		$found = false;
		foreach ($this->ignoreList as $ignore)
		{
			if($src == $ignore)
			{
				$found = true;
				break;
			}
		}
		
		// check for the src in the ignore list
		return $found;
	}

    /**
     * Resolves a set of elements into as few elements as possible using the minify library
     * @param string $assets the set of assets to minify
     */
    private function minify($assets)
	{
		$list =& $this->$assets;
		
		$this->preMinify[$assets] = $list;
		
		$addScript = false;
		$url = $this->minifyPath;
		
		// position at which to insert the minified script
		$insertAt = 0;
		
		foreach($list as $key => $asset)
		{
			if($asset['type'] == TYPE_INTERNAL)
			{
				$addScript = true;
                /** @var $element DOMElement */
                $element = $asset['element'];
				$url .= $element->getAttribute($assets == 'scripts' ? 'src' : 'href') . ',';
								
				if($insertAt == 0)
					$insertAt = $key;
				
				unset($list[$key]);
			}
		}
		
		if(!$addScript)
			return;
		
		// remove the last comma
		$url = substr($url, 0, strlen($url) -1);
		
		// create a temporary dom document
		$dom = new DOMDocument;
		
		// create the new dom element
		if($assets == 'scripts')
		{
			$element = new DOMElement('script');
			$dom->appendChild($element);
			$element->setAttribute('type', 'text/javascript');
			$element->setAttribute('src', $url);
		}
		else
		{
			$element = new DOMElement('link');
			$dom->appendChild($element);
			$element->setAttribute('type', 'text/css');
			$element->setAttribute('rel', 'stylesheet');
			$element->setAttribute('href', $url);
		}
		
		// insert the minify source into the scripts array
		$list = $this->array_insert_at($insertAt, array('element' => $element->cloneNode(true), 'type' => TYPE_INTERNAL, 'import' => true), $list);
	}
	
	/**
	 * Checks an element to see if its src/href is external or not
	 * 
	 * @param DOMElement $element the element to check
	 * @param string $attr the attribute to use in the check
     * @return bool
     */
	private function isExternal(DOMElement $element, $attr)
	{
		// get the elments attribute value	
		$src = $element->getAttribute($attr);
		if($src == '' || $src == null)
			return false;
		
		// set protocals to check
		$prots = array('http','https','//');
		$external = false;
		
		// loop over protocols and check for presence the attribute value
		foreach($prots as $prot)
		{
			if(strpos($src, $prot) === 0)
			{
				$external = true;
				break;
			}
		}
		
		//TODO set as an option
		// resolve local absolute urls into relative urls
		if($external)
			$external = $this->resolveLocalURL($element, $attr);
		
		return $external;
	}
	/**
	 * Checks a script source for evidence of mootools
	 */
	private function isMootools($src)
	{		
		return strpos($src, '/mootools') !== false;			
	}

    /**
     * @param $index
     * @param $insert
     * @param $array
     * @return array
     */
    private function array_insert_at($index, $insert, $array)
	{
		return array_merge(array_slice($array, 0, $index), array($insert), array_slice($array, $index));
	}
}