<?php
require_once("cron-helper.php");
require_once("display-instances.php");
/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @package           List_instances
 *
 * @wordpress-plugin
 * Plugin Name:       rpi List Moodle Instanzen
 * Plugin URI:        https://github.com/rpi-virtuell/rpi-wall/
 * Description:       Wordpress Plugin used to display Moodle instances of the Server
 * Version:           1.1.2
 * Author:            Daniel Reintanz
 * Author URI:        https://github.com/FreelancerAMP
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       rpi-wp-list-moodle-instances
 * Domain Path:       /languages
 */

/**
 * List and Mange Moodle  from Wordpress on the same Server
 */
class ListInstances
{
    public function __construct()
    {

        if (!defined('MOODLE_MAIN_HOST')) {
            $u = parse_url(home_url());
            $host = str_replace('manage', '', $u['host']);
            define('MOODLE_MAIN_HOST', $host);
        }

        add_action('wp', [$this, 'delete_post_on_attribute_pass']);
        add_action('wp_insert_post', [$this, 'update_ini_on_post_insert'], 10, 3);
        add_filter('acf/validate_value/name=subdomain', [$this, 'validate_subdomain'], 10, 4);
        add_action('admin_init', [$this, 'sync_instances_with_ini']);

        //activate only for Testing purposes
        //add_action( 'init', [ $this, 'cron_update_instance_courses' ] );
        //add_action( 'init', [ $this, 'cron_create_new_instance' ] );


        /*
         * Rewrite permalinks of courses -> Direct link to Moodle courses
         */
        add_filter('post_type_link', function ($post_link, WP_Post $post) {
            if ('moodle_course' == $post->post_type) {
                return get_post_meta($post->ID, 'course-url', true);
            }
            return $post_link;
        }, 10, 2);


        //Stylesheet
        wp_enqueue_style('rpi-wall-style', plugin_dir_url(__FILE__) . 'css/list-instances.css');
    }

    /**
     * sync  multi-moodle-instances/instances.ini with existing instances posts
     * @return void
     */
    static function sync_instances_with_ini()
    {

        $assoc_arr = parse_ini_file(dirname(get_home_path()) . '/multi-moodle-instances/instances.ini', true);
        $assoc_arr['subdomains']['sub'] = array();

        $instances = get_posts([
            'numberposts' => -1,
            'post_status' => ['publish', 'draft'],  //draft: instance while creating
            'post_type' => 'instance'
        ]);
        foreach ($instances as $instance) {
            if (is_a($instance, 'WP_Post')) {
                $assoc_arr['subdomains']['sub'][$instance->post_name] = $instance->post_title;
            }
        }

        ListInstances::write_ini_file($assoc_arr, dirname(get_home_path()) . '/multi-moodle-instances/instances.ini', true);

    }

    /**
     * @param array $assoc_arr
     * @param string $path
     * @param boolean $has_sections
     *
     * @return false|int
     *
     * written by Harikrishnan
     * https://stackoverflow.com/questions/1268378/create-ini-file-write-values-in-php
     */
    static function write_ini_file(array $assoc_arr, string $path, bool $has_sections = false)
    {
        $content = "";
        if ($has_sections) {
            foreach ($assoc_arr as $key => $elem) {
                $content .= "[" . $key . "]\n";
                foreach ($elem as $key2 => $elem2) {
                    if (is_array($elem2)) {
                        foreach ($elem2 as $key3 => $elem3) {
                            $content .= $key2 . "[$key3] = \"" . $elem3 . "\"\n";
                        }
                    } else if ($elem2 == "") {
                        $content .= $key2 . " = \n";
                    } else {
                        $content .= $key2 . " = \"" . $elem2 . "\"\n";
                    }
                }
            }
        } else {
            foreach ($assoc_arr as $key => $elem) {
                if (is_array($elem)) {
                    for ($i = 0; $i < count($elem); $i++) {
                        $content .= $key . "[] = \"" . $elem[$i] . "\"\n";
                    }
                } else if ($elem == "") {
                    $content .= $key . " = \n";
                } else {
                    $content .= $key . " = \"" . $elem . "\"\n";
                }
            }
        }

        if (!$handle = fopen($path, 'w')) {
            return false;
        }

        $success = fwrite($handle, $content);
        fclose($handle);

        return $success;
    }

