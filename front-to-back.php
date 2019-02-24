<?php

/**
 * Plugin Name: Front to Back
 * Description: Parse HTML content to create ACF Fields + Timber and Twig files.
 * Version: 1.0
 * Author: Acti
 * Author URI: https://www.acti.fr
 * Author Email: avandepitte@acti.fr
 */

if( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Front_to_Back {

    /**
     * Settings
     */
    var $settings = array();
    var $group_prefix = 'group_';
    var $fields = array();
    var $page = '';
    var $html = '';
    var $parser;
    var $errors = array();

    /**
     * HTML_To_ACF_Fields constructor.
     */
    public function __construct() {

        // Add admin menu page
        add_action( 'admin_menu', array($this, 'register_ftb_menu_page' ));
    }

    /**
     * Register a custom menu page.
     */
    public function register_ftb_menu_page() {
        add_submenu_page(
            'tools.php',
            __('Front to Back', 'ftb'),
            'Front to Back',
            'manage_options',
            'front-to-dev',
            array($this, 'ftb_menu_page_content')
        );
    }

    /**
     * Admin page content
     */
    public function ftb_menu_page_content(){ ?>
        <?php
            if(!empty($_POST['page_to_parse'])) {
                if(!file_exists($_POST['page_to_parse'])) {
                    return;
                }

                $parse = $this->run($_POST['page_to_parse']);

                if($parse) : ?>
                    <div class="notice notice-success" style="margin: 10px 0 20px 2px;">
                        <p>
                            <?php _e('Parsing done', 'ftb'); ?>
                        </p>
                    </div>
                <?php endif;

                if(!empty($this->errors)) :
                    foreach ($this->errors as $error) : ?>
                        <div class="notice notice-error" style="margin: 10px 0 20px 2px;">
                            <p>
                                <?php echo $error; ?>
                            </p>
                        </div>
                    <?php endforeach;
                endif;

            }
        ?>
        <h1><?php _e('Front to Back', 'ftb'); ?></h1>

        <div class="postbox acf-postbox">
            <div class="inside">
                <h2><?php _e('Parsing', 'ftb'); ?></h2>

                <p>
                    <?php _e('Select the html to parse','ftb');?>
                </p>

                <?php
                    $integration_folder = WP_CONTENT_DIR.'/integration';

                    if(!is_dir($integration_folder)) {
                        echo '<span style="color: red">'.__('The "integration" folder does not exists or is not at the right place. It should be at the root of the application.', 'ftb').'</span>';
                        return;
                    }else {
                        $files = array_diff(scandir($integration_folder), array('.', '..'));
                    }

                ?>

                <form action="<?php menu_page_url('html-to-acf-fields').'';?>" method="post">

                    <select name="page_to_parse">
                        <?php foreach ($files as $file) : ?>
                            <option value="<?php echo $integration_folder.'/'.$file; ?>"><?php echo $file; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button button-primary">
                        <?php _e('Start Parsing', 'ftb'); ?>
                    </button>
                </form>
            </div>

        </div>
    <?php }


    /**
     * Run
     */
    public function run($page) {

        $this->page = $page;

        // Define constant and settings
        $path = plugin_dir_path( __FILE__ );
        $this->define('FTD_PATH', $path);

        // Include parser and start parsing
        include_once($path. 'includes/Parser.php');

        $this->parser = new Parser();

        $parser_data = $this->parser->get_fields($page);

        if(empty($parser_data['fields'])) {
            $this->set_error(__('No fields in the html file', 'ftb'));
            return;
        }

        $this->fields = $parser_data['fields'];
        $this->html = $parser_data['html'];

        $timber_file = $this->create_timber_php_page($page, $this->html);

        if(!$timber_file) {
            return;
        }

        $group = $this->generate_acf_group_settings($page);

        $this->create_acf_fields($this->fields, $group);

        return true;
    }

    /**
     * Add errors
     */
    public function set_error($error) {
        $this->errors[] = $error;
    }


    /**
     * This function will safely define a constant
     */
    function define( $name, $value = true ) {

        if( !defined($name) ) {
            define( $name, $value );
        }

    }

    /**
     * Get acf parent id
     */
    function get_acf_parent_id($parent_key){
        global $wpdb;

        return $wpdb->get_var("
        SELECT ID
        FROM $wpdb->posts
        WHERE post_type='acf-field' AND post_name='$parent_key';
    ");
    }

    /**
     * Create acf fields and group
     */
    public function create_acf_fields($fields, $group) {

        // Create the field group
        $field_group = acf_update_field_group(array (
            'key' => uniqid($this->group_prefix),
            'title' => $group['title'],
            'location' => array (
                array (
                    array (
                        'param' => $group['param'],
                        'operator' => '==',
                        'value' => $group['value'],
                    ),
                ),
            ),
        ));

        // Add fields
        foreach ($fields as $field) {

            if(!isset($field['parent'])) {
                $field['parent'] = $field_group['ID'];
            }else {
                $field['parent'] = $this->get_acf_parent_id($field['parent']);
            }

            acf_update_field($field);
        }
    }

    /**
     * Get clean name of file
     */
    function get_page_basename($page) {
        return str_replace('.html', '', basename($page));
    }

    /**
     * Guess location from page
     */
    function guess_location_from_page($page) {
        $basename = $this->get_page_basename($page);

        // If page template or post type or page
        if(strpos($basename,'template-') === 0) {
            $param = 'post_template';
            $value = str_replace('template-', '', $basename);
            $basename = $value;

        }else if(strpos($basename,'single-') === 0) {
            $param = 'post_type';
            $value = str_replace('single-', '', $basename);
        }else {
            $param = 'post_type';
            $value = $basename;
        }

        return array(
            'param' => $param,
            'value' => $value,
            'page_name' => $basename
        );

    }

    /**
     * Create timber php page
     */
    function create_timber_php_page($page, $html){
        $theme_directory = get_stylesheet_directory();

        $location = $this->guess_location_from_page($page);

        if($location['param'] === 'post_template'){

            $php_page =  $theme_directory .'/page-templates/'.$location['page_name'].'.php';
        }else {
            $php_page =  $theme_directory . '/'. $location['page_name'].'.php';
        }

        if(file_exists($php_page)) {
            $this->set_error(__('Page already exists. Remove php and twig files first.', 'ftb'));
            return false;
        }

        $file = fopen($php_page, 'w');

        $txt = '<?php

$context = Timber::get_context();
$context[\'post\'] = Timber::get_post();

Timber::render(\''.$this->get_page_basename($page).'.twig\', $context);
 ';
        fwrite($file, $txt);
        fclose($file);


        // Path of twig file in templates dir
        $twig_file_path = str_replace('.php', '.twig', str_replace($theme_directory, $theme_directory.'/templates', $php_page));

        $twig_content = $this->html;

        $twig_file = fopen($twig_file_path, 'w');

        fwrite($twig_file, $twig_content);
        fclose($twig_file);

        return true;
    }


    /**
     * Generate acf group settings
     */
    function generate_acf_group_settings($page) {

        $basename = $this->get_page_basename($page);

        $location = $this->guess_location_from_page($page);

        $group = array(
            'title' => ucfirst(str_replace('-', ' ', $basename)),
            'value' => $location['value'],
            'param' => $location['param']
        );

        return $group;
    }

}

$ftb = new Front_to_Back();
