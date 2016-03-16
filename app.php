<?php
/**
 * @package Restrict User Access
 * @copyright Joachim Jensen <jv@intox.dk>
 * @license GPLv3
 */

if (!defined('ABSPATH')) {
	header('Status: 403 Forbidden');
	header('HTTP/1.1 403 Forbidden');
	exit;
}

final class RUA_App {

	/**
	 * Plugin version
	 */
	const PLUGIN_VERSION       = '0.11.1';

	/**
	 * Post Type for restriction
	 */
	const TYPE_RESTRICT        = 'restriction';

	/**
	 * Language domain
	 */
	const DOMAIN               = 'restrict-user-access';

	/**
	 * Capability to manage restrictions
	 */
	const CAPABILITY           = 'edit_users';

	/**
	 * Access Levels
	 * 
	 * @var array
	 */
	private $levels            = array();

	/**
	 * Instance of class
	 * 
	 * @var RUA_App
	 */
	private static $_instance;

	/**
	 * Level manager
	 * @var RUA_Level_Manager
	 */
	public $level_manager;

	/**
	 * Constructor
	 */
	public function __construct() {

		$this->level_manager = new RUA_Level_Manager();

		if(is_admin()) {

			new RUA_Settings_Page();
			new RUA_Nav_Menu();

			add_action('admin_enqueue_scripts',
				array($this,'load_admin_scripts'));

			add_action( 'show_user_profile',
				array($this,'add_field_access_level'));
			add_action( 'edit_user_profile',
				array($this,'add_field_access_level'));
			add_action( 'personal_options_update',
				array($this,'save_user_profile'));
			add_action( 'edit_user_profile_update',
				array($this,'save_user_profile'));
			add_action('delete_post',
				array($this,'sync_level_deletion'));

			add_filter( 'manage_users_columns',
				array($this,'add_user_column_headers'));
			add_filter( 'manage_users_custom_column',
				array($this,'add_user_columns'), 10, 3 );

		}

		add_filter('show_admin_bar',
			array($this,"show_admin_toolbar"),99);

		add_shortcode( 'login-form',
			array($this,'shortcode_login_form'));

		add_action('init',
			array($this,'load_textdomain'));
	}