    /**
     * @param object $person
     * @param WP_Post $instance
     *
     * @return bool
     */
    static function create_user(object $person, WP_Post $instance)
    {

        $prefix = ListInstances::get_moodle_db_prefix($instance->post_name);


        $command = 'cd ' . dirname(get_home_path()) . '/multi-moodle-instances/ &&' .
            ' export MULTI_HOST_SUBDOMAIN="' . $instance->post_name . '" &&  php7.4 create_new_user.php' .
            ' --username="' . $person->username . '" --email="' . $person->email . '"  --password="' . $person->password . '" ' .
            '--firstname="' . $person->firstname . '" --lastname="' . $person->lastname . '"';

        if ($person->is_manager) {
            $command .= ' --is_manager=yes';
        }

        ob_start();
        system($command);
        $output = ob_get_clean();
        ListInstances::log($command . "\n" . $output, "_$prefix");

        if ('success' == trim($output)) {
            ListInstances::mail_to_manager($person, $instance);
            return true;
        }
        return false;

    }

    /**
     * convert $subdomain to database secure string
     * @example: the subdomain "my-school2 needs database prefix "my_school"
     * @param $subdomain
     *
     * @return string
     */
    static function get_moodle_db_prefix($subdomain)
    {
        $prefix = str_replace('-', '_', $subdomain);
        $prefix = preg_replace('/[^a-z0-9_]/', '', $prefix);
        return trim($prefix, "\t\n\r\0\x0B\-");
    }

    static function log($output = "", $postfix = '')
    {
        $logfile = dirname(get_home_path()) . '/manage' . $postfix . '.log';
        $date = date('Y/m/d H:i');
        $ip = $_SERVER['REMOTE_ADDR'];
        $user_id = get_current_user_id();
        $content = "[$date] $ip | $user_id | $output \n----\n\n";
        file_put_contents($logfile, $content, FILE_APPEND);
    }

    /**
     * @param WP_Post $instance
     * @param stdClass $person
     *
     * @return boolean
     */
    static function mail_to_manager(object $person, WP_Post $instance)
    {

        $subject = "[{$instance->post_name}" . MOODLE_MAIN_HOST . "] GPEN Moodle";

        $body = "Du hast jetzt Manager Rechte in deiner Moodle Instanz.<br>" .
            "Login:  https://{$instance->post_name} " . MOODLE_MAIN_HOST . "<br>" .
            "Benutzername: $person->username <br>Passwort: $person->password <br>" .
            "Nach dem ersten Gebrauch unbedingt ändern!";


        if ($person->email) {
            ListInstances::mail($person->email, $subject, $body);
        }

        return true;
    }

    /**
     * @param string $mail_to
     * @param string $subject
     * @param string $message
     *
     * @return void
     */
    static function mail(string $mail_to, string $subject, string $message)
    {

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: GPEN Dialogue <technik@rpi-virtuell.de>',
            'Bcc: joachim.happel@gmail.com'
        );

