<?php

include "RecipeManager.php";
include "Logger.php";
include "Functions.php";
include "Json.php";
include "User.php";
include "PrefTab.php";

class Feediron extends Plugin implements IHandler
{
	private $host;
	private $charset;
	private $json_error;
	const plugindata_tag = 'feediron';

	// Required API
	function about()
	{
		return array(
			1.0,   // version
			'Irons a feeds content to your needs',   // description
			'm42e',   // author
			false,   // is_system
		);
	}

	// Required API
	function api_version()
	{
		return 2;
	}

	// Required API for adding the hooks
	function init($host)
	{
		$this->host = $host;
		$host->add_hook($host::HOOK_PREFS_TABS, $this);
		$host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
	}

	// Required API, Django...
	function csrf_ignore($method)
	{
		$csrf_ignored = array("index", "edit");
		return array_search($method, $csrf_ignored) !== false;
	}

	// Allow only in active sessions
	function before($method)
	{
		if ($_SESSION["uid"])
		{
			return true;
		}
		return false;
	}

	// Required API
	function after()
	{
		return true;
	}

	// The hook to filter the article. Called for each article
	function hook_article_filter($article)
	{
		Feediron_Logger::get()->set_log_level(0);
		if (($config = $this->getConfigSection($article['link'])) !== FALSE)
		{
			$articleMarker = $this->getMarker($article, $config);
			if (strpos($article['plugin_data'], $articleMarker) !== false)
			{
				return $article;
			}

			Feediron_Logger::get()->log(Feediron_Logger::LOG_TTRSS, "Article was not fetched yet: ".$article['link']);
			$link = $this->reformatUrl($article['link'], $config);
			$article['content'] = $this->getNewContent($link, $config);
			$article['plugin_data'] = $this->addArticleMarker($article, $articleMarker);
		}

		return $article;
	}
	//Creates a marker for the article processed with specific config
	function getMarker($article, $config){
		$articleMarker = self::plugindata_tag.":".$article['owner_uid'].",".md5(print_r($config, true)).":";
		return $articleMarker;
	}

	// Removes old marker and adds new one
	function addArticleMarker($article, $marker){
		return preg_replace('/'.self::plugindata_tag.':'.$article['owner_id'].',.*?:/','',$article['plugin_data']).$marker;
	}

	function getConfigSection($url)
	{
		$data = $this->getConfig();
		if(is_array($data)){
			foreach ($data as $urlpart=>$config) {
				if (strpos($url, $urlpart) === false){
					continue;   // skip this config if URL not matching
				}
				Feediron_Logger::get()->log_object(Feediron_Logger::LOG_TEST, "Config found", $config);
				return $config;
			}
		}
		return FALSE;
	}

	// Load config
	function getConfig()
	{
		$json_conf = $this->host->get($this, 'json_conf');
		$data = json_decode($json_conf, true);
		if(Feediron_Logger::get()->get_log_level() == 0){
			Feediron_Logger::get()->set_log_level((isset($data['debug']) && $data['debug'])||!is_array($data));
		}
		if(!is_array($data)){
			Feediron_Logger::get()->log(Feediron_Logger::LOG_TTRSS, "No Config found");
		}
		return $data;
	}

	// reformat an url with a given config
	function reformatUrl($url, $config)
	{
		$link = trim($url);
		if(is_array($config['reformat']))
		{
			$link = $this->reformat($link, $config['reformat']);
			Feediron_Logger::get()->log(Feediron_Logger::LOG_TTRSS, "Reformated url: ".$link);
		}
		return $link;
	}

