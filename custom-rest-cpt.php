<?php
/**
 * Plugin Name: Custom REST CPT Endpoint
 * Description: Custom REST API with CPT, replies, and admin settings.
 * Version: 2.0
 * Author: Cryptoball cryptoball7@gmail.com
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CRCE_Plugin {

    public function __construct() {
        add_action( 'init', [ $this, 'register_cpt' ] );
        add_action( 'init', [ $this, 'ensure_default_posts' ] );

        add_action( 'rest_api_init', [ $this, 'register_routes' ] );

        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );

        add_action( 'wp_head', [ $this, 'output_api_notice' ] );

        register_activation_hook( __FILE__, [ $this, 'activate' ] );
    }

    /**
     * Activation
     */
    public function activate() {
        $this->register_cpt();
        flush_rewrite_rules();
        $this->ensure_default_posts();

        if ( get_option( 'crce_api_notice_message', null ) === null ) {
            add_option( 'crce_api_notice_message', $this->get_default_notice_message() );
        }

if ( get_option( 'crce_enable_api_notice', null ) === null ) {
    add_option( 'crce_enable_api_notice', 1 );
}
    }

    /**
     * CPT
     */
    public function register_cpt() {
        register_post_type( 'crce_item', [
            'label' => 'Items',
            'public' => true,
            'show_in_rest' => true,
            'supports' => [ 'title', 'editor', 'comments' ],
        ]);
    }

    /**
     * Default posts
     */
    public function ensure_default_posts() {

        $defaults = [
            'root' => [
                'title' => 'Root Endpoint',
                'content' => "Welcome to the API.\n\nUse /item/{slug} to fetch content."
            ],
            'not-found' => [
                'title' => 'Not Found',
                'content' => "The requested item does not exist."
            ]
        ];

        foreach ( $defaults as $slug => $data ) {

            $existing = get_page_by_path( $slug, OBJECT, 'crce_item' );

            if ( ! $existing ) {
                wp_insert_post([
                    'post_title'   => $data['title'],
                    'post_name'    => $slug,
                    'post_content' => $data['content'],
                    'post_status'  => 'publish',
                    'post_type'    => 'crce_item',
                ]);
            }
        }
    }

    /**
     * REST Routes
     */
    public function register_routes() {

        register_rest_route( 'crce/v1', '/item(?:/(?P<slug>[a-zA-Z0-9-_]+))?', [
            'methods'  => 'GET',
            'callback' => [ $this, 'get_item' ],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route( 'crce/v1', '/item/(?P<slug>[a-zA-Z0-9-_]+)/reply', [
            'methods'  => 'POST',
            'callback' => [ $this, 'post_reply' ],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * GET handler
     */
    public function get_item( $request ) {

        $slug = $request['slug'] ?? 'root';
        $slug = sanitize_title( $slug );

        $post = get_page_by_path( $slug, OBJECT, 'crce_item' );

        if ( ! $post ) {
            $post = get_page_by_path( 'not-found', OBJECT, 'crce_item' );
        }

        if ( ! $post ) {
            return new WP_REST_Response([ 'error' => 'Not found' ], 404);
        }

        return $this->format_post( $post );
    }

    /**
     * POST reply
     */
    public function post_reply( $request ) {

        $slug = sanitize_title( $request['slug'] );
        $post = get_page_by_path( $slug, OBJECT, 'crce_item' );

        if ( ! $post ) {
            return new WP_REST_Response([ 'error' => 'Post not found' ], 404);
        }

        $params = $request->get_json_params();

        $content = sanitize_textarea_field( $params['content'] ?? '' );

        if ( empty( $content ) ) {
            return new WP_REST_Response([ 'error' => 'Content required' ], 400);
        }

        $comment_id = wp_insert_comment([
            'comment_post_ID' => $post->ID,
            'comment_content' => $content,
            'comment_author'  => sanitize_text_field( $params['author_name'] ?? '' ),
            'comment_author_email' => sanitize_email( $params['author_email'] ?? '' ),
            'comment_approved' => 1,
        ]);

        return [
            'success' => true,
            'comment_id' => $comment_id
        ];
    }

    /**
     * Format post (clean JSON)
     */
    private function format_post( $post ) {

        $content = trim( wp_strip_all_tags( $post->post_content ) );

        $comments = get_comments([
            'post_id' => $post->ID,
            'status'  => 'approve'
        ]);

        $replies = array_map(function($c) {
            return [
                'id' => $c->comment_ID,
                'author' => $c->comment_author,
                'content' => wp_strip_all_tags( $c->comment_content ),
                'date' => $c->comment_date
            ];
        }, $comments);

        return [
            'id' => $post->ID,
            'slug' => $post->post_name,
            'title' => $post->post_title,
            'content' => $content,
            'replies' => $replies
        ];
    }

    /**
     * Default API notice
     */
    private function get_default_notice_message() {

        $base = get_rest_url( null, 'crce/v1/item' );

        return "Custom REST API Available\n\n"
            . "GET {$base}\n"
            . "GET {$base}/{slug}\n\n"
            . "POST {$base}/{slug}/reply\n\n"
            . "{ \"content\": \"Your reply\" }";
    }

    /**
     * Output API notice
     */
    public function output_api_notice() {

        if ( is_admin() ) return;

    $enabled = get_option( 'crce_enable_api_notice', 1 );

    if ( ! $enabled ) return;

        $message = get_option( 'crce_api_notice_message', $this->get_default_notice_message() );

        echo "\n<!--\n" . esc_html( $message ) . "\n-->\n";
    }

    /**
     * Admin Menu
     */
    public function admin_menu() {
        add_menu_page(
            'CRCE API',
            'CRCE API',
            'manage_options',
            'crce-settings',
            [ $this, 'settings_page' ],
            'dashicons-rest-api'
        );
    }

    /**
     * Register setting
     */
    public function register_settings() {
        register_setting( 'crce_settings_group', 'crce_api_notice_message' );

    register_setting( 'crce_settings_group', 'crce_enable_api_notice', [
        'type' => 'boolean',
        'sanitize_callback' => function( $value ) {
            return $value ? 1 : 0;
        },
        'default' => 1
    ]);
    }

    /**
     * Settings page
     */
    public function settings_page() {

        if ( ! current_user_can( 'manage_options' ) ) return;
        ?>

        <div class="wrap">
            <h1>CRCE API Settings</h1>

            <form method="post" action="options.php">
                <?php settings_fields( 'crce_settings_group' ); ?>

                <textarea name="crce_api_notice_message" rows="12" class="large-text code"><?php
                    echo esc_textarea(
                        get_option( 'crce_api_notice_message', $this->get_default_notice_message() )
                    );
                ?></textarea>

                <p>
                    <button type="button" class="button" onclick="crceReset()">Reset to Default</button>
                </p>

<table class="form-table">
    <tr>
        <th scope="row">Enable API Source Notice</th>
        <td>
            <label>
                <input type="checkbox" name="crce_enable_api_notice" value="1"
                    <?php checked( get_option( 'crce_enable_api_notice', 1 ), 1 ); ?> />
                Show API notice in HTML source
            </label>
        </td>
    </tr>
</table>

                <?php submit_button(); ?>
            </form>
        </div>

        <script>
        function crceReset() {
            if (confirm('Reset to default?')) {
                document.querySelector('textarea[name="crce_api_notice_message"]').value =
                    <?php echo json_encode( $this->get_default_notice_message() ); ?>;
            }
        }
        </script>

        <?php
    }
}

/**
 * Bootstrap plugin
 */
new CRCE_Plugin();