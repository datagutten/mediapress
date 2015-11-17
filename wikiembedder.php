<?Php
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );
/*
Plugin Name: Wikiembedder
Plugin URI:  http://URI_Of_Page_Describing_Plugin_and_Updates
Description: Embed MediaWiki in a wordpress site and allow users to navigate the wiki without leaving your wordpress page.
Version:     0.1
Author:      Anders Birkenes
Author URI:  https://github.com/datagutten
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

class wikiembedder {
	public $ch;
	public $wikiurl; //Full url to the wiki
	public $wikidir; //Wiki subdir to be used a source for relative link rewriting
	public $page;
	function __construct() {
		$this->ch = curl_init();
		curl_setopt( $this->ch, CURLOPT_USERAGENT, 'Wikiembedder/0.1 (https://github.com/datagutten/wikiembedder)' );
		curl_setopt( $this->ch, CURLOPT_ENCODING, "UTF-8" );
		curl_setopt( $this->ch, CURLOPT_RETURNTRANSFER, true );

		//Initialize the plugin on WordPress initialization
		add_action( 'init', array( $this, 'init' ) );
		//Initalize options
		add_option( 'wikiurl' , 'https://en.wikipedia.org/wiki');
		$this->wikiurl = get_option( 'wikiurl' );
		$this->wikidir = basename( $this->wikiurl );
		//Admin menu
		require 'admin_menu.php';
	}
	//Register shortcode, add template redirect and add css
	function init() {
		add_shortcode( 'wiki', array($this, 'shortcode') );
		add_action( 'template_redirect', array( $this, 'get_page' ) );
		wp_enqueue_style( 'wiki-embed-style', plugins_url( '/wikiembedder/wikiembedder.css' ), false, $this->version, 'screen' );
	}

	//Do a HTTP GET request with the correct useragent
	function http_get($url) {
		curl_setopt($this->ch,CURLOPT_URL,$url);
		$data = curl_exec($this->ch);
		//Error handling
		if($data === false) {
			trigger_error(curl_error($this->ch),E_USER_WARNING);
			return false;
		}
		return $data;
	}

	//Rewrite links to image pages to link directly to the image file
	function rewrite_images($text) {
		$dom = new DOMDocument("1.0", "UTF-8");
		$dom->loadHTML( mb_convert_encoding( $text, 'HTML-ENTITIES', 'UTF-8' ) ); //Load the page as correct UTF-8

		//Loop through the links
		foreach( $dom->getElementsByTagName( 'a' ) as $a ) {
			//Get images inside the link
			$img = $a->getElementsByTagName( 'img' );
			//We only want links with images inside
			if( $img->length==0 ) {
				continue;
			}
			//Get the link href
			$href = $a->getAttribute( 'href' );
			//Remove everything before the namespace declaration
			$href = preg_replace( '/.*?([A-Za-z]+:.+)/','$1', $href); 
			//Get the image info as JSON
			$imageinfo_raw = $this->http_get( $this->wikiurl.'/api.php?action=query&prop=imageinfo&iiprop=url|parsedcomment&format=json&titles='.basename($href) );
			//Parse the JSON
			$imageinfo = json_decode( $imageinfo_raw, true );
			//Get the last (only) element
			$imageinfo = array_pop( $imageinfo['query']['pages'] );
			$imageinfo = $imageinfo['imageinfo'][0];
			//Set the link target to the image file
			$a->setAttribute( 'href', $imageinfo['url'] );
			//Rewrite the picture text
			$text=$this->rewrite( $imageinfo['parsedcomment'], 'noimages' );
			//Set the title attribute to the picture text (works nice with simple-lightbox)
			$a->setAttribute( 'title', $text );
		}
		return $dom->saveXml();
	}

	//Rewrite links and image tags
	function rewrite ( $text, $mode='images' ) {
		//Rewrite links to other wiki pages
		$text = str_replace( '/'.$this->wikidir.'/index.php/', '?wikipage=',$text );
		//Rewrite other relative links
		$text = str_replace( '/'.$this->wikidir,$this->wikiurl, $text );
		//Rewrite image
		if( $mode == 'images' ) {
			$text = $this->rewrite_images( $text );
		}
		return $text;
	}

	//Load the page from the wiki and rewrite links
	function load_page( $page ) {		
		$url=$this->wikiurl.'/api.php?action=parse&format=json&disableeditsection&redirects&page='.urlencode($page);
		curl_setopt($this->ch,CURLOPT_URL,$url);
		$json_raw=curl_exec($this->ch);
		$json=json_decode($json_raw,true);
		$json['parse']['text']['*']=$this->rewrite($json['parse']['text']['*']);
		return $json;
	}

	//Shortcode handler
	function shortcode( $atts ) {
		$a = shortcode_atts( array(
		'page'=>'Forside',
		'namespace'=>false)
		, $atts );
		if(!empty($_GET['wikipage']))
			$a['page']=$_GET['wikipage'];
		$json=$this->load_page($a['page']);
		$text=$json['parse']['text']['*'];
		return $text;
	}

	//Load a page using get parameter
	function get_page() {
		if(!isset($_GET['wikipage']))
			return true;
		$pagedata=$this->load_page($_GET['wikipage']);

		global $wp_query;
		//This code is copied from RDP Wiki-Press Embed by Robert D Payne
		$wp_query->is_home = false;
		$wp_query->is_page = true;
		$wp_query->post_count = 1;

		$admin_email = get_bloginfo( 'admin_email' );
		$user = get_user_by( 'email', $admin_email );
		$title = $pagedata['parse']['title'];

		$post = (object) null;
		$post->ID = 0; // wiki-embed is set to 0
		$post->post_title 	= $title; //Set the post title to the wiki page title
		$post->post_name 	= sanitize_title($title);
		$post->post_name 	= sanitize_title($pagedata['parse']['title']);
		$post->guid 		= get_site_url()."?wikipage=".urlencode($title);
		$post->post_content = $pagedata['parse']['text']['*'];
		$post->post_status 	= "published";
		$post->comment_status = "closed"; //We don't want comments on a page that not really exists
		$post->post_modified = date( 'Y-m-d H:i:s' ); //The page is generated now, so set the modified time to now
		$post->post_excerpt = "excerpt nothing goes here";
		$post->post_parent 	= 0; //No parent
		$post->post_type 	= "page";
		$post->post_date 	= date( 'Y-m-d H:i:s' );
		$post->post_author 	= $user->ID; // newly created posts are set as if they are created by the admin user

		$wp_query->posts = array( $post );
		$wp_query->queried_object = $post; // this helps remove some errors 
	}
}
$wikiembedder=new wikiembedder();