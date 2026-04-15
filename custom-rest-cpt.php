<?php
/**
 * Plugin Name: Custom REST CPT Endpoint
 * Description: Adds a custom post type and REST endpoint with root + fallback logic.
 * Version: 1.2
 * Author: Cryptoball cryptoball7@gmail.com
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Register Custom Post Type
 */
function crce_register_cpt() {
    register_post_type( 'crce_item', array(
        'label' => 'Items',
        'public' => true,
        'show_in_rest' => true,
        'supports' => array( 'title', 'editor', 'custom-fields', 'comments' ),
        'has_archive' => true,
    ));
}
add_action( 'init', 'crce_register_cpt' );

/**
 * Create default posts if they don't exist
 */
function crce_ensure_default_posts() {

    $defaults = array(
        'root' => array(
            'title' => 'Root Endpoint',
            'content' => '
                <h2>Welcome to the API</h2>
                <p>This is the default "root" response.</p>
                <p>Use the endpoint with a slug to retrieve specific content:</p>
                <pre>/wp-json/crce/v1/item/{slug}</pre>
                <p>Create new Items in WordPress with matching slugs to expand this API.</p>
            '
        ),
        'not-found' => array(
            'title' => 'Content Not Found',
            'content' => '
                <h2>Not Found</h2>
                <p>The requested resource could not be found.</p>
                <p>Check the slug or create a new Item in the admin.</p>
            '
        )
    );

    foreach ( $defaults as $slug => $data ) {

        $existing = get_page_by_path( $slug, OBJECT, 'crce_item' );

        if ( ! $existing ) {
            wp_insert_post( array(
                'post_title'   => $data['title'],
                'post_name'    => $slug,
                'post_content' => $data['content'],
                'post_status'  => 'publish',
                'post_type'    => 'crce_item',
            ));
        }
    }
}

/**
 * Run on activation
 */
function crce_activate_plugin() {
    crce_register_cpt();
    flush_rewrite_rules();
    crce_ensure_default_posts();

    if ( get_option( 'crce_api_notice_message', null ) === null ) {
        add_option( 'crce_api_notice_message', crce_get_default_notice_message() );
    }
}
register_activation_hook( __FILE__, 'crce_activate_plugin' );

/**
 * Also ensure posts exist during runtime (safety net)
 */
add_action( 'init', 'crce_ensure_default_posts' );

/**
 * Register REST Route
 */
function crce_register_rest_route() {
    register_rest_route( 'crce/v1', '/item(?:/(?P<slug>[a-zA-Z0-9-_]+))?', array(
        'methods'  => 'GET',
        'callback' => 'crce_get_item',
        'permission_callback' => '__return_true',
    ));
}
add_action( 'rest_api_init', 'crce_register_rest_route' );

/**
 * REST Callback
 */
function crce_get_item( $request ) {

    $slug = $request->get_param( 'slug' );

    $target_slug = empty( $slug ) ? 'root' : sanitize_title( $slug );

    $query = new WP_Query( array(
        'post_type' => 'crce_item',
        'name'      => $target_slug,
        'posts_per_page' => 1,
    ));

    if ( $query->have_posts() ) {
        $query->the_post();
        return crce_format_post( get_post() );
    }

    // fallback
    $fallback = new WP_Query( array(
        'post_type' => 'crce_item',
        'name'      => 'not-found',
        'posts_per_page' => 1,
    ));

    if ( $fallback->have_posts() ) {
        $fallback->the_post();
        return crce_format_post( get_post() );
    }

    return new WP_REST_Response( array(
        'error' => 'No content found.',
    ), 404 );
}

/**
 * Format Post for API Output
 */
function crce_format_post( $post ) {

    $raw_content = $post->post_content;

    // Split into paragraphs
    $paragraphs = array_filter( array_map( function( $p ) {
        return trim( wp_strip_all_tags( $p ) );
    }, preg_split( '/\n\s*\n/', $raw_content ) ) );

    return array(
        'id'         => $post->ID,
        'slug'       => $post->post_name,
        'title'      => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
        'content'    => implode( "\n\n", $paragraphs ),
        'paragraphs' => array_values( $paragraphs ),
    );
}

/**
 * Output API notice in HTML source
 */
