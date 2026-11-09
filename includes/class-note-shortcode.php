<?php
/**
 * Shortcode for displaying notes
 * 
 * Provides a shortcode to display notes anywhere on the site
 * 
 * @package Nostr_For_WP
 */

if (!defined('ABSPATH')) {
    exit;
}

class Nostr_Note_Shortcode {
    
    /**
     * Single instance
     */
    private static $instance = null;
    
    /**
     * Get single instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        add_shortcode('nostr_note', array($this, 'render_note_shortcode'));
        add_shortcode('nostr_notes', array($this, 'render_notes_shortcode'));
        
        // Enqueue styles when shortcodes are present
        add_action('wp_enqueue_scripts', array($this, 'enqueue_shortcode_styles'));
    }
    
    /**
     * Enqueue styles when shortcodes might be used
     */
    public function enqueue_shortcode_styles() {
        global $post;
        
        $should_enqueue = false;
        
        // Check if current post/page contains our shortcodes
        if (is_a($post, 'WP_Post')) {
            if (has_shortcode($post->post_content, 'nostr_note') || has_shortcode($post->post_content, 'nostr_notes')) {
                $should_enqueue = true;
            }
        }
        
        // Also check for blocks that might render notes
        if (!$should_enqueue && is_a($post, 'WP_Post')) {
            if (has_blocks($post->post_content)) {
                $blocks = parse_blocks($post->post_content);
                foreach ($blocks as $block) {
                    if (isset($block['blockName']) && 
                        ($block['blockName'] === 'nostr-for-wp/notes' || $block['blockName'] === 'nostr-for-wp/note')) {
                        $should_enqueue = true;
                        break;
                    }
                }
            }
        }
        
        // Enqueue CSS when shortcodes or blocks are detected
        if ($should_enqueue) {
            wp_enqueue_style(
                'nostr-for-wp-frontend',
                NOSTR_FOR_WP_PLUGIN_URL . 'public/css/nostr-frontend.css',
                array(),
                NOSTR_FOR_WP_VERSION
            );
        }
    }
    
    /**
     * Render a single note by ID
     * 
     * Usage: [nostr_note id="123"]
     */
    public function render_note_shortcode($atts) {
        $atts = shortcode_atts(array(
            'id' => 0,
            'class' => '',
        ), $atts, 'nostr_note');
        
        $note_id = intval($atts['id']);
        if (!$note_id) {
            return '';
        }
        
        $note = get_post($note_id);
        if (!$note || $note->post_type !== 'note') {
            return '';
        }
        
        $extra_class = !empty($atts['class']) ? ' ' . esc_attr($atts['class']) : '';
        
        ob_start();
        ?>
        <article id="post-<?php echo esc_attr($note_id); ?>" class="nostr-note nostr-note-shortcode<?php echo $extra_class; ?>">
            <?php
            setup_postdata($note);
            ?>
            <div class="nostr-note-content">
                <?php echo apply_filters('the_content', $note->post_content); ?>
            </div>
            <?php if (Nostr_Frontend_Display::show_note_provenance()) : ?>
            <div class="nostr-note-meta">
                <time datetime="<?php echo esc_attr(get_the_date('c', $note_id)); ?>">
                    <?php echo esc_html(get_the_date('l, F j, Y \a\t g:i', $note_id)); ?>
                </time>
            </div>
            <?php endif; ?>
            <?php wp_reset_postdata(); ?>
        </article>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render multiple notes
     * 
     * Usage: [nostr_notes limit="10" orderby="date" order="DESC"]
     */
    public function render_notes_shortcode($atts) {
        $atts = shortcode_atts(array(
            'limit' => 10,
            'orderby' => 'date',
            'order' => 'DESC',
            'class' => '',
        ), $atts, 'nostr_notes');
        
        $args = array(
            'post_type' => 'note',
            'posts_per_page' => intval($atts['limit']),
            'orderby' => sanitize_key($atts['orderby']),
            'order' => strtoupper($atts['order']) === 'ASC' ? 'ASC' : 'DESC',
            'post_status' => 'publish',
        );
        
        $notes_query = new WP_Query($args);
        
        if (!$notes_query->have_posts()) {
            return '<p>' . esc_html__('No notes found.', 'burrowsync-for-nostr') . '</p>';
        }
        
        $extra_class = !empty($atts['class']) ? ' ' . esc_attr($atts['class']) : '';
        
        ob_start();
        ?>
        <div class="nostr-notes-shortcode<?php echo $extra_class; ?>">
            <?php
            while ($notes_query->have_posts()) :
                $notes_query->the_post();
                ?>
                <article id="post-<?php the_ID(); ?>" class="nostr-note">
                    <div class="nostr-note-content">
                        <?php the_content(); ?>
                    </div>
                    <?php if (Nostr_Frontend_Display::show_note_provenance()) : ?>
                    <div class="nostr-note-meta">
                        <time datetime="<?php echo esc_attr(get_the_date('c')); ?>">
                            <?php echo esc_html(get_the_date('l, F j, Y \a\t g:i')); ?>
                        </time>
                    </div>
                    <?php endif; ?>
                </article>
                <?php
            endwhile;
            wp_reset_postdata();
            ?>
        </div>
        <?php
        return ob_get_clean();
    }
}