	// reformat a string with given options
	function reformat($string, $options)
	{
		Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "Reformat ", $string);
		foreach($options as $option)
		{
			Feediron_Logger::get()->log_object(Feediron_Logger::LOG_VERBOSE, "Reformat step with option ", $option);
			switch($option['type'])
			{
			case 'replace':
				$string = str_replace($option['search'], $option['replace'], $string);
				break;

			case 'regex':
				$string = preg_replace($option['pattern'], $option['replace'], $string);
				break;
			}
			Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "Step result ", $string);
		}
		Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "Result ", $string);
		return $string;
	}

	// grep new content for a link and aplly config
	function getNewContent($link, $config)
	{
		$links = $this->getLinks($link, $config);
		Feediron_Logger::get()->log(Feediron_Logger::LOG_TTRSS, "Fetching ".count($links)." links");
		Feediron_Logger::get()->log(Feediron_Logger::LOG_TEST, "Fetching ".count($links)." links", join("\n", $links));
		$html_complete = "";
		foreach($links as $lnk)
		{
			$html = $this->getArticleContent($lnk, $config);
			Feediron_Logger::get()->log(Feediron_Logger::LOG_TEST, "Original Source ".$lnk.":", htmlentities($html));
			$html = $this->processArticle($html, $config);
			Feediron_Logger::get()->log(Feediron_Logger::LOG_TEST, "Modified Source ".$lnk.":", htmlentities($html));
			$html_complete .= $html;
		}
		return $html_complete;

	}
	//extract links for multipage articles
	function getLinks($link, $config){
		if (isset($config['multipage']))
		{
			$links = $this->fetch_links($link, $config);
		}
		else
		{
			$links = array($link);
		}
		return $links;
	}
	function getArticleContent($link, $config)
	{
		list($html, $content_type) = $this->get_content($link);

		$this->charset = false;
		if (!isset($config['force_charset']))
		{
			if ($content_type)
			{
				preg_match('/charset=(\S+)/', $content_type, $matches);
				if (isset($matches[1]) && !empty($matches[1])) {
					$this->charset = $matches[1];
				}
			}
		} else {
			// use forced charset
			$this->charset = $config['force_charset'];
		}

		Feediron_Logger::get()->log(Feediron_Logger::LOG_TEST, "charset:", $this->charset);
		if ($this->charset && isset($config['force_unicode']) && $config['force_unicode'])
		{
			$html = iconv($this->charset, 'utf-8', $html);
			$this->charset = 'utf-8';
			Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "Changed charset to utf-8:", htmlentities($html));
		}
		return $html;
	}
	function get_content($link)
	{
		global $fetch_last_content_type;
		Feediron_Logger::get()->log(Feediron_Logger::LOG_TTRSS, $link);
		if (version_compare(VERSION, '1.7.9', '>='))
		{
			$html = fetch_file_contents($link);
			$content_type = $fetch_last_content_type;
		}
		else
		{
			// fallback to file_get_contents()
			$html = file_get_contents($link);

			// try to fetch charset from HTTP headers
			$headers = $http_response_header;
			$content_type = false;
			foreach ($headers as $h)
			{
				if (substr(strtolower($h), 0, 13) == 'content-type:')
				{
					$content_type = substr($h, 14);
					// don't break here to find LATEST (if redirected) entry
				}
			}
		}
		return array( $html,  $content_type);
	}
	function fetch_links($link, $config)
	{
		$html = $this->getArticleContent($link, $config);
		$links = $this->extractlinks($html, $config);
		if (count($links) == 0)
		{
			return array($link);
		}
		$links = $this->fixlinks($link, $links);
		foreach ($links as $lnk)
		{
			Feediron_Logger::get()->log(Feediron_Logger::LOG_TEST, "link:".$lnk);
		}
		if(isset($config['multipage']['append']) && $config['multipage']['append'])
		{
			array_unshift($links, $link);
		}
		return $links;

	}
	function extractlinks($html, $config)
	{
		$doc = $this->getDOM($html);
		$links = array();

		$xpath = new DOMXPath($doc);
		$entries = $xpath->query('(//'.$config['multipage']['xpath'].')');   // find main DIV according to config


		if ($entries->length < 1){
			return array();
		}
		if($this->loglevel == Feediron_Logger::LOG_VERBOSE){
			$log_entries = array_map( array($this, 'getHtmlNode') , $entries);
			Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "Found ".count($entries)." link elements:", htmlentities(join("\n", $log_entries)));
		}
		foreach($entries as $entry)
		{
			$links[] = $entry->getAttribute('href');
		}
		return $links;
	}
	function getHtmlNode($node){ 
		$newdoc = new DOMDocument();
		$cloned = $node->cloneNode(TRUE);
		$newdoc->appendChild($newdoc->importNode($cloned,TRUE));
		return $newdoc->saveHTML();
   	}
	function getDOM($html){
		$doc = new DOMDocument();
		if ($this->charset) {
			$html = '<?xml encoding="' . $this->charset . '">' . $html;
		}
		libxml_use_internal_errors(true);
		$doc->loadHTML($html);
		if(!$doc)
		{
			Feediron_Logger::get()->log(Feediron_Logger::LOG_TTRSS, "The content is not a valid xml format");
			if($this->debug)
			{
				foreach (libxml_get_errors() as $value)
				{
					Feediron_Logger::get()->log(Feediron_Logger::LOG_TTRSS, $value);
				}
			}
			return new DOMDocument();
		}
		return $doc;
	}
	function fixlinks($link, $links)
	{
		$retlinks = array();
		foreach($links as $lnk)
		{
			$retlinks[] = $this->rel2abs($lnk, $link);
		}
		return $retlinks;
	}
	function rel2abs($rel, $base)
	{
		if (parse_url($rel, PHP_URL_SCHEME) != '' || substr($rel, 0, 2) == '//') {
			return $rel;
		}
		if ($rel[0]=='#' || $rel[0]=='?') {
			return $base.$rel;
		}
		Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "Transform url for base ".$base, $rel);
		extract(parse_url($base));
		$path = preg_replace('#/[^/]*$#', '', $path);
		if ($rel[0] == '/') {
			$path = '';
		}

		/* dirty absolute URL */
		$abs = "$host$path/$rel";

		/* replace '//' or '/./' or '/foo/../' with '/' */
		$re = array('#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#');
		for($n=1; $n>0; $abs=preg_replace($re, '/', $abs, -1, $n)) {}

		Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "Transform result ", $scheme.'://'.$abs);
		/* absolute URL is ready! */
		return $scheme.'://'.$abs;
	}
	function processArticle($html, $config)
	{
		switch ($config['type'])
		{
		case 'split':
			$html = $this->performSplit($html, $config);
			break;

		case 'xpath':
			$html = $this->performXpath($html, $config);
			break;

		default:
			Feediron_Logger::get()->log(Feediron_Logger::LOG_TTRSS, "Unrecognized option: ".$config['type']);
			continue;
		}
		if(is_array($config['modify']))
		{
			$html = $this->reformat($html, $config['modify']);
		}
		return $html;
	}

	function performSplit($html, $config){
		$orig_html = $html;
		foreach($config['steps'] as $step)
		{
			Feediron_Logger::get()->log_object(Feediron_Logger::LOG_VERBOSE, "Perform step: ", $step);
			if(isset($step['after']))
			{
				$result = preg_split ($step['after'], $html);
				$html = $result[1];
			}
			if(isset($step['before']))
			{
				$result = preg_split ($step['before'], $html);
				$html = $result[0];
			}
			Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "Step result", htmlentities($html));
		}
		if(strlen($html) == 0)
		{
			Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "removed all content, reverting");
			return $orig_html;
		}
		if(isset($config['cleanup']))
		{
			foreach($config['cleanup'] as $cleanup)
			{
				Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "Cleaning up", $cleanup);
				$html = preg_replace($cleanup, '', $html);
				Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "cleanup  result", htmlentities($html));
			}
		}
		return $html;
	}

	function performXpath($html, $config)
	{
		$doc = $this->getDOM($html);
		$basenode = false;
		$xpath = new DOMXPath($doc);
		$entries = $xpath->query('(//'.$config['xpath'].')');   // find main DIV according to config

		if ($entries->length > 0) {
			$basenode = $entries->item(0);
		}

		if (!$basenode) {
			Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "removed all content, reverting");
			return $html;
		}

		Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "Extracted node", htmlentities($this->getHtmlNode($basenode)));
		// remove nodes from cleanup configuration
		$basenode = $this->cleanupNode($xpath, $basenode, $config);
		$html = $this->getInnerHtml($basenode);
		return $html;
	}
	function getInnerHtml( $node ) {
		$innerHTML= '';
		$children = $node->childNodes;
		foreach ($children as $child) {
			$innerHTML .= $child->ownerDocument->saveXML( $child );
		}

		return $innerHTML;
	} 
	function cleanupNode($xpath, $basenode, $config)
	{
		if(($cconfig = $this->getCleanupConfig($config))!== FALSE)
		{
			foreach ($cconfig as $cleanup)
			{
				Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "cleanup", $cleanup);
				if(strpos($cleanup, "./") !== 0)
				{
					$cleanup = '//'.$cleanup;
				}
				$nodelist = $xpath->query($cleanup, $basenode);
				foreach ($nodelist as $node)
				{
					if ($node instanceof DOMAttr)
					{
						$node->ownerElement->removeAttributeNode($node);
					}
					else
					{
						$node->parentNode->removeChild($node);
					}
				}
				Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "Node after cleanup", htmlentities($this->getHtmlNode($basenode)));
			}
		}
		return $basenode;
	}

	function getCleanupConfig($config)
	{
		$cconfig = false;

		if (isset($config['cleanup']))
		{
			$cconfig = $config['cleanup'];
			if (!is_array($cconfig))
			{
				$cconfig = array($cconfig);
			}
		}
		Feediron_Logger::get()->log_object(Feediron_Logger::LOG_VERBOSE, "Cleanup config", $cconfig);
		return $cconfig;
	}

	function hook_prefs_tabs($args)
	{
		print '<div id="feedironConfigTab" dojoType="dijit.layout.ContentPane"
			href="backend.php?op=feediron"
			title="' . __('FeedIron') . '"></div>';
	}

	function index()
	{
		$pluginhost = PluginHost::getInstance();
		$json_conf = $pluginhost->get($this, 'json_conf');
		print Feediron_PrefTab::get_pref_tab($json_conf);
	}

	/*
	 * Storing the json reformat data
	 */
	function save()
	{
		$json_conf = $_POST['json_conf'];

		$json_reply = array();
		Feediron_Json::format($json_conf);
		header('Content-Type: application/json');
		if (is_null(json_decode($json_conf)))
		{
			$json_reply['success'] = false;
			$json_reply['errormessage'] = __('Invalid JSON! ').json_last_error_msg();
			$json_reply['json_error'] = Feediron_Json::get_error();
			echo json_encode($json_reply);
			return false;
		}

		$this->host->set($this, 'json_conf', Feediron_Json::format($json_conf));
		$json_reply['success'] = true;
		$json_reply['message'] = __('Configuration saved.');
		$json_reply['json_conf'] = Feediron_Json::format($json_conf);
		echo json_encode($json_reply); 
	}

	function export(){
		$conf = $this->getConfig();
		$recipe2export = $_POST['recipe'];
		Feediron_Logger::get()->log(Feediron_Logger::LOG_TTRSS, "export recipe: ".$recipe2export);
		header('Content-Type: application/json');
		if(!isset ($conf[$recipe2export])){
			$json_reply['success'] = false;
			$json_reply['errormessage'] = __('Not found');
			echo json_encode($json_reply); 
			return false;
		}
		$json_reply['success'] = true;
		$json_reply['message'] = __('Exported');
		$data = array(
			"name"=> (isset($conf[$recipe2export]['name'])?$conf[$recipe2export]['name']:$recipe2export), 
			"url" => (isset($conf[$recipe2export]['url'])?$conf[$recipe2export]['url']:$recipe2export),
			"stamp" => time(),
			"author" => Feediron_User::get_full_name(),
			"match" => $recipe2export,
			"config" => $conf[$recipe2export]
		);
		$json_reply['json_export'] = Feediron_Json::format(json_encode($data));
		echo json_encode($json_reply); 
	}
	function add(){
		$conf = $this->getConfig();
		$recipe2add = $_POST['addrecipe'];
		Feediron_Logger::get()->log(Feediron_Logger::LOG_TTRSS, "recipe: ".$recipe2add);
		$rm = new RecipeManager();
		$recipe = $rm->getRecipe($recipe2add);
		header('Content-Type: application/json');
		if(!isset ($recipe['match'])){
			$json_reply['success'] = false;
			$json_reply['errormessage'] = __('Github API message: ').$recipe['message'];
			$json_reply['data'] = Feediron_Json::format(json_encode($recipe));
			echo json_encode($json_reply); 
			return false;
		}
		if(isset ($conf[$recipe['match']])){
			$conf[$recipe['match'].'_orig'] = $conf[$recipe['match']];
		}
		$conf[$recipe['match']] = $recipe['config'];
		
		$json_reply['success'] = true;
		$json_reply['message'] = __('Configuration updated.');
		$json_reply['json_conf'] = Feediron_Json::format(json_encode($conf));
		echo json_encode($json_reply); 
	}

	/*
	 *  this function tests the rules using a given url
	 */
	function test()
	{
	   Feediron_Logger::get()->set_log_level($_POST['verbose']?Feediron_Logger::LOG_VERBOSE:Feediron_Logger::LOG_TEST);
	   $test_url = $_POST['test_url'];
	   Feediron_Logger::get()->log(Feediron_Logger::LOG_TTRSS, "Test url: $test_url");
	   $config = $this->getConfigSection($test_url);
	   $test_url = $this->reformatUrl($test_url, $config);
	   Feediron_Logger::get()->log(Feediron_Logger::LOG_TTRSS, "Url after reformat: $test_url");
	   header('Content-Type: application/json');
	   $reply = array();
	   if($config === FALSE)
	   {
		   $reply['success'] = false;
		   $reply['errormessage'] = "URL did not match";
		   $reply['log'] = Feediron_Logger::get()->get_testlog();
		   echo json_encode($reply);
		   return false;
	   }
	   else
	   {
		   $reply['success'] = true;
		   $reply['url'] = $test_url;
		   $reply['content'] = $this->getNewContent($test_url, $config);
		   $reply['log'] = Feediron_Logger::get()->get_testlog();
		   echo json_encode($reply);
		}
	}
}

