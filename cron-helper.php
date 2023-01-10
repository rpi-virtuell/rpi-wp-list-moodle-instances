<?php

class CronHelper
{

    public function __construct()
    {
        add_action('rpi_multi_moodle_update_all_courses', [$this, 'cron_update_instance_courses']);
        add_action('rpi_multi_moodle_create_new_instance', [$this, 'cron_create_new_instance']);
    }


    /**
     * Fetch information of moodel courses voia databas request
     * Save course infos in CPT moodle_course
     *
     */
    public function cron_update_instance_courses()
    {
        global $wpdb;
        $moodledb = new wpdb(DB_MOODLE_USER, DB_MOODLE_PASSWORD, DB_MOODLE, DB_HOST);


        $instances = get_posts([
            'numberposts' => -1,
            'post_status' => 'publish',
            'post_type' => 'instance'
        ]);

        foreach ($instances as $instance) {
            if (is_a($instance, 'WP_Post')) {

                $prefix = ListInstances::get_moodle_db_prefix($instance->post_name);

                $sql = "select id, fullname, summary, startdate, enddate, category from {$prefix}_course where visible = 1 ORDER BY startdate DESC;";

                $courses = (array)$moodledb->get_results($sql, OBJECT);


                foreach ($courses as $course) {
                    $course_id = intval($course->id);

                    if ($course_id == 1) {

                        $instance->post_title = $course->fullname;
                        $instance->post_content = $course->summary;

                        wp_update_post($instance, false, false);

                    } else {

                        $query = "select CONCAT('/pluginfile.php',filepath, contextid,'/',component,'/',filearea,'/', filename) path
                              from test_files 
                              where component='course' and mimetype is NOT null and filearea='overviewfiles' and contextid in (
	                            select id from test_context where instanceid = {$course_id}
	                          )";

                        $course_img_path = $moodledb->get_var($query);

                        $moodle_home = 'https://' . $instance->post_name . MOODLE_MAIN_HOST;
                        $course->url = $moodle_home . '/course/view.php?id=' . $course_id;
                        $course->course_img_url = $moodle_home . $course_img_path;
                        $course->slug = $prefix . '_' . $course_id;
                        $course->instance = $instance->post_name;

                        ListInstances::update_course($course);
                    }

                }
            }
        }


    }

    /**
     * This function runs as a cronjob, which should run the script required to create a new moodle instance
     * and set the Post_Status of the instance to publish afterwards
     * @return void
     */
    public function cron_create_new_instance()
    {

        $instances = get_posts([
            'numberposts' => -1,
            'post_status' => 'draft',
            'post_type' => 'instance'
        ]);


        foreach ($instances as $instance) {
            if (is_a($instance, 'WP_Post')) {

                if ($this->setup_new_moodle_instance($instance) && $this->set_manager($instance)) {
                    $instance->post_status = 'publish';
                    wp_update_post($instance);
                }


            }
        }
    }

    /**
     * @param WP_Post $instance
     *
     * @return boolean
     */
    protected function setup_new_moodle_instance(WP_Post $instance)
    {

        $prefix = ListInstances::get_moodle_db_prefix($instance->post_name);

        if (!file_exists(dirname(get_home_path()) . '/moodle-data/' . $prefix . '/')) {


            $ini_content = parse_ini_file(dirname(get_home_path()) . '/multi-moodle-instances/instances.ini', true);
            $ini_content['subdomains']['sub'][$instance->post_name] = $instance->post_title;
            ListInstances::write_ini_file($ini_content, dirname(get_home_path()) . '/multi-moodle-instances/instances.ini', true);


            $command = 'cd ' . dirname(get_home_path()) . '/multi-moodle-instances/ && bash setup.sh "' . $instance->post_name . '" "' . $instance->post_title . '"';

            ob_start();
            system($command);
            $output = ob_get_clean();

            ListInstances::log($command . "\n" . $output, "_$prefix");

            return true;

        }
        return false;

    }

    /**
     * @param WP_Post $instance
     * @param mixed $user stdClass | string
     *
     * @return boolean
     */
    protected function set_manager(WP_Post $instance)
    {


        $person = new stdClass();

        $person->username = get_post_meta($instance->ID, 'username', true);
        $person->password = get_post_meta($instance->ID, 'password', true);
        $person->email = get_post_meta($instance->ID, 'e-mail', true);
        $person->firstname = get_post_meta($instance->ID, 'firstname', true);
        $person->lastname = get_post_meta($instance->ID, 'lastname', true);
        $person->is_manager = true;

        return ListInstances::create_user($person, $instance);


    }

}