function crce_get_default_notice_message() {

    $base_url = get_rest_url( null, 'crce/v1/item' );

    $message  = "Custom REST API Available\n";
    $message .= "================================\n\n";

    $message .= "GET Endpoints:\n";
    $message .= "Root: {$base_url}\n";
    $message .= "By slug: {$base_url}/{slug}\n\n";

    $message .= "POST Endpoint (Reply):\n";
    $message .= "{$base_url}/{slug}/reply\n\n";

    $message .= "Request Body (JSON):\n";
    $message .= "{\n";
    $message .= "  \"author_name\": \"Your Name\",\n";
    $message .= "  \"author_email\": \"your@email.com\",\n";
    $message .= "  \"content\": \"Your reply message\"\n";
    $message .= "}\n\n";

    $message .= "Notes:\n";
    $message .= "- Replies are stored as comments on the item\n";
    $message .= "- Content must not be empty\n";
    $message .= "- Slug must match an existing item\n";
    $message .= "- If no slug is provided, the root item is returned\n";

    return $message;
}

function crce_output_api_notice() {

    if ( is_admin() ) return;

    $message = get_option( 'crce_api_notice_message', '' );

    // Safety fallback (in case option was deleted)
    if ( empty( $message ) ) {
        $message = crce_get_default_notice_message();
    }

    echo "\n<!--\n" . esc_html( $message ) . "\n-->\n";
}
add_action( 'wp_head', 'crce_output_api_notice' );

// Handling Replies

function crce_register_reply_route() {
    register_rest_route( 'crce/v1', '/item/(?P<slug>[a-zA-Z0-9-_]+)/reply', array(
        'methods'  => 'POST',
        'callback' => 'crce_handle_reply',
        'permission_callback' => '__return_true',
    ));
}
add_action( 'rest_api_init', 'crce_register_reply_route' );

function crce_handle_reply( $request ) {

    $slug = sanitize_title( $request['slug'] );

    // Find post
    $post = get_page_by_path( $slug, OBJECT, 'crce_item' );

    if ( ! $post ) {
        return new WP_REST_Response( array(
            'error' => 'Post not found'
        ), 404 );
    }

    // Get JSON body
    $params = $request->get_json_params();

    $author_name  = sanitize_text_field( $params['author_name'] ?? '' );
    $author_email = sanitize_email( $params['author_email'] ?? '' );
    $content      = sanitize_textarea_field( $params['content'] ?? '' );

    if ( empty( $content ) ) {
        return new WP_REST_Response( array(
            'error' => 'Reply content is required'
        ), 400 );
    }

    // Insert comment
    $comment_id = wp_insert_comment( array(
        'comment_post_ID' => $post->ID,
        'comment_content' => $content,
        'comment_author'  => $author_name,
        'comment_author_email' => $author_email,
        'comment_approved' => 1, // change to 0 if you want moderation
    ));

    if ( ! $comment_id ) {
        return new WP_REST_Response( array(
            'error' => 'Failed to save reply'
        ), 500 );
    }

    return array(
        'success' => true,
        'comment_id' => $comment_id,
        'post_slug' => $slug
    );
}

// Settings Page

function crce_add_admin_menu() {
    add_menu_page(
        'CRCE API Settings',        // Page title
        'CRCE API',                // Menu title
        'manage_options',          // Capability
        'crce-settings',           // Menu slug
        'crce_settings_page_html', // Callback
        'dashicons-rest-api',      // Icon
        25                         // Position
    );
}
add_action( 'admin_menu', 'crce_add_admin_menu' );

function crce_register_settings() {
    register_setting(
        'crce_settings_group',
        'crce_api_notice_message',
        array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => ''
        )
    );
}
add_action( 'admin_init', 'crce_register_settings' );

function crce_settings_page_html() {

    if ( ! current_user_can( 'manage_options' ) ) return;

    ?>
    <div class="wrap">
        <h1>CRCE API Settings</h1>

        <form method="post" action="options.php">
            <?php
                settings_fields( 'crce_settings_group' );
                do_settings_sections( 'crce_settings_group' );
            ?>

            <table class="form-table">
                <tr>
                    <th scope="row">API Source Notice</th>
                    <td>
                        <textarea 
                            name="crce_api_notice_message" 
                            rows="12" 
                            class="large-text code"
                        ><?php echo esc_textarea(
                            get_option(
                                'crce_api_notice_message',
                                crce_get_default_notice_message()
                            )
                        ); ?></textarea>

                        <p class="description">
                            This message is shown in the HTML source of your site.
                        </p>

                        <button type="button" class="button" onclick="crceResetNotice()">
                            Reset to Default
                        </button>

                        <script>
                        function crceResetNotice() {
                            if (confirm('Reset message to default?')) {
                                document.querySelector('textarea[name="crce_api_notice_message"]').value = <?php echo json_encode( crce_get_default_notice_message() ); ?>;
                            }
                        }
                        </script>

                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
