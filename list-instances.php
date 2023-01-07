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

class ListInstances {
	public function __construct() {

        if(!defined('MOODLE_MAIN_HOST')){
            $u = parse_url(home_url());
            $host = str_replace('manage','',$u['host']);
	        define('MOODLE_MAIN_HOST',$host);
        }

		add_action( 'init', [ $this, 'delete_post_on_attribute_pass' ] );
		add_action( 'wp_insert_post', [ $this, 'update_ini_on_post_insert' ], 10, 3 );
		##add_action( 'delete_post', [ $this, 'update_ini_on_post_delete' ], 10, 2 );
		add_action( 'blocksy:single:content:bottom', [ $this, 'display_instance_content' ] );
		add_action( 'blocksy:single:bottom', [ $this, 'add_delete_button' ] , 20);
		add_action( 'rpi_multi_moodle_update_all_courses', [ $this, 'cron_update_instance_courses' ] );
		//add_action( 'init', [ $this, 'cron_update_instance_courses' ] );
		add_action( 'init', [ $this, 'cron_create_new_instance' ] );
		add_action( 'rpi_multi_moodle_create_new_instance', [ $this, 'cron_create_new_instance' ] );

		add_filter('post_type_link', function ($post_link, WP_Post $post) {
            if('moodle_course' == $post->post_type){
                return  get_post_meta($post->ID, 'course-url', true);
            }
            return $post_link;
		},10,2);
	}

	public function sync_instances_with_ini() {

		$assoc_arr                      = parse_ini_file( dirname( get_home_path() ) . '/multi-moodle-instances/instances.ini', true );
		$assoc_arr['subdomains']['sub'] = array();

		$instances = get_posts( [
			'numberposts' => - 1,
			'post_status' => 'publish',
			'post_type'   => 'instance'
		] );
		foreach ( $instances as $instance ) {
			if ( is_a( $instance, 'WP_Post' ) ) {
				$subdomain = str_replace('-','_',$instance->post_name);
				$assoc_arr['subdomains']['sub'][ $subdomain ] = $instance->post_title;
				$this->setup_new_moodle_instance( $instance );
			}
		}

		$this->write_ini_file( $assoc_arr, dirname( get_home_path() ) . '/multi-moodle-instances/instances.ini', true );


	}

	/**
	 * @param $post_id
	 * @param WP_Post $post
	 * @param $update
	 *
	 * @return void
	 */
	public function update_ini_on_post_insert( $post_id, WP_Post $post, $update ) {
		if ( $post->post_type === 'instance' && !$update) {
			wp_redirect( home_url() . '/instance' );
		}
	}

	/**
	 * @param $postid
	 * @param WP_Post $post
	 *
	 * @return void
	 */
	public function update_ini_on_post_delete( $postid, WP_Post $post ) {
		if ( $post->post_type === 'instance' && current_user_can('administrator')) {
			$subdomain = str_replace('-','_',$post->post_name);

			$ini_content = parse_ini_file( dirname( get_home_path() ) . '/multi-moodle-instances/instances.ini', true );
			$command     = 'cd ' . dirname( get_home_path() ) . '/multi-moodle-instances/ && bash delete.sh "' . $subdomain . '" "y" ';
            var_dump($command); die();
			echo shell_exec( $command );
			unset( $ini_content['subdomains']['sub'][ $subdomain ] );
			$this->write_ini_file( $ini_content, dirname( get_home_path() ) . '/multi-moodle-instances/instances.ini', true );
			//die();

		}
	}


	public function add_delete_button() {
		if ( get_post_type() === 'instance' && ! is_archive() && is_user_logged_in() && current_user_can( 'administrator' ) ) {
			?>

            <a href="?delete=request" class="button">Instanz löschen</a>

			<?php
			if ( $_GET['delete'] === 'request' ) {
				?>
                <div>
                    Wirklich Löschen?
                    <a href="?delete=confirm" class="button">Ja</a>
                    <a href="<?php echo get_post_permalink() ?>" class="button">Nein</a>
                </div>
				<?php
			}
			if ( $_GET['delete'] === 'confirm' ) {
				$postID = get_the_ID();
				wp_delete_post( $postID );
				//$this->update_ini_on_post_delete(get_the_ID(), get_post());
			}
		}
	}

	public function delete_post_on_attribute_pass() {
		if ( get_post_type() === 'instance' && ! is_archive() && is_user_logged_in()  && current_user_can('manage_options')) {
			if ( $_GET['delete'] === 'confirm' ) {
				$postID = get_the_ID();
				wp_delete_post( $postID );
				$this->update_ini_on_post_delete( get_the_ID(), get_post() );



                die();
			}
		}
	}

