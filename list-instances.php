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
        add_action('wp', [$this, 'sync_instances_with_ini']);
    }

    public function sync_instances_with_ini()
    {

        $ini_content = parse_ini_file(dirname(get_home_path()) . '/multi-moodle-instances/instances.ini', true);


        $assoc_arr = [
            'exclude_plugins' => [
                'ex' => ['test' => 'sso']
            ]
            ,
            'domain' => ['main_host_domain' => '.gpendialogue.net']

        ];

        $instances = get_posts([
            'numberposts' => -1,
            'post_type' => 'instance'
        ]);
        foreach ($instances as $instance) {
            if (is_a($instance, 'WP_Post')) {
                $subdomain = get_post_meta($instance->ID, 'subdomain',true);
                $assoc_arr['subdomains']['sub'][$subdomain] = $instance->post_title;
                if (false)
                {
                    shell_exec('bash setup.sh <'.$subdomain.'> <'.$instance->post_title.'>');

                }
            }
        }
        $this->write_ini_file($assoc_arr,dirname(get_home_path()) . '/multi-moodle-instances/instances.ini', true);



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
                        foreach ($elem2 as $key3 => $elem3)
                        {
                            $content .= $key2 . "[$key3] = \"" . $elem3 . "\"\n" ;
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