<?php
/**
 * Plugin Name: WritePost Posts (Starter)
 * Description: Displays published WritePost posts using the [writepost_posts] shortcode.
 * Version: 0.1.0
 */
if (!defined('ABSPATH')) { exit; }
add_action('admin_menu', function () {
    add_options_page('WritePost', 'WritePost', 'manage_options', 'writepost', 'writepost_settings_page');
});
add_action('admin_init', function () {
    register_setting('writepost', 'writepost_origin', array('type'=>'string', 'sanitize_callback'=>'esc_url_raw'));
    register_setting('writepost', 'writepost_key', array('type'=>'string', 'sanitize_callback'=>'sanitize_text_field'));
});
function writepost_settings_page() {
    if (!current_user_can('manage_options')) { return; }
    echo '<div class="wrap"><h1>WritePost</h1><form method="post" action="options.php">';
    settings_fields('writepost');
    echo '<p><label>Platform HTTPS URL<br><input type="url" name="writepost_origin" class="regular-text" value="'.esc_attr(get_option('writepost_origin', get_option('trywryter_origin', ''))).'" required></label></p>';
    echo '<p><label>Project read-only API key<br><input type="password" autocomplete="off" name="writepost_key" class="regular-text" value="'.esc_attr(get_option('writepost_key', get_option('trywryter_key', ''))).'" required></label></p>';
    submit_button();
    echo '</form><p>Add [writepost_posts] to a page. Posts remain in WritePost; this does not import WordPress posts or configure SEO.</p></div>';
}
function writepost_request($suffix) {
    $origin = untrailingslashit(get_option('writepost_origin', get_option('trywryter_origin', '')));
    $key = get_option('writepost_key', get_option('trywryter_key', ''));
    if (wp_parse_url($origin, PHP_URL_SCHEME) !== 'https' || !$key) { return null; }
    $response = wp_safe_remote_get($origin.'/api/v1/projects/blogs'.$suffix, array('timeout'=>15, 'redirection'=>0, 'headers'=>array('X-API-KEY'=>$key)));
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { return null; }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    return is_array($data) ? $data : null;
}
function writepost_image($url, $alt, $caption) {
    if (!$url) { return ''; }
    return '<figure><img style="max-width:100%;height:auto" src="'.esc_url($url).'" alt="'.esc_attr($alt).'" loading="lazy"><figcaption>'.esc_html($caption).'</figcaption></figure>';
}
$writepost_shortcode = function () {
    $slug = isset($_GET['twpost']) && is_string($_GET['twpost']) ? sanitize_title(wp_unslash($_GET['twpost'])) : '';
    $base = get_permalink();
    if ($slug) {
        $data = writepost_request('/'.rawurlencode($slug));
        if (!$data || empty($data['blog'])) { return '<p>This post is unavailable.</p>'; }
        $post = $data['blog'];
        $html = '<article><a href="'.esc_url($base).'">← All posts</a><h2>'.esc_html($post['title']).'</h2><p>'.esc_html($post['author_name']).'</p>';
        $html .= writepost_image($post['cover_image_url'] ?? '', $post['cover_image_alt'] ?? '', $post['cover_image_caption'] ?? '');
        $html .= wp_kses_post($post['content']);
        foreach (($post['sections'] ?? array()) as $section) {
            $html .= writepost_image($section['image_url'] ?? '', $section['image_alt'] ?? '', $section['image_caption'] ?? '');
            $html .= wp_kses_post($section['text_content']);
        }
        return $html.'</article>';
    }
    $page = isset($_GET['twpage']) && is_scalar($_GET['twpage']) ? max(1, absint($_GET['twpage'])) : 1;
    $data = writepost_request('?per_page=12&page='.$page);
    if (!$data || !isset($data['blogs'])) { return '<p>Posts are temporarily unavailable.</p>'; }
    $html = '<div class="writepost-posts">';
    foreach ($data['blogs'] as $post) {
        $html .= '<article><h2><a href="'.esc_url(add_query_arg('twpost', $post['slug'], $base)).'">'.esc_html($post['title']).'</a></h2><p>'.esc_html($post['excerpt']).'</p></article>';
    }
    if (!$data['blogs']) { $html .= '<p>No posts published yet.</p>'; }
    if ($data['meta']['current_page'] > 1) { $html .= '<a href="'.esc_url(add_query_arg('twpage', $data['meta']['current_page']-1, $base)).'">← Previous</a> '; }
    if ($data['meta']['current_page'] < $data['meta']['last_page']) { $html .= '<a href="'.esc_url(add_query_arg('twpage', $data['meta']['current_page']+1, $base)).'">Next →</a>'; }
    return $html.'</div>';

};
add_shortcode('writepost_posts', $writepost_shortcode);
// Keep existing shortcode placements working after upgrading the starter.
add_shortcode('trywryter_posts', $writepost_shortcode);