	/**
	 * This Function runs as a Cronjob which should run the script required to creat a new moodle instance
	 * and set the Post_Status of the instance to publish afterwards
	 * @return void
	 */
	public function cron_create_new_instance() {
		global $wpdb;
		$instances = get_posts( [
			'numberposts' => - 1,
			'post_status' => 'draft',
			'post_type'   => 'instance'
		] );


		foreach ( $instances as $instance ) {
            if ( is_a( $instance, 'WP_Post' ) ) {
                $this->setup_new_moodle_instance( $instance );
			}
		}

	}

	public function cron_update_instance_courses() {
		global $wpdb;
		$moodledb = new wpdb(DB_MOODLE_USER,DB_MOODLE_PASSWORD,DB_MOODLE,DB_HOST);


        $instances = get_posts( [
			'numberposts' => - 1,
			'post_status' => 'publish',
			'post_type'   => 'instance'
		] );

        foreach ( $instances as $instance ) {
			if ( is_a( $instance, 'WP_Post' ) ) {
				$subdomain = preg_replace( '/^[a-z][0-9]_/', '', $instance->post_name );


                $sql = "select id, fullname, summary, startdate, enddate, category from {$subdomain}_course where visible = 1 ORDER BY startdate DESC;";

				$courses = (array) $moodledb->get_results( $sql, OBJECT );


                foreach ( $courses as $course ) {
					$course_id = intval( $course->id);

					if ( $course_id == 1 ) {

						$instance->post_title   = $course->fullname;
						$instance->post_content = $course->summary;

						wp_update_post( $instance ,false, false);

					} else {

						$query = "select CONCAT('/pluginfile.php',filepath, contextid,'/',component,'/',filearea,'/', filename) path
                              from test_files 
                              where component='course' and mimetype is NOT null and filearea='overviewfiles' and contextid in (
	                            select id from test_context where instanceid= {$course_id}
	                          )";

                        $course_img_path = $moodledb->get_var( $query );

                        $moodle_home            = 'https://'.$subdomain.MOODLE_MAIN_HOST;
						$course->url            = $moodle_home . '/course/view.php?id=' . $course_id;
						$course->course_img_url = $moodle_home . $course_img_path;
						$course->slug           = $subdomain . '_' . $course_id;
						$course->instance       = $subdomain;

                        $this->update_course( $course );
					}

				}
			}
		}


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
	protected function update_course( stdClass $course ) {

		$moodle_course = get_page_by_path( $course->slug, OBJECT, 'moodle_course' );
		if ( ! $moodle_course ) {
			$moodle_course = array(
				'post_name'    => $course->slug,
				'post_title'   => $course->fullname,
				'post_content' => $course->summary,
				'post_status' => 'publish',
				'post_type'    => 'moodle_course'
			);
			$post_id       = wp_insert_post( $moodle_course );
			update_post_meta( $post_id, 'course-category', $course->category );
			update_post_meta( $post_id, 'course-id', $course->id );
		} else {
			$post_id                     = $moodle_course->ID;
			$moodle_course->post_title   = $course->fullname;
			$moodle_course->post_content = $course->summary;
		}

		if ( is_numeric( $post_id ) ) {
			update_post_meta( $post_id, 'course-url', $course->url );
			update_post_meta( $post_id, 'course-startdate', $course->startdate );
			update_post_meta( $post_id, 'course-enddate', $course->enddate );

			if ( $course->course_img_url && ! get_post_meta( $post_id, 'course_img_url', true ) ) {
				update_post_meta( $post_id, 'course_img_url', $course->course_img_url );
				$this->Generate_Featured_Image( $course->course_img_url, $post_id );
			}

            $term = get_term_by('slug',$course->instance,'mdl-instance');
            if($term) {
	            $term_id = $term->term_id;
            }else {
	            $term_id = wp_insert_term( $course->instance, 'mdl-instance' );
            }

            wp_set_object_terms($post_id,[$term_id],'mdl-instance');

		}
	}

	/**
     *
	 * @return void
	 */
    public function display_instance_content(){

        if('instance' !== get_post_type()){
            return;
        }

        $post = get_post(get_the_ID());
        $manager =  get_field('firstname',$post->ID) . ' ' ;
        $manager .=  get_field('lastname',$post->ID);

        $url = 'https://'.$post->post_name.MOODLE_MAIN_HOST;

        $args = array(
             'post_type'=>'moodle_course',
             'tax_query'=>array(
	             'taxonomy' => 'mdl-instance',
	             'terms' => $post->post_name,
	             'field' => 'slug',
             )
        );
	    $custom_query = new WP_Query($args);?>
        <hr>
        <div class="table-grid" style="">
            <div class="row-label">Adresse</div><div><a href="<?php echo $url;?>"><?php echo $url;?></a></div>
            <div class="row-label">Ansprechperson</div><div><?php echo $manager;?></div>
        </div>
        <hr>
        <h2>Öffentliche Kurse</h2>
        <div class="table-grid" style="display: grid;grid-template-columns: 1fr 1fr 1fr;"><?php
	    if ($custom_query->have_posts()) : while($custom_query->have_posts()) : $custom_query->the_post();
            blocksy_render_archive_card();
	    endwhile; else : ?>
            <p>Keine öffentlichen Kurse</p>
	    <?php endif; wp_reset_postdata() ?>
        </div>



        <hr>
	    <?php

    }

	/**
	 * @param WP_Post $instance
	 *
	 * @return void
	 */
	protected function setup_new_moodle_instance( WP_Post $instance ) {

		$subdomain = str_replace('-','_',$instance->post_name);

		if ( ! file_exists( dirname( get_home_path() ) . '/moodle-data/' . $subdomain . '/' ) ) {


			$ini_content  = parse_ini_file( dirname( get_home_path() ) . '/multi-moodle-instances/instances.ini', true );
			$ini_content['subdomains']['sub'][ $subdomain ] = $instance->post_title;
			$this->write_ini_file( $ini_content, dirname( get_home_path() ) . '/multi-moodle-instances/instances.ini', true );


			$command = 'cd ' . dirname( get_home_path() ) . '/multi-moodle-instances/ && bash setup.sh "' . $subdomain . '" "' . $instance->post_title . '"';
			shell_exec( $command );

			$username  = get_post_meta( $instance->ID, 'username', true );
			$password  = get_post_meta( $instance->ID, 'password', true );
			$e_mail    = get_post_meta( $instance->ID, 'e-mail', true );
			$firstname = get_post_meta( $instance->ID, 'firstname', true );
			$lastname  = get_post_meta( $instance->ID, 'lastname', true );

			$user_command = 'cd ' . dirname( get_home_path() ) . '/multi-moodle-instances/ && export MULTI_HOST_SUBDOMAIN=' . $subdomain . ' &&  php7.4 create-user.php --username="' . $username . '" --email="' . $e_mail . '" --firstname="' . $firstname . '" --lastname="' . $lastname . '"  --password="' . $password . '"';
			echo shell_exec( $user_command );

            $instance->post_status = 'publish';

            wp_update_post($instance);
		}
	}

	/**
	 * @param $assoc_arr
	 * @param $path
	 * @param $has_sections
	 *
	 * @return false|int
	 *
	 * written by Harikrishnan
	 * https://stackoverflow.com/questions/1268378/create-ini-file-write-values-in-php
	 */
	function write_ini_file( $assoc_arr, $path, $has_sections = false ) {
		$content = "";
		if ( $has_sections ) {
			foreach ( $assoc_arr as $key => $elem ) {
				$content .= "[" . $key . "]\n";
				foreach ( $elem as $key2 => $elem2 ) {
					if ( is_array( $elem2 ) ) {
						foreach ( $elem2 as $key3 => $elem3 ) {
							$content .= $key2 . "[$key3] = \"" . $elem3 . "\"\n";
						}
					} else if ( $elem2 == "" ) {
						$content .= $key2 . " = \n";
					} else {
						$content .= $key2 . " = \"" . $elem2 . "\"\n";
					}
				}
			}
		} else {
			foreach ( $assoc_arr as $key => $elem ) {
				if ( is_array( $elem ) ) {
					for ( $i = 0; $i < count( $elem ); $i ++ ) {
						$content .= $key . "[] = \"" . $elem[ $i ] . "\"\n";
					}
				} else if ( $elem == "" ) {
					$content .= $key . " = \n";
				} else {
					$content .= $key . " = \"" . $elem . "\"\n";
				}
			}
		}

		if ( ! $handle = fopen( $path, 'w' ) ) {
			return false;
		}

		$success = fwrite( $handle, $content );
		fclose( $handle );

		return $success;
	}

	/**
	 * @param $image_url
	 * @param $post_id
	 *
	 * @return void
	 * @author Rob Vermeer
	 */
	protected function Generate_Featured_Image( $image_url, $post_id ) {
		$upload_dir = wp_upload_dir();
		$image_data = file_get_contents( $image_url );
		$filename   = basename( $image_url );
		if ( wp_mkdir_p( $upload_dir['path'] ) ) {
			$file = $upload_dir['path'] . '/' . $filename;
		} else {
			$file = $upload_dir['basedir'] . '/' . $filename;
		}
		file_put_contents( $file, $image_data );

		$wp_filetype = wp_check_filetype( $filename, null );
		$attachment  = array(
			'post_mime_type' => $wp_filetype['type'],
			'post_title'     => sanitize_file_name( $filename ),
			'post_content'   => '',
			'post_status'    => 'inherit'
		);
		$attach_id   = wp_insert_attachment( $attachment, $file, $post_id );
		require_once( ABSPATH . 'wp-admin/includes/image.php' );
		$attach_data = wp_generate_attachment_metadata( $attach_id, $file );
		$res1        = wp_update_attachment_metadata( $attach_id, $attach_data );
		$res2        = set_post_thumbnail( $post_id, $attach_id );
	}
}

new ListInstances();