        wp_mail($mail_to, $subject, $message, $headers);
    }

    /**
     * @param stdClass $course
     * $course->id
     * $course->slug
     * $course->fullname
     * $course->url
     * $course->summary
     * $course->startdate
     * $course->enddate
     * $course->category
     * $course->instance
     * $course->course_img_url
     *
     * @return void
     */
    static function update_course(stdClass $course)
    {

        $moodle_course = get_page_by_path($course->slug, OBJECT, 'moodle_course');
        if (!$moodle_course) {
            $moodle_course = array(
                'post_name' => $course->slug,
                'post_title' => $course->fullname,
                'post_content' => $course->summary,
                'post_status' => 'publish',
                'post_type' => 'moodle_course'
            );
            $post_id = wp_insert_post($moodle_course);
            update_post_meta($post_id, 'course-category', $course->category);
            update_post_meta($post_id, 'course-id', $course->id);
        } else {
            $post_id = $moodle_course->ID;
            $moodle_course->post_title = $course->fullname;
            $moodle_course->post_content = $course->summary;
        }

        if (is_numeric($post_id)) {
            update_post_meta($post_id, 'course-url', $course->url);
            update_post_meta($post_id, 'course-startdate', $course->startdate);
            update_post_meta($post_id, 'course-enddate', $course->enddate);

            if ($course->course_img_url && !get_post_meta($post_id, 'course_img_url', true)) {
                update_post_meta($post_id, 'course_img_url', $course->course_img_url);
                ListInstances::Generate_Featured_Image($course->course_img_url, $post_id);
            }

            $term = get_term_by('slug', $course->instance, 'mdl-instance');
            if ($term) {
                $term_id = $term->term_id;
            } else {
                $term_id = wp_insert_term($course->instance, 'mdl-instance');
            }

            wp_set_object_terms($post_id, [$term_id], 's');

        }
    }

    /**
     * @param $image_url
     * @param $post_id
     *
     * @return void
     * @author Rob Vermeer
     */
    static function Generate_Featured_Image($image_url, $post_id)
    {
        $upload_dir = wp_upload_dir();
        $image_data = file_get_contents($image_url);
        $filename = basename($image_url);
        if (wp_mkdir_p($upload_dir['path'])) {
            $file = $upload_dir['path'] . '/' . $filename;
        } else {
            $file = $upload_dir['basedir'] . '/' . $filename;
        }
        file_put_contents($file, $image_data);

        $wp_filetype = wp_check_filetype($filename, null);
        $attachment = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title' => sanitize_file_name($filename),
            'post_content' => '',
            'post_status' => 'inherit'
        );
        $attach_id = wp_insert_attachment($attachment, $file, $post_id);
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $file);
        wp_update_attachment_metadata($attach_id, $attach_data);
        set_post_thumbnail($post_id, $attach_id);
    }

    public function validate_subdomain($valid, $value, $field, $input)
    {
        if (!preg_match("/^[a-z][a-z0-9-]*[a-z0-9]$/", $value)) {
            $valid = "Ungültige Subdomain";
        }
        return $valid;
    }

    /**
     * @param $post_id
     * @param WP_Post $post
     * @param $update
     *
     * @return void
     */
    public function update_ini_on_post_insert($post_id, WP_Post $post, $update)
    {
        if ($post->post_type === 'instance' && !$update) {
            wp_redirect(home_url() . '/instance');
        }
    }

    /**
     * action hook wp
     * @return void
     */
    public function delete_post_on_attribute_pass()
    {
        if ($_GET['delete'] === 'confirm') {

            if (is_singular('instance') && is_user_logged_in() && current_user_can('manage_options')) {

                $post = get_post();
                if (is_a($post, 'WP_Post')) {
                    if ($this->update_ini_on_post_delete($post) === true) {
                        wp_delete_post($post->ID);
                        wp_redirect(home_url() . '/instance');
                        die();
                    } else {
                        echo('Die Instanz konnte nicht gelöscht werden!');
                        die();
                    }

                }

            }
        }
    }

    /**
     * @param WP_Post $post
     *
     * @return bool;
     */
    protected function update_ini_on_post_delete(WP_Post $post)
    {

        $prefix = ListInstances::get_moodle_db_prefix($post->post_name);

        $ini_content = parse_ini_file(dirname(get_home_path()) . '/multi-moodle-instances/instances.ini', true);
        $command = 'cd ' . dirname(get_home_path()) . '/multi-moodle-instances/ && bash delete.sh "' . $prefix . '" "y" ';

        ob_start();
        system($command);
        $output = ob_get_clean();
        ListInstances::log($command . "\n" . $output, '_' . $prefix);

        if ("true" == trim($output)) {
            unset($ini_content['subdomains']['sub'][$post->post_name]);
            ListInstances::write_ini_file($ini_content, dirname(get_home_path()) . '/multi-moodle-instances/instances.ini', true);
            ListInstances::log("write_ini_file", '_' . $prefix);
            return true;
        }


        return false;
    }

}

new ListInstances();
new DisplayInstances();
new CronHelper();