	/**
	 * Instantiates and returns class singleton
	 *
	 * @since  0.1
	 * @return RUA_App 
	 */
	public static function instance() {
		if(!self::$_instance) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Maybe hide admin toolbar for Users
	 *
	 * @since  0.10
	 * @return boolean
	 */
	public function show_admin_toolbar($show) {
		if(!current_user_can("administrator") && is_user_logged_in()) {
			$show = !get_option("rua-toolbar-hide",false);
		}
		return $show;
	}
	
	/**
	 * Load plugin textdomain for languages
	 *
	 * @since  0.1
	 * @return void 
	 */
	public function load_textdomain() {
		load_plugin_textdomain(self::DOMAIN, false, dirname(plugin_basename(__FILE__)).'/lang/');
	}

	/**
	 * Get login form in shotcode
	 * 
	 * @version 0.9
	 * @param   array     $atts
	 * @param   string    $content
	 * @return  string
	 */
	public function shortcode_login_form( $atts, $content = null ) {
		$a = shortcode_atts( array(
			'remember'       => true,
			'redirect'       => "",
			'form_id'        => 'loginform',
			'id_username'    => 'user_login',
			'id_password'    => 'user_pass',
			'id_remember'    => 'rememberme',
			'id_submit'      => 'wp-submit',
			'label_username' => __( 'Username' ),
			'label_password' => __( 'Password' ),
			'label_remember' => __( 'Remember Me' ),
			'label_log_in'   => __( 'Log In' ),
			'value_username' => '',
			'value_remember' => false
		), $atts );
		$a["echo"] = false;

		if(!$a["redirect"]) {
			if(isset($_GET["redirect_to"])) {
				$a["redirect"] = urldecode($_GET["redirect_to"]);
			} else {
				$a["redirect"] = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
			}
		}

		return wp_login_form( $a );
	}

	/**
	 * Add Access Level to user profile
	 *
	 * @since 0.3
	 * @param WP_User  $user
	 */
	public function add_field_access_level( $user ) {
		if(current_user_can(self::CAPABILITY)) {
			$user_levels = $this->level_manager->get_user_levels($user->ID,false,false,true);
?>
			<h3><?php _e("Access",self::DOMAIN); ?></h3>
			<table class="form-table">
				<tr>
					<th><label for="_ca_level"><?php _e("Access Levels",self::DOMAIN); ?></label></th>
					<td>
					<input type="text" class="regular-text js-rua-levels" name="_ca_level" value="<?php echo esc_html( implode(",", $user_levels) ); ?>" />
					<p class="description"><?php _e("Access Levels synchronized with User Roles will not be listed here."); ?></p>
					</td>
				</tr>
			</table>
<?php
		}
	}

	/**
	 * Save additional data for
	 * user profile
	 *
	 * @since  0.3
	 * @param  int  $user_id
	 * @return void
	 */
	public function save_user_profile( $user_id ) {
		if ( !current_user_can(self::CAPABILITY) )
			return false;

		$new_levels = isset($_POST[WPCACore::PREFIX.'level']) ? explode(",", $_POST[WPCACore::PREFIX.'level']) : array();
		$user_levels = array_flip($this->level_manager->get_user_levels($user_id,false,false,true));

		foreach ($new_levels as $level) {
			if(isset($user_levels[$level])) {
				unset($user_levels[$level]);
			} else {
				$this->level_manager->add_user_level($user_id,$level);
			}
		}
		foreach ($user_levels as $level => $value) {
			$this->level_manager->remove_user_level($user_id,$level);
		}
	}

	/**
	 * Add column headers on
	 * User overview
	 *
	 * @since 0.3
	 * @param array  $column
	 */
	public function add_user_column_headers( $columns ) {
		$new_columns = array();
		foreach($columns as $key => $title) {
			$new_columns[$key] = $title;
			if($key == "role") {
				$new_columns["level"] = __('Access Levels',self::DOMAIN);
			}
		}
		return $new_columns;
	}

	/**
	 * Add columns on user overview
	 *
	 * @since 0.3
	 * @param string  $output
	 * @param string  $column_name
	 * @param int     $user_id
	 */
	public function add_user_columns( $output, $column_name, $user_id ) {
		switch ($column_name) {
			case 'level' :
				$levels = $this->get_levels();
				$level_links = array();
				foreach ($this->level_manager->get_user_levels($user_id,false,true,true) as $user_level) {
					$user_level = isset($levels[$user_level]) ? $levels[$user_level] : null;
					if($user_level) {
						$level_links[] = '<a href="'.admin_url( 'post.php?post='.$user_level->ID.'&action=edit#top#rua-members').'">'.$user_level->post_title.'</a>';
					}
				}
				$output = implode(", ", $level_links);
				break;
			default:
		}
		return $output;
	}

	/**
	 * Get all levels not synced with roles
	 *
	 * @since  0.3
	 * @return array
	 */
	public function get_levels() {
		if(!$this->levels) {
			$levels = get_posts(array(
				'numberposts' => -1,
				'post_type'   => self::TYPE_RESTRICT,
				'post_status' => array('publish','private','future')
			));
			foreach ($levels as $level) {
				$this->levels[$level->ID] = $level;
			}
		}
		return $this->levels;
	}

	/**
	 * Delete foreign metadata belonging to level
	 *
	 * @since  0.11.1
	 * @param  int    $post_id
	 * @return void
	 */
	public function sync_level_deletion($post_id) {

		if (!current_user_can(self::CAPABILITY))
			return;

		global $wpdb;

		//Delete user levels
		$wpdb->query($wpdb->prepare( 
			"DELETE FROM $wpdb->usermeta
			 WHERE
			 (meta_key = %s AND meta_value = %d)
			 OR
			 meta_key = %s",
			WPCACore::PREFIX."level",
			$post_id,
			WPCACore::PREFIX."level_".$post_id
		));

		//Delete nav menu item levels
		$wpdb->query($wpdb->prepare( 
			"DELETE FROM $wpdb->postmeta
			 WHERE
			 meta_key = %s AND meta_value = %d",
			"_menu_item_level",
			$post_id
		));

	}

	/**
	 * Load scripts and styles for administration
	 * 
	 * @since  0.1
	 * @param  string  $hook
	 * @return void
	 */
	public function load_admin_scripts($hook) {

		$current_screen = get_current_screen();

		if($current_screen->post_type == self::TYPE_RESTRICT){

			wp_register_script('rua/admin/edit', plugins_url('/js/edit.js', __FILE__), array('select2','jquery'), self::PLUGIN_VERSION);

			wp_register_style('rua/style', plugins_url('/css/style.css', __FILE__), array(), self::PLUGIN_VERSION);

			//Sidebar editor
			if ($current_screen->base == 'post') {
				wp_enqueue_script('rua/admin/edit');
				wp_enqueue_style('rua/style');
			//Sidebar overview
			} else if ($hook == 'edit.php') {
				wp_enqueue_style('rua/style');
			}
		} else if($current_screen->id == "nav-menus" || $current_screen->id == "user-edit"  || $current_screen->id == "profile") {

			//todo: enqueue automatically in wpcacore
			if(wp_script_is("select2","registered")) {
				wp_deregister_script("select2");
			}
			wp_register_script(
				'select2',
				plugins_url('/lib/wp-content-aware-engine/assets/js/select2.min.js', __FILE__),
				array('jquery'),
				'3.5.4',
				false
			);
			wp_enqueue_style(
				WPCACore::PREFIX.'condition-groups',
				plugins_url('/lib/wp-content-aware-engine/assets/css/condition_groups.css', __FILE__),
				array(),
				WPCACore::VERSION
			);

			$levels = array();
			foreach($this->get_levels() as $level) {
				$synced_role = get_post_meta($level->ID,WPCACore::PREFIX."role",true);
				if($current_screen->id != "nav-menus" && $synced_role != "-1") {
					continue;
				}
				$levels[] = array(
					"id" => $level->ID,
					"text" => $level->post_title
				);
			}
			wp_enqueue_script('rua/admin/suggest-levels', plugins_url('/js/suggest-levels.js', __FILE__), array('select2','jquery'), self::PLUGIN_VERSION);
			wp_localize_script('rua/admin/suggest-levels', 'RUA', array(
				"search" => __("Search for Levels",self::DOMAIN),
				'levels' => $levels
			));
		}
	}

}

//eol