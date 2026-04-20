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

add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
add_action( 'save_post', [ $this, 'save_meta_box' ] );

        add_action( 'rest_api_init', [ $this, 'register_routes' ] );

        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );

        add_action( 'wp_head', [ $this, 'output_api_notice' ] );
add_action( 'wp_head', [ $this, 'output_jsonld' ] );

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

if ( get_option( 'crce_enable_jsonld', null ) === null ) {
    add_option( 'crce_enable_jsonld', 1 );
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

public function add_meta_box() {
    add_meta_box(
        'crce_replies_box',
        'API Replies',
        [ $this, 'render_meta_box' ],
        'crce_item',
        'side'
    );
}

public function render_meta_box( $post ) {

    $enabled = get_post_meta( $post->ID, 'crce_enable_replies', true );

    // default ON if not set
    if ( $enabled === '' ) $enabled = 1;

    wp_nonce_field( 'crce_replies_nonce', 'crce_replies_nonce_field' );
    ?>

    <label>
        <input type="checkbox" name="crce_enable_replies" value="1"
            <?php checked( $enabled, 1 ); ?> />
        Allow replies via API
<p style="margin-top:8px; color:#666;">
    When disabled, the API will reject POST requests to this item.
</p>
    </label>

    <?php
}

public function save_meta_box( $post_id ) {

    if ( ! isset( $_POST['crce_replies_nonce_field'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['crce_replies_nonce_field'], 'crce_replies_nonce' ) ) return;

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    $value = isset( $_POST['crce_enable_replies'] ) ? 1 : 0;

    update_post_meta( $post_id, 'crce_enable_replies', $value );
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

$ip = $this->get_user_ip();

if ( $this->is_rate_limited( $ip ) ) {
    return new WP_REST_Response([
        'error' => 'Too many requests. Please slow down.'
    ], 429);
}

$params = $request->get_json_params();

// Honeypot check
if ( ! empty( $params['website'] ) ) {
    return new WP_REST_Response([
        'error' => 'Spam detected'
    ], 400);
}

if ( function_exists( 'akismet_http_post' ) ) {

    $data = [
        'blog' => get_site_url(),
        'user_ip' => $this->get_user_ip(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'comment_type' => 'comment',
        'comment_author' => $params['author_name'] ?? '',
        'comment_author_email' => $params['author_email'] ?? '',
        'comment_content' => $params['content'] ?? ''
    ];

    $response = akismet_http_post( http_build_query( $data ), 'comment-check' );

    if ( isset( $response[1] ) && trim( $response[1] ) === 'true' ) {
        return new WP_REST_Response([
            'error' => 'Spam detected (Akismet)'
        ], 400);
    }
}

        $slug = sanitize_title( $request['slug'] );
        $post = get_page_by_path( $slug, OBJECT, 'crce_item' );

        if ( ! $post ) {
            return new WP_REST_Response([ 'error' => 'Post not found' ], 404);
        }

$enabled = get_post_meta( $post->ID, 'crce_enable_replies', true );

// default ON
if ( $enabled === '' ) $enabled = 1;

if ( ! $enabled ) {
    return new WP_REST_Response([
        'error' => 'Replies are disabled for this item'
    ], 403);
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

$enabled = get_post_meta( $post->ID, 'crce_enable_replies', true );
if ( $enabled === '' ) $enabled = 1;

        return [
            'id' => $post->ID,
            'slug' => $post->post_name,
            'title' => $post->post_title,
            'content' => $content,
            'replies' => $replies,
            'replies_enabled' => (bool) $enabled,
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

public function output_jsonld() {

    if ( is_admin() ) return;

    if ( ! get_option( 'crce_enable_jsonld', 1 ) ) return;

    $base = get_rest_url( null, 'crce/v1/item' );

    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'WebAPI',
        'name' => 'CRCE API',
        'url' => $base,
        'description' => 'Custom REST API for retrieving and replying to content items.',
        'documentation' => $base,
        'potentialAction' => [
            [
                '@type' => 'SearchAction',
                'target' => $base . '/{slug}',
                'query-input' => 'required name=slug'
            ],
            [
                '@type' => 'CommunicateAction',
                'target' => $base . '/{slug}/reply',
                'httpMethod' => 'POST'
            ]
        ]
    ];

    echo "\n<script type=\"application/ld+json\">\n";
    echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
    echo "\n</script>\n";
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
register_setting( 'crce_settings_group', 'crce_enable_jsonld', [
    'type' => 'boolean',
    'sanitize_callback' => function( $v ) { return $v ? 1 : 0; },
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
<tr>
    <th scope="row">Enable JSON-LD API Discovery</th>
    <td>
        <label>
            <input type="checkbox" name="crce_enable_jsonld" value="1"
                <?php checked( get_option( 'crce_enable_jsonld', 1 ), 1 ); ?> />
            Output structured data (<code>application/ld+json</code>)
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

// Rate Limiting
private function is_rate_limited( $ip ) {

    $key = 'crce_rate_' . md5( $ip );

    $count = (int) get_transient( $key );

    if ( $count >= 5 ) {
        return true;
    }

    set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

    return false;
}

private function get_user_ip() {

    foreach ( [ 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
        if ( ! empty( $_SERVER[ $key ] ) ) {
            return sanitize_text_field( explode( ',', $_SERVER[ $key ] )[0] );
        }
    }

    return '0.0.0.0';
}

}

/**
 * Bootstrap plugin
 */
new CRCE_Plugin();