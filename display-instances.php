<?php

class DisplayInstances
{
    public function __construct()
    {

        add_shortcode('render_instance_form', [$this, 'render_instance_form']);

        add_action('blocksy:single:content:bottom', [$this, 'display_instance_content']);
        add_action('blocksy:single:top', [$this, 'add_delete_button'], 20);
        add_action('blocksy:loop:card:end', [$this, 'add_moodle_thumbnail_to_card']);
    }

    public function render_instance_form()
    {

        if (current_user_can('administrator')) {
            ob_start();

            acfe_form('instanz');

            return ob_get_clean();
        }
    }

    /**
     *
     * @return void
     */
    public function display_instance_content()
    {

        if ('instance' !== get_post_type()) {
            return;
        }

        $post = get_post(get_the_ID());
        $manager = "N.N.";
        if (function_exists('get_field')) {
            $manager = get_field('firstname', $post->ID) . ' ';
            $manager .= get_field('lastname', $post->ID);

        }

        $url = 'https://' . $post->post_name . MOODLE_MAIN_HOST;


        $args = array(
            'post_type' => 'moodle_course',
            'tax_query' => array([
                'taxonomy' => 'mdl-instance',
                'terms' => $post->post_name,
                'field' => 'slug',
            ]

            )
        );
        $custom_query = new WP_Query($args);
        ?>
        <hr>
        <div class="table-grid" style="">
            <div class="row-label">Adresse</div>
            <div><a href="<?php echo $url; ?>"><?php echo $url; ?></a></div>
            <div class="row-label">Ansprechperson</div>
            <div><?php echo $manager; ?></div>
        </div>
        <hr>
        <h2>Öffentliche Kurse</h2>
        <div class="table-grid" style="display: grid;grid-template-columns: 1fr 1fr 1fr;"><?php
            if ($custom_query->have_posts()) : while ($custom_query->have_posts()) : $custom_query->the_post();
                if (function_exists('blocksy_render_archive_card')) {
                    //desplay Course Content with the render method of blocksy theme
                    blocksy_render_archive_card();
                }
            endwhile;
            else : ?>
                <p>Keine öffentlichen Kurse</p>
            <?php endif;
            wp_reset_postdata() ?>
        </div>
        <hr>
        <?php

    }

    /**
     * @return void
     */
    public function add_delete_button()
    {
        if (get_post_type() === 'instance' && !is_archive() && is_user_logged_in() && current_user_can('administrator')) {
            ?>
            <div style="position: relative">
                <div id="instance-delete">
                    <?php
                    if ($_GET['delete'] === 'request') {
                        ?>
                        <div class="delete-confirm">
                            <div>Soll diese Moodle-Instanz wirklich gelöscht werden?</div>
                            <a href="?delete=confirm" class="button yes">Ja</a>
                            <a href="<?php echo get_post_permalink() ?>" class="button no">Nein</a>
                        </div>
                        <?php
                    } else {
                        ?>
                        <a href="?delete=request" class="button">Instanz löschen</a>
                        <?php
                    }
                    ?>
                </div>
            </div>
            <?php


        }
    }

    /**
     *
     * @return void
     */
    public function add_moodle_thumbnail_to_card()
    {
        if (get_post_type() === 'instance') {
            ob_start();
            $sub = get_post_field('post_name', get_post());
            ?>
            <div>
                <a href="https://<?php echo $sub ?>.gpendialogue.net">
                    <img src="https://s0.wp.com/mshots/v1/https://<?php echo $sub ?>.gpendialogue.net">
                </a>
                <p><?php the_content() ?></p>
                <div>
                    <?php echo $sub ?>.gpendialogue.net
                </div>
            </div>
            <?php
            echo ob_get_clean();
        }
    }
}
