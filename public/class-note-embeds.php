<?php
/**
 * Render-time inline URL embeds for note post content.
 *
 * Appends oEmbed cards for plain-text URLs at display time only; stored
 * post content remains byte-faithful to the source Nostr event.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Nostr_Note_Embeds {

    /**
     * Single instance.
     */
    private static $instance = null;

    /**
     * Get single instance.
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        add_filter('the_content', array($this, 'append_inline_embeds'), 9);
    }

    /**
     * Whether inline URL embeds are enabled in settings.
     */
    public static function is_enabled() {
        $options = get_option('nostr_for_wp_options', array());
        $enabled = !isset($options['embed_inline_urls']) || !empty($options['embed_inline_urls']);

        /**
         * Whether this plugin appends inline embeds to notes. Themes that
         * render their own embed cards (e.g. Wonder Burrow) return false.
         *
         * @param bool $enabled Setting value.
         */
        return (bool) apply_filters('nfw_note_embeds_enabled', $enabled);
    }

    /**
     * Append oEmbed cards for embeddable plain-text URLs in note content.
     *
     * @param string $content Filtered post content.
     * @return string
     */
    public function append_inline_embeds($content) {
        if (is_admin() || is_feed() || !self::is_enabled()) {
            return $content;
        }

        $post = get_post();
        if (!$post || $post->post_type !== 'note') {
            return $content;
        }

        $urls = $this->extract_plain_text_urls($content);
        if (empty($urls)) {
            return $content;
        }

        global $wp_embed;

        $embeds_html = '';
        $seen        = array();

        foreach ($urls as $url) {
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            $url = apply_filters('nfw_note_embed_url', $url, $post);
            if (!$url || !$this->is_oembeddable($url)) {
                continue;
            }

            $html = $wp_embed->shortcode(array(), $url);
            if (!$this->is_successful_embed($html)) {
                continue;
            }

            $embeds_html .= '<figure class="nfw-note-embed">' . $html . '</figure>';
        }

        if ($embeds_html === '') {
            return $content;
        }

        return $content . $embeds_html;
    }

    /**
     * Extract http(s) URLs that are still plain text in rendered content.
     *
     * Skips href attributes and existing embed markup; matches visible inline
     * URLs including those auto-linked by make_clickable.
     *
     * @param string $content HTML content.
     * @return string[] Unique URLs in document order.
     */
    private function extract_plain_text_urls($content) {
        $pattern = '/(?<!href=")(?<!href=\')(?<!">)(https?:\/\/[^\s<>"\'\]]+)/i';

        if (!preg_match_all($pattern, $content, $matches)) {
            return array();
        }

        $urls = array();
        foreach ($matches[1] as $raw_url) {
            $url = $this->normalize_url($raw_url);
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Strip trailing punctuation often adjacent to inline URLs in note text.
     *
     * @param string $url Raw matched URL.
     * @return string
     */
    private function normalize_url($url) {
        $url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
        $url = rtrim($url, '.,;:!?)\\]\'"');
        return esc_url_raw($url, array('http', 'https')) ?: '';
    }

    /**
     * Whether WordPress has an oEmbed provider for this URL.
     *
     * @param string $url Normalized URL.
     * @return bool
     */
    private function is_oembeddable($url) {
        if (!function_exists('_wp_oembed_get_object')) {
            return false;
        }
        // Registered providers only; no discovery request at render time.
        return (bool) _wp_oembed_get_object()->get_provider($url, array('discover' => false));
    }

    /**
     * Whether embed HTML is a real card (not a fallback plain link).
     *
     * @param string $html Embed shortcode output.
     * @return bool
     */
    private function is_successful_embed($html) {
        return (stripos($html, '<blockquote') !== false || stripos($html, '<iframe') !== false);
    }
}
