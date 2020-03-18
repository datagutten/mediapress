<?Php
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );
/*
Plugin Name: Bussdatabasen WP
Plugin URI:  https://github.com/datagutten/bussdatabasen-wp
Description: 
Version:     0.1
Author:      Anders Birkenes
Author URI:  https://github.com/datagutten
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/
apply_filters( 'wp_title', 'foo', 100);
class bussdatabasen {
	public $ch;
	public $wikiurl; //Full url to the wiki
	public $django_url;
	public $wikidir; //Wiki subdir to be used a source for relative link rewriting
	public $page;
	public $version = '0.1';
	public $response;
	/**
	 * @var DOMDocument
	 */
	public $dom;
	public $error;

	public $frame_page_id;

	/**
	 * bussdatabasen constructor.
	 */
	function __construct() {
		//Initialize the plugin on WordPress initialization
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'wp_loaded', array ($this, 'preload_page') );
		apply_filters( 'pre_get_document_title', array($this, 'title'), 100);
		//apply_filters( 'wp_title', array($this, 'title'), 100);

		//Initalize options
		add_option( 'django_url' , 'https://db.bussdatabasen.no');
		add_option( 'django_page_id');
		$this->django_url    = get_option( 'django_url' );
		$this->frame_page_id = get_option('django_page_id');
		$this->wikidir       = basename( $this->django_url );
		//Admin menu
		require 'admin_menu.php';
	}

	//Register shortcode, add template redirect and add CSS
	function init() {
		add_shortcode( 'bussdatabasen', array($this, 'shortcode') );
		//add_action( 'template_redirect', array( $this, 'get_page' ) );
		wp_enqueue_style( 'wiki-embed-style', plugins_url( '/wikiembedder/wikiembedder.css' ), false, $this->version, 'screen' );
	}

	//Called by add_action( 'wp_loaded' ...
	function preload_page()
	{
		if(!isset($_GET['page'])) //Home page
			$url=$this->django_url;
		else
		{
			$page=$_GET['page'];
			if($page[0]=='/') //Internal link
			{
				$url=$this->django_url.$page;
			}
			else //External link
			{
				$url=$page;
				trigger_error('External link loaded: '.$page);
			}
		}

		if(!empty($_POST))
		{
			if(!empty($_FILES))
				trigger_error('File upload not supported, form should be sent directly to django',E_USER_WARNING);
			/*{
				$fp=fopen($file=$_FILES['bilde']['tmp_name']);
				/*$data=file_get_contents($_FILES['bilde']['tmp_name']);
				$base64=base64_encode($data);
				$_POST['bilde']=$base64;
				trigger_error(print_r($_FILES,true));*/
				/*$file_data=fread($fp,$_FILES['bilde']['size']);
				$_POST['bilde']=$file_data;
			}*/
			$referer = $_SERVER['HTTP_REFERER'];
			$referer_page = preg_replace('/.+page=(.+)/', '$1', $referer);
			$referer_page = urldecode($referer_page);
			$referer = sprintf('%s%s', $this->django_url, $referer_page);
			//$referer = $_GET['page'];
			//var_dump($referer);
			$response = wp_remote_post( $url, array('cookies'=>$_COOKIE, 'body'=>$_POST, 'redirection'=>0, 'referer'=>$referer));
		}
		else
			$response = wp_remote_get( $url , array('cookies'=>$_COOKIE, 'redirection'=>0));

		$this->response=$response;
		if( is_wp_error( $response ) )
		{
			$this->error=$response->get_error_message();
			return false;
		}
		else
		{
			$cookies = wp_remote_retrieve_header($response, 'set-cookie');
			if(!is_array($cookies))
				$cookies = array($cookies);
			foreach($cookies as $cookie)
			{
				if(preg_match_all('/(\S+)=(.+);/U',$cookie.';',$fields)) //Parse cookies
				{
					$name=$fields[1][0];
					$fields=array_combine($fields[1],$fields[2]);
					if(!isset($fields['expires']))
						$expire=0;
					else
						$expire=strtotime($fields['expires']);
					if(!isset($fields['Path']))
						$fields['Path']='';
					if(!isset($fields['Domain']))
						$fields['Domain']='';

					setcookie($name,$fields[$name],$expire,$fields['Path'],$fields['Domain']);
				}
			}
			$response_code = wp_remote_retrieve_response_code($response);
			if($response_code >=300 && $response_code<=399)
			{
				$location = wp_remote_retrieve_header($response, 'location');
				trigger_error('Location: '.$location);
				if(strpos($location,'?next=')!==false)
				{
					//Fjerne next og la login gå til default?
					//Sjekke at det er login
					//Fjerne next hvis login
					parse_str(parse_url($location,PHP_URL_QUERY),$parameters);
					$next_link='https://www.bussdatabasen.no/bevarte-busser/?page='.$parameters['next'];
					$location=str_replace('next='.$parameters['next'],'next='.$next_link,$location);
					/*trigger_error(print_r($parameters,true));
					trigger_error($location);
					trigger_error($next_link);*/
				}				
				
				$url='https://www.bussdatabasen.no/bevarte-busser/?page=';
				if($location[0]=='/')
					header('Location: '.$url.urlencode($location));
				else
					header('Location: '.$location);
				//wp_die();
				die();
			}
			else
			{
				if(!class_exists('DOMDocument'))
					return 'DOMDocument is not available';
				$this->dom=new DOMDocument;
				$this->dom->loadHTML($response['body']);
			}
		}
	}

	function get_title()
	{
		$title=$this->dom->getElementsByTagName('title');
		return $title->item(0)->textContent;
	}

	//Rewrite links and image tags
	function rewrite ()
	{
		if(empty($this->dom))
			return $this->error;
		$post = get_post();
		if($post->ID!=$this->frame_page_id)
		{
			//print_r(get_post($this->frame_page_id));
			$url_prefix = get_permalink($this->frame_page_id);
		}
		else
			$url_prefix ='';

		$dom=$this->dom;
		$body=$dom->getElementById('bussdatabasen');
		$xpath=new DOMXPath($dom);
		$images=$dom->getElementsByTagName('img');
		foreach($images as $image) //Rewrite relative image paths to absolute
		{
			$src=$image->getAttribute('src');
			if($src[0]=='/')
			{
				$src=$this->django_url.$src;
				$image->setAttribute('src',$src);
			}
		}
		//$attrs['src']=$xpath->query('//*[@src]');
		$attrs['href']=$xpath->query('//*[@href]');
		$attrs['action']=$xpath->query('//form[@action]');
		foreach($attrs as $attr_name=>$attr)
		{
			foreach($attr as $element)
			{
				$uri=$element->getAttribute($attr_name);
				if($uri[0]!='/')
					continue;
				if(strpos($uri,'/media')===false && strpos($uri,'/bussbilder')===false)
					$element->setAttribute($attr_name,$url_prefix.'?page='.urlencode($uri));
				else
					$element->setAttribute($attr_name,$this->django_url.$uri);
			}
		}

		return $dom->saveXML($body, LIBXML_NOEMPTYTAG);
	}

	//Shortcode handler
	function shortcode( $atts ) {
		$a = shortcode_atts( array(
		'page'=>'',
		'namespace'=>false)
		, $atts );

		if(!empty($_GET['page']))
			$a['page']=$_GET['page'];
		elseif(!empty($a['page']))
        {
            $_GET['page']=$a['page'];
            $this->preload_page();
        }

		$html=$this->rewrite();
		return $html;
	}
}
$bussdatabasen=new bussdatabasen();