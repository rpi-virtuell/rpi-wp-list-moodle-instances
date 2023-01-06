<?php

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

class ListInstances
{
    public function __construct()
    {
        add_action('init', [$this, 'delete_post_on_attribute_pass']);
        add_action('wp_insert_post', [$this, 'update_ini_on_post_insert'], 10, 3);
        add_action('delete_post', [$this, 'update_ini_on_post_delete'], 10, 2);
        add_action('blocksy:content:before', [$this, 'add_delete_button']);
    }

    public function sync_instances_with_ini()
    {

        $assoc_arr = parse_ini_file(dirname(get_home_path()) . '/multi-moodle-instances/instances.ini', true);
        $assoc_arr['subdomains']['sub'] = array();

        $instances = get_posts([
            'numberposts' => -1,
            'post_status' => 'any',
            'post_type' => 'instance'
        ]);
        foreach ($instances as $instance) {
            if (is_a($instance, 'WP_Post')) {

                $assoc_arr['subdomains']['sub'][$instance->post_name] = $instance->post_title;
                if (!file_exists(dirname(get_home_path()) . '/moodle-data/' . $instance->post_name . '/')) {

                    $command = 'cd ' . dirname(get_home_path()) . '/multi-moodle-instances/ && bash setup.sh "' . $instance->post_name . '" "' . $instance->post_title . '"';
                    shell_exec($command);

                    $username = get_post_meta($instance->ID, 'username', true);
                    $password = get_post_meta($instance->ID, 'password', true);
                    $e_mail = get_post_meta($instance->ID, 'e-mail', true);
                    $firstname = get_post_meta($instance->ID, 'firstname', true);
                    $lastname = get_post_meta($instance->ID, 'lastname', true);

                    $user_command = 'cd ' . dirname(get_home_path()) . '/multi-moodle-instances/ && export MULTI_HOST_SUBDOMAIN=' . $instance->post_name . ' &&  php7.4 create-user.php --username="' . $username . '" --email="' . $e_mail . '" --firstname="' . $firstname . '" --lastname="' . $lastname . '"  --password="' . $password . '"';
                    shell_exec($user_command);
                }
            }
        }

        $this->write_ini_file($assoc_arr, dirname(get_home_path()) . '/multi-moodle-instances/instances.ini', true);


    }

    /**
     * @param $post_id
     * @param WP_Post $post
     * @param $update
     * @return void
     */
    public function update_ini_on_post_insert($post_id, WP_Post $post, $update)
    {
        if ($post->post_type === 'instance') {

            $ini_content = parse_ini_file(dirname(get_home_path()) . '/multi-moodle-instances/instances.ini', true);
            $ini_content['subdomains']['sub'][$post->post_name] = $post->post_title;
            $this->write_ini_file($ini_content, dirname(get_home_path()) . '/multi-moodle-instances/instances.ini', true);
            $this->sync_instances_with_ini();
            wp_redirect(home_url() . '/instance');
        }
    }

    /**
     * @param $postid
     * @param WP_Post $post
     * @return void
     */
    public function update_ini_on_post_delete($postid, WP_Post $post)
    {
        if ($post->post_type === 'instance') {
            $ini_content = parse_ini_file(dirname(get_home_path()) . '/multi-moodle-instances/instances.ini', true);
            unset($ini_content['subdomains']['sub'][$post->post_name]);
            $this->write_ini_file($ini_content, dirname(get_home_path()) . '/multi-moodle-instances/instances.ini', true);
            $command = 'cd ' . dirname(get_home_path()) . '/multi-moodle-instances/ && bash delete.sh ' . $post->post_name . ' y ';
            shell_exec($command);
        }
    }


    public function add_delete_button()
    {
        if (get_post_type() === 'instance' && !is_archive() && is_user_logged_in()) {
            ?>

            <a href="?delete=request" class="button">Instanz löschen</a>

            <?php
            if ($_GET['delete'] === 'request') {
                ?>
                <div>
                    Wirklich Löschen?
                    <a href="?delete=confirm" class="button">Ja</a>
                    <a href="<?php echo get_post_permalink() ?>" class="button">Nein</a>
                </div>
                <?php
            }
            if ($_GET['delete'] === 'confirm') {
                $postID = get_the_ID();
                wp_delete_post($postID);
                $this->update_ini_on_post_delete(get_the_ID(), get_post());
            }
        }
    }

    public function delete_post_on_attribute_pass()
    {
        if (get_post_type() === 'instance' && !is_archive() && is_user_logged_in()) {
            if ($_GET['delete'] === 'confirm') {
                $postID = get_the_ID();
                wp_delete_post($postID);
                $this->update_ini_on_post_delete(get_the_ID(), get_post());
            }
        }
    }

    /**
     * This Function runs as a Cronjob which should run the script required to creat a new moodle instance
     * and set the Post_Status of the instance to publish afterwards
     * @return void
     */
    public function cron_create_new_instance()
    {


    }

    public function cron_update_instance_courses()
    {
        global $wpdb;

        $course_id = intval(3);

        /*
         * TODO: When the courses are querried unset the course with id =1 because this is the reference to the
         * TODO: instance and actual course
         */

        $query = "select CONCAT('/pluginfile.php',filepath, contextid,'/',component,'/',filearea,'/', filename) path
from moodle_gpen_dialogue_net.test_files 
where component='course' and mimetype is NOT null and filearea='overviewfiles' and contextid in (
	select id from moodle_gpen_dialogue_net.test_context where instanceid= $course_id
)";

        $course_img_path = $wpdb->get_var($query);
    }

    /**
     * @param $assoc_arr
     * @param $path
     * @param $has_sections
     * @return false|int
     *
     * written by Harikrishnan
     * https://stackoverflow.com/questions/1268378/create-ini-file-write-values-in-php
     */
    function write_ini_file($assoc_arr, $path, $has_sections = FALSE)
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
                    } else if ($elem2 == "") $content .= $key2 . " = \n";
                    else $content .= $key2 . " = \"" . $elem2 . "\"\n";
                }
            }
        } else {
            foreach ($assoc_arr as $key => $elem) {
                if (is_array($elem)) {
                    for ($i = 0; $i < count($elem); $i++) {
                        $content .= $key . "[] = \"" . $elem[$i] . "\"\n";
                    }
                } else if ($elem == "") $content .= $key . " = \n";
                else $content .= $key . " = \"" . $elem . "\"\n";
            }
        }

        if (!$handle = fopen($path, 'w')) {
            return false;
        }

        $success = fwrite($handle, $content);
        fclose($handle);

        return $success;
    }
}

new ListInstances();