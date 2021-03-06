<?php

/*
Plugin Name: WP Query Factory
Plugin URI: 
Description: Build powerful queries with the WYSIWYG editor for using as shortcodes, widgets or in your code.
Version: 1.0
Author: Timothy Wood (@codearachnid)
Author URI: http://www.codearachnid.com	
Author Email: tim@imaginesimplicity.com
Text Domain: wp-query-factory
License: GPLv2 or later

Notes:

License:

  Copyright 2011 Imagine Simplicity (tim@imaginesimplicity.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License, version 2, as 
  published by the Free Software Foundation.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program; if not, write to the Free Software
  Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
  
*/

if ( !defined('ABSPATH') )
  die('-1');

if( ! class_exists('WP_Query_Factory') ) {
  class WP_Query_Factory {

    protected static $instance;

    const PLUGIN_NAME = 'WP Query Factory';
    const VERSION = 1.0;
    const MIN_PHP_VERSION = '5.3';
    const MIN_WP_VERSION = '3.3';
    const TRANSIENT = 'WPQF';
    const DOMAIN = 'wp_query_factory';
    const FACTORY_TYPE = 'wp-query-factory';
    const FACTORY_TEMPLATE = 'wp-query-factory-tpl';

    public $base_url;
    public $base_path;
    public $base_name;
    public $field_list;
    public $request_page_id = 0;

    private $post_type_args = array(
        'public' => true,
        'publicly_queryable' => false,
        'show_ui' => true, 
        'show_in_menu' => true,
        'query_var' => false,
        'rewrite' => false,
        'capability_type' => 'post',
        'has_archive' => false, 
        'hierarchical' => false,
        'supports' => array( 'title' )
      );
    function __construct(){
      // Setup common access properties
      $this->base_path = plugin_dir_path( __FILE__ );
      $this->base_url = plugin_dir_url( __FILE__ );
      $this->base_name = plugin_basename( __FILE__ );
      $this->setup_field_list();

      do_action(self::DOMAIN);

      add_action( 'init', array( $this, 'register_framework' ) );
      add_action( 'admin_menu', array( $this, 'admin_menu' ) );
      add_action( 'admin_bar_menu', array($this, 'admin_bar_menu' ), 100 );
      add_filter( 'post_updated_messages', array( $this, 'override_confirmation_messages') );
      // plugin dashboard
      add_action( 'admin_notices', array($this, 'admin_dashboard') );
      // add our various styles
      wp_enqueue_style(WP_Query_Factory::DOMAIN . '-custom-icons-css', $this->base_url . 'assets/css/icons.css');
    }

    public static function init() {}

    public function admin_menu() {
      // add_submenu_page( 'edit.php?post_type=' . self::FACTORY_TYPE, __('WP Query Factory Help', 'wp-query-factory'), __('Help', 'wp-query-factory'), 'manage_options', self::FACTORY_TYPE . '-help', array( $this, 'help' ));
    }
    function admin_bar_menu() {
      global $wp_admin_bar;

      if ( !is_super_admin() || !is_admin_bar_showing() )
        return;
      $wp_admin_bar->add_menu( array(
        'parent' => 'site-name',
        'id' => self::FACTORY_TYPE,
        'title' => __('Query Factory','wp-query-factory'),
        'href' => get_admin_url() . 'edit.php?post_type=' . self::FACTORY_TYPE ) );
    }

    public function admin_dashboard(){
      global $current_screen;
      if($current_screen->id == 'edit-'.self::FACTORY_TYPE)
        include $this->get_view('dashboard');
    }

    public function query( $query_id = null, $args = array() ) {
      if( is_null($query_id) )
        return null;

      if ( false === ( $query_factory = get_transient( self::TRANSIENT . '_' . $query_id ) ) ) {
        // It wasn't there, so regenerate the data and save the transient
        
        $factory_args = array(
          'name' => $query_id,
          'post_type' => self::FACTORY_TYPE,
          'posts_per_page' => 1
          );
        $query_factory = new WP_Query( $factory_args );

        if( empty($query_factory->posts))
          return null;

        $query_factory = $query_factory->posts[0];
        $query_factory->args = unserialize(base64_decode($query_factory->post_content));

        // save $query_factory as transient to speed up future requests
        set_transient( self::TRANSIENT . '_' . $query_id, $query_factory );
      }

      if( !isset($query_factory->ID))
        return null;

      $wp_query_factory->ID = $query_factory->ID;
      $wp_query_factory->args = $query_factory->args; 
      $wp_query_factory->query_type = $query_factory->post_mime_type;
      $wp_query_factory->default_template = $query_factory->to_ping;
      
      $args = wp_parse_args( $args, $wp_query_factory->args );
      switch( $wp_query_factory->query_type ) {
        case 'WP_User_Query':
          // documentation: http://codex.wordpress.org/Class_Reference/WP_User_Query
          $wp_user_query = new WP_User_Query( $args );
          $wp_query_factory->results = $wp_user_query->get_results();
          $wp_query_factory->total_found = $wp_query->found_posts;
          break;
        default:
          // documentation: http://codex.wordpress.org/Class_Reference/WP_Query
          $wp_query = new WP_Query( $args );
          $wp_query_factory->results = $wp_query->posts;
          $wp_query_factory->total_found = $wp_query->found_posts;
          break;
      }

      return $wp_query_factory;
    }

    public function the_content($request_id = null, $more_link_text = null, $stripteaser = false) {
      global $post;
      $content = get_the_content($more_link_text, $stripteaser);
      // we can't apply 'the_content' filters if the current page is the same as the result from the 
      // query because it throws WordPress into an infinite loop thus any shortcodes are then not 
      // "run" on the current result
      $content = ($post->request_page_id != get_the_ID()) ? apply_filters('the_content', $content) : $content;
      $content = str_replace(']]>', ']]&gt;', $content);
      echo $content;
    }

    public function register_framework() {
      // register wp-query-factory
      $args = wp_parse_args( array(
        'menu_position' => 79
        ), $this->post_type_args );
      $args['labels'] = $this->setup_labels(array(
        'name' => __('WordPress Query Factory', 'wp-query-factory'),
        'menu_name' => __('Query Factory', 'wp-query-factory'),
        'single' => __('Query', 'wp-query-factory'),
        'plural' => __('Queries', 'wp-query-factory')
        ));
      register_post_type( self::FACTORY_TYPE,$args);

      // register wp-query-factory-tpl
      $args = wp_parse_args( array(
        'show_in_menu' => 'edit.php?post_type=' . self::FACTORY_TYPE, 
        'supports' => array( 'title', 'editor' )
        ), $this->post_type_args );
      $args['labels'] = $this->setup_labels(array(
        'name' => __('Templates', 'wp-query-factory'),
        'single' => __('Template', 'wp-query-factory'),
        'plural' => __('Templates', 'wp-query-factory')
        ));
      register_post_type( self::FACTORY_TEMPLATE,$args);
    }    

    public function setup_field_list(){
      // build category list
      $categories = array();
      foreach (get_categories( array('hide_empty' => 0) ) as $category) {
        $categories[$category->term_id] = $category->name;
      }
      // build available template list
      $templates = array();
      foreach($this->available_templates() as $template) {
        $templates[$template->post_name] = $template->post_title;
      }

      // build user lists
      $users = array();
      foreach(get_users() as $user ) {
       $users[$user->ID] = $user->display_name;
      }

      $this->field_list = apply_filters(self::DOMAIN.'_field_list', array(
        'default_template' => array(
          'options' => $templates,
          'id' => 'post_name',
          'name' => 'template_tools[default_template]',
          'label' => __('Select default template', 'wp-query-factory'),
          'not_arg' => true,
          'key_value' => true,
          'required' => true
          ),
        'post_name' => array(
          'options' => array('WP_Query','WP_User_Query'),
          'id' => 'post_name',
          'name' => 'query_builder[post_name]',
          'label' => __('Select type of query to create', 'wp-query-factory'),
          'default' => null,
          'not_arg' => true,
          'required' => true
          ),
        'query_type' => array(
          'options' => array('WP_Query','WP_User_Query'),
          'id' => 'query_type',
          'name' => 'query_builder[query_type]',
          'label' => __('Select type of query to create', 'wp-query-factory'),
          'default' => 'WP_Query',
          'not_arg' => true,
          'required' => true
          ),
        'post_type' => array(
          'options' => array_filter(get_post_types(), array( $this, 'exclude_factory_types' ) ),
          'name' => 'query_builder[post_type][]',
          'label' => __('Select types', 'wp-query-factory'),
          'default' => 'post',
          'single_is_ok' => true,
          'required' => true
          ),
        'post_status' => array(
          'options' => array('publish','pending','draft','auto-draft','future','private','inherit','trash','any'),
          'name' => 'query_builder[post_status][]',
          'default' => 'publish',
          'label' => __('Select status', 'wp-query-factory'),
          'single_is_ok' => true,
          'required' => true
          ),
        'author' => array(
          'options' => $users,
          'name' => 'query_builder[author][]',
          'label' => __('Select users to include', 'wp-query-factory'),
          'key_value' => true,
          'deselect' => true,
          'single_is_ok' => false
          ),
        'author_exclude' => array(
          'options' => $this->negative_keys($users),
          'name' => 'query_builder[author_exclude][]',
          'label' => __('Select users to exclude', 'wp-query-factory'),
          'key_value' => true,
          'deselect' => true,
          'single_is_ok' => false
          ),
        'cat' => array(
          'options' => $categories,
          'name' => 'query_builder[cat][]',
          'label' => __('Select category', 'wp-query-factory'),
          'key_value' => true,
          'deselect' => true,
          'single_is_ok' => false
          ),
        'category_type' => array(
          'options' => array('category__and' => 'And', 'category__in'=>'In','category__not_in'=>'Not In'),
          'name' => 'query_builder[category_type]',
          'label' => __('Multiple category handling', 'wp-query-factory'),
          'key_value' => true,
          'deselect' => true
          ),
        'offset' => array(
          'name' => 'query_builder[offset]',
          'default' => null
          ),
        'ignore_sticky_posts' => array(
          'name' => 'query_builder[ignore_sticky_posts]',
          'default' => 0
          ),
        'order' => array(
          'options' => array('DESC'=>__('Descending','wp-query-factory'),'ASC'=>__('Ascending','wp-query-factory')),
          'name' => 'query_builder[order]',
          'label' => __('Sort direction', 'wp-query-factory'),
          'key_value' => true,
          'deselect' => true,
          'single_is_ok' => false
          ),
        'orderby' => array(
          'options' => array('date','ID','author','title','modified','parent','rand','comment_count','menu_order','meta_value','meta_value_num','none'),
          'name' => 'query_builder[orderby][]',
          'label' => __('Order by field', 'wp-query-factory'),
          'deselect' => true,
          'single_is_ok' => false
          ),
        'year' => array(
          'name' => 'query_builder[year]',
          'default' => null
          ),
        'monthnum' => array(
          'options' => $this->setup_timelist(1,13,'month'),
          'name' => 'query_builder[monthnum]',
          'label' => __('Select month', 'wp-query-factory'),
          'key_value' => true,
          'deselect' => true,
          'css' => array('month')
          ),
        'day' => array(
          'options' => $this->setup_timelist(1,31,'ordinal'),
          'name' => 'query_builder[day]',
          'label' => __('Select day', 'wp-query-factory'),
          'key_value' => true,
          'deselect' => true,
          'css' => array('day')
          ),
        'hour' => array(
          'options' => $this->setup_timelist(1,25),
          'name' => 'query_builder[hour]',
          'label' => __('Select hour', 'wp-query-factory'),
          'deselect' => true,
          'css' => array('time')
          ),
        'minute' => array(
          'options' => $this->setup_timelist(1,61),
          'name' => 'query_builder[minute]',
          'label' => __('Select minute', 'wp-query-factory'),
          'deselect' => true,
          'css' => array('time')
          ),
        'second' => array(
          'options' => $this->setup_timelist(1,61),
          'name' => 'query_builder[second]',
          'label' => __('Select second', 'wp-query-factory'),
          'deselect' => true,
          'css' => array('time')
          ),
        'w' => array(
          'options' => $this->setup_timelist(1,53,'ordinal'),
          'name' => 'query_builder[w]',
          'label' => __('Select week of the year', 'wp-query-factory'),
          'key_value' => true,
          'deselect' => true,
          'css' => array('week')
          ),
        's' => array( // search keyword
          'name' => 'query_builder[s]',
          'default' => null
          )
        ));
    }

    public function negative_keys($positive){
      $negative = array();
      foreach($positive as $key => $row)
        $negative['-'.$key] = $row;
      return $negative;
    }

    public function setup_timelist( $start, $end, $format = null){
      $list = array();
      for($n=$start;$n<$end;$n++) {
        switch($format) {
          case 'month':
            $list[$n] = date( 'F', mktime(0, 0, 0, $n) );
            break;
          case 'ordinal':
            $list[$n] = $n . date('S',mktime(1,1,1,1,( (($n>=10)+($n>=20)+($n==0))*10 + $n%10) ));
            break;
          default:
            $list[$n] = $n;
            break;
        }
      }
      return $list;
    }

    public function available_templates( $args = array()){
      $defaults = array(
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'post_type' => self::FACTORY_TEMPLATE
        );
      $args = wp_parse_args( $args, $defaults );
      $templates = new WP_Query($args);
      return apply_filters(self::DOMAIN.'-available_templates', $templates->posts);
    }

    public function available_queries( $args = array()){
      $defaults = array(
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'post_type' => self::FACTORY_TYPE
        );
      $args = wp_parse_args( $args, $defaults );
      $queries = new WP_Query($args);
      return apply_filters(self::DOMAIN.'-available_queries', $queries->posts);
    }

    public function exclude_factory_types( $post_type ){
      return ! in_array($post_type, array(self::FACTORY_TYPE, self::FACTORY_TEMPLATE));
    }

    public function check_factory_types( $post_type ){
      return in_array($post_type, array(self::FACTORY_TYPE, self::FACTORY_TEMPLATE));
    }

    /**
     * build the I18n labels for registered post types
     * @param  $args
     * @return array [labels]
     */
    private function setup_labels( $args = array() ) {
      $defaults = array(
        'name' => 'Post Type Name',
        'menu_name' => 'Post Type',
        'single' => 'Post Type',
        'plural' => 'Post Types');
      $args = wp_parse_args( $args, $defaults );
      return array(
        'name' => $args['name'],
        'singular_name' => sprintf( __('%s', 'wp-query-factory'), $args['single'] ),
        'add_new' => __('Add New', 'wp-query-factory'),
        'add_new_item' => sprintf( __('Add New %s', 'wp-query-factory'), $args['single'] ),
        'edit_item' => sprintf( __('Edit %s', 'wp-query-factory'), $args['single'] ),
        'new_item' => sprintf( __('New %s', 'wp-query-factory'), $args['single'] ),
        'all_items' => sprintf( __('%s', 'wp-query-factory'), $args['plural'] ),
        'view_item' => sprintf( __('View %s', 'wp-query-factory'), $args['single'] ),
        'search_items' => sprintf( __('Search %s', 'wp-query-factory'), $args['plural'] ),
        'not_found' =>  sprintf( __('No %s found', 'wp-query-factory'), strtolower($args['plural']) ),
        'not_found_in_trash' => sprintf( __('No %s found in Trash', 'wp-query-factory'), strtolower($args['plural']) ), 
        'parent_item_colon' => '',
        'menu_name' => $args['menu_name']
      );
    }

    public function override_confirmation_messages( $messages ) {
      global $post;
      $messages[ self::FACTORY_TYPE ] = array(
        0 => '', // Unused. Messages start at index 1.
        1 => __('Query updated.', 'wp-query-factory'),
        2 => __('Custom field updated.', 'wp-query-factory'),
        3 => __('Custom field deleted.', 'wp-query-factory'),
        4 => __('Query updated.', 'wp-query-factory'),
        /* translators: %s: date and time of the revision */
        5 => isset($_GET['revision']) ? sprintf( __('Query restored to revision from %s', 'wp-query-factory'), wp_post_revision_title( (int) $_GET['revision'], false ) ) : false,
        6 => __('Query published.', 'wp-query-factory'),
        7 => __('Query saved.', 'wp-query-factory'),
        8 => __('Query submitted.', 'wp-query-factory'),
        9 => sprintf( __('Query scheduled for: <strong>%s</strong>.', 'wp-query-factory'),
          // translators: Publish box date format, see http://php.net/date
          date_i18n( __( 'M j, Y @ G:i' ), strtotime( $post->post_date ) ) ),
        10 => __('Query draft updated.', 'wp-query-factory'),
        );
      $messages[ self::FACTORY_TEMPLATE ] = array(
        0 => '', // Unused. Messages start at index 1.
        1 => __('Template updated.', 'wp-query-factory'),
        2 => __('Custom field updated.', 'wp-query-factory'),
        3 => __('Custom field deleted.', 'wp-query-factory'),
        4 => __('Template updated.', 'wp-query-factory'),
        /* translators: %s: date and time of the revision */
        5 => isset($_GET['revision']) ? sprintf( __('Template restored to revision from %s', 'wp-query-factory'), wp_post_revision_title( (int) $_GET['revision'], false ) ) : false,
        6 => __('Template published.', 'wp-query-factory'),
        7 => __('Template saved.', 'wp-query-factory'),
        8 => __('Template submitted.', 'wp-query-factory'),
        9 => sprintf( __('Template scheduled for: <strong>%s</strong>.', 'wp-query-factory'),
          // translators: Publish box date format, see http://php.net/date
          date_i18n( __( 'M j, Y @ G:i' ), strtotime( $post->post_date ) ) ),
        10 => __('Template draft updated.', 'wp-query-factory'),
        );

      return $messages;
    }

    /**
     * Loads theme files in appropriate hierarchy: 
     * 1) child theme,
     * 2) parent template, 
     * 3) plugin resources. will look in the wp-pillow-author/
     * directory in a theme and the views/ directory in the plugin
     *
     * You may also override the @return var directly by using:
     * add_filter('wp-query-factory_{template_name}')
     *
     * @param string $template template file to search for
     * @param string $class pass through class filters
     * @return template path
     **/
    public function get_view( $template, $folder = 'views' ) {
      // whether or not .php was added
      $template = rtrim($template, '.php');

      if ( $theme_file = locate_template( array(self::FACTORY_TYPE . '/' . $template . '.php') ) ) {
        $file = $theme_file;
      } else {
        $file = $this->base_path . '/' . $folder . '/' . $template . '.php';
      }

      return apply_filters( self::DOMAIN . '_' . $template, $file);
    }

    public function get_template( $template, $create = true ){
      if(is_null($template))
        return false;
      $t = self::get_view( $template, 'templates');
      if( !$create )
        return $t;
      if(!file_exists($t)) {
        // look to generate template file if it exists
        $args = array(
          'name' => $template,
          'post_type' => self::FACTORY_TEMPLATE,
          'posts_per_page' => 1
          );
        $template_query = new WP_Query($args);
        if(empty($template_query->posts))
          return false;
        $t = self::get_view( $template, 'templates');
        file_put_contents( $t, WP_Query_Factory_Editor::generate_template( $template_query->posts[0]->post_content ) );
      }
      return $t;
    }
    /**
     * Check that the minimum PHP and WP versions are met
     *
     * @static
     * @param string $php_version
     * @param string $wp_version
     * @return bool Whether the test passed
     */
    public static function prerequisites( $php_version, $wp_version ) {
      $pass = TRUE;
      $pass = $pass && version_compare( $php_version, self::MIN_PHP_VERSION, '>=');
      $pass = $pass && version_compare( $wp_version, self::MIN_WP_VERSION, '>=');
      return $pass;
    }

    public function fail_notices( $php_version = self::MIN_PHP_VERSION, $wp_version = self::MIN_WP_VERSION ) {
      printf( '<div class="error"><p>%s</p></div>', sprintf( self::__( '%1$s requires WordPress %2$s or higher and PHP %3$s or higher.' ), self::PLUGIN_NAME, $wp_version, $php_version ) );
    }

    /* Static Singleton Factory Method */
    public static function instance() {
      if ( !isset( self::$instance ) ) {
        $className = __CLASS__;
        self::$instance = new $className;
      }
      return self::$instance;
    }
  }

  /**
   * Instantiate class and set up WordPress actions.
   *
   * @return void
   */
  function Load_WP_Query_Factory() {
    if ( apply_filters( 'wp_query_factory_pre_check', class_exists( 'WP_Query_Factory' ) && WP_Query_Factory::prerequisites(phpversion(), get_bloginfo('version')) ) ) {
      // Load all supporting files and hook into WordPress
      include 'lib/template_tags.class.php';
      include 'lib/editor.class.php';
      include 'lib/shortcode.class.php';
      add_action('init', array('WP_Query_Factory', 'instance'), -100, 0);      
      add_action(WP_Query_Factory::DOMAIN, array('WP_Query_Factory_Template_Tags', 'instance'), 0, 0);
      add_action(WP_Query_Factory::DOMAIN, array('WP_Query_Factory_Editor', 'instance'), 0, 0);
      add_action(WP_Query_Factory::DOMAIN, array('WP_Query_Factory_Shortcode', 'instance'), 2, 0);

    } else {
      // let the user know prerequisites weren't met
      add_action('admin_head', array('WP_Query_Factory', 'fail_notices'), 0, 0);
    }
  }
  add_action( 'plugins_loaded', 'Load_WP_Query_Factory', 1); // high priority so that it's not too late for addon overrides
}