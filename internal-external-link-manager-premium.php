<?php
/**
 * Plugin Name: Internal & External Link Manager - Premium
 * Plugin URI: https://beeclear.pl/en/internal-external-link-manager/
 * Description: Turn keywords into smart, automatic links—drive traffic to key posts/pages and trusted external URLs. Fine-grained rule controls (regex, case, title, aria, rel, class, per-page cap, post-type targeting), custom priorities, clear overview tables, import/export, and design tweaks. Auto-rebuild on save and one-click Rebuild index with a summary.
 * Version: 1.7.5
 * Author: BeeClear
 * Author URI: https://beeclear.pl/en/
 * Text Domain: internal-external-link-manager-premium
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'BeeClear_ILM', false ) ) :

class BeeClear_ILM {

    /**
     * Tracks whether admin CSS has already been injected to avoid duplicates.
     *
     * @var bool
     */
    private $admin_css_printed = false;
    private $autolink_timing_ms = 0.0;
    private $collect_external_matches = false;
    private $external_matches_log = array();
    private $last_rebuild_error = '';

    /**
     * Cached author note HTML for admin headers.
     *
     * @var string|null
     */
    private $author_note = null;

    /**
     * Cached HTML for the token tips helper block.
     *
     * @var string|null
     */
    private $token_tips_html = null;

    /**
     * Basename of the free plugin to coordinate activation rules.
     */
    const BASE_PLUGIN = 'internal-external-link-manager/internal-external-link-manager.php';

    /**
     * Transient name used to queue admin notices across redirects.
     */
    const NOTICE_TRANSIENT = 'beeclear_ilm_premium_notices';

    // === Options / keys ===
    const OPT_SETTINGS        = 'beeclear_ilm_settings';
    const OPT_INDEX           = 'beeclear_ilm_index';          // compiled internal rules index
    const OPT_EXT_RULES       = 'beeclear_ilm_external_rules'; // external rules (UI table)
    const OPT_LINKMAP         = 'beeclear_ilm_linkmap';        // runtime link counters (internal)
    const OPT_EXTERNAL_MAP    = 'beeclear_ilm_external_map';   // scan results for external linking
    const OPT_OVERVIEW_SCAN   = 'beeclear_ilm_overview_scan';  // background scan state for overview
    const OPT_OVERVIEW_SCAN_SUMMARY = 'beeclear_ilm_overview_scan_summary'; // last overview scan summary
    const OPT_ACTIVITY_LOG    = 'beeclear_ilm_activity_log';   // recent maintenance/scan logs
    const OPT_DBVER           = 'beeclear_ilm_db_version';     // migration marker
    const META_RULES          = '_beeclear_ilm_rules';         // per-post internal rules
    const META_ALLOWED_TAGS   = '_beeclear_ilm_allowed_tags';  // per-post tag whitelist for matching
    const META_CONTEXT_FLAG   = '_beeclear_ilm_context_flag';  // per-post flag to enable context words
    const META_NO_OUT         = '_beeclear_ilm_no_outgoing';   // per-post flag: block outgoing autolinks
    const META_MAX_PER_TARGET = '_beeclear_ilm_max_per_target';// per-post override: max links per target
    const META_TARGET_PRIORITY= '_beeclear_ilm_target_priority';// per-post priority: higher = first to link
    const NONCE               = 'beeclear_ilm_nonce';
    const VERSION             = '1.7.5';

    public function __construct() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        register_uninstall_hook(__FILE__, array('BeeClear_ILM','uninstall'));

        add_action('init', array($this, 'maybe_upgrade'), 20);

        add_action('admin_menu', array($this, 'admin_menu'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this,'settings_link'));
        add_action('admin_init', array($this, 'register_settings'));

        add_action('add_meta_boxes', array($this, 'add_metabox'));

        // ZAPIS FRAZ — ostrożnie (autosave/revision/REST/bulk)
        add_action('save_post', array($this, 'save_post_rules'));

        add_action('before_delete_post', array($this, 'on_post_delete'));
        add_action('trashed_post', array($this, 'on_post_delete'));

        add_action('save_post', array($this, 'trigger_full_rebuild_after_save'), 99, 3);
        add_action('transition_post_status', array($this, 'on_status_change'), 10, 3);

        add_filter('the_content', array($this, 'autolink_content'), 9999);
        add_filter('the_excerpt', array($this, 'autolink_excerpt'), 9999);
        add_filter('widget_text', array($this, 'autolink_widget_text'), 9999);
        add_filter('widget_text_content', array($this, 'autolink_widget_text'), 9999);

        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('admin_head', array($this, 'admin_head_fallback_css'));
        add_action('wp_ajax_beeclear_ilm_expand_sources', array($this, 'ajax_expand_sources'));
        add_action('wp_ajax_beeclear_ilm_start_overview_scan', array($this, 'ajax_start_overview_scan'));
        add_action('wp_ajax_beeclear_ilm_step_overview_scan', array($this, 'ajax_step_overview_scan'));
        add_action('wp_ajax_beeclear_ilm_fetch_logs', array($this, 'ajax_fetch_logs'));

        add_action('wp_enqueue_scripts', array($this, 'frontend_assets'));
        add_action('wp_footer', array($this, 'render_timing_log_script'));

        add_action('admin_init', array($this,'register_admin_columns'));

        add_action('admin_init', array($this, 'disable_free_version_if_active'));
        add_action('admin_init', array($this, 'maybe_block_free_plugin_activation'));
        add_filter('plugin_action_links_' . self::BASE_PLUGIN, array($this, 'maybe_replace_free_plugin_actions'));
        add_action('admin_notices', array($this, 'render_premium_admin_notices'));
    }

    public function activate(){
        $defaults = array(
            'rel'                   => 'nofollow',
            'title_mode'            => 'phrase',
            'title_custom'          => '',
            'aria_mode'             => 'phrase',
            'aria_custom'           => '',
            'default_class'         => 'beeclear-ilm-link',
            'max_per_target'        => 1,
            'max_total_per_page'    => 0,
            'process_post_types'    => array('post','page'),
            'min_content_length'    => 200,
            'min_element_length'    => 20,
            'link_template'         => '<a href="{url}"{rel}{title}{aria}{class}>{text}</a>',
            'clean_on_uninstall'    => false,
            'clean_on_deactivation' => false,
            'process_on_archives'   => false,
            'skip_elements_internal'=> '',
            'skip_elements_external'=> '',
            'cross_inline'          => false,
            'log_internal_timing'   => false,
            'auto_scan_on_save'     => false,
            'auto_scan_on_external' => false,
            'activity_log_limit'    => '',
        );
        add_option(self::OPT_SETTINGS, $defaults, '', false);
        add_option(self::OPT_INDEX, array(), '', false);
        add_option(self::OPT_EXT_RULES, array(), '', false);
        add_option(self::OPT_LINKMAP, array(), '', false);
        add_option(self::OPT_EXTERNAL_MAP, array(), '', false);
        add_option(self::OPT_OVERVIEW_SCAN_SUMMARY, array(), '', false);
        add_option(self::OPT_ACTIVITY_LOG, array(), '', false);
        add_option(self::OPT_DBVER, self::VERSION, '', false);
        $this->rebuild_index();

        $this->disable_free_version_if_active();
    }

    public function deactivate(){
        $settings = get_option(self::OPT_SETTINGS, array());
        if ( ! empty($settings['clean_on_deactivation']) ){
            update_option(self::OPT_INDEX, array(), false);
            update_option(self::OPT_LINKMAP, array(), false);
            update_option(self::OPT_EXTERNAL_MAP, array(), false);
        }
    }

    public static function uninstall(){
        $settings = get_option(self::OPT_SETTINGS, array());
        if ( ! empty($settings['clean_on_uninstall']) ){
            delete_option(self::OPT_SETTINGS);
            delete_option(self::OPT_INDEX);
            delete_option(self::OPT_EXT_RULES);
            delete_option(self::OPT_LINKMAP);
            delete_option(self::OPT_EXTERNAL_MAP);
            delete_option(self::OPT_DBVER);
            if ( class_exists('WP_Query') ) {
                $q = new WP_Query(array(
                    'post_type'      => get_post_types(array('public'=>true),'names'),
                    'post_status'    => 'any',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                ));
                    if ( ! is_wp_error($q) && ! empty($q->posts) ) {
                        foreach($q->posts as $pid){
                            delete_post_meta($pid, self::META_RULES);
                            delete_post_meta($pid, self::META_NO_OUT);
                            delete_post_meta($pid, self::META_MAX_PER_TARGET);
                            delete_post_meta($pid, self::META_ALLOWED_TAGS);
                            delete_post_meta($pid, self::META_CONTEXT_FLAG);
                            delete_post_meta($pid, self::META_TARGET_PRIORITY);
                        }
                    }
                }
            }
        }

    public function maybe_upgrade(){
        if ( ! is_admin() ) return;
        if ( (defined('DOING_AJAX') && DOING_AJAX) || (defined('REST_REQUEST') && REST_REQUEST) ) return;
        if ( ! current_user_can('manage_options') ) return;
        if ( apply_filters('beeclear_ilm_skip_migration', defined('BEECLEAR_ILM_SKIP_MIGRATION') && BEECLEAR_ILM_SKIP_MIGRATION) ) return;

        $dbver = get_option(self::OPT_DBVER, '');
        if ( version_compare($dbver ?: '0.0.0', self::VERSION, '>=' ) ) return;

        if ( version_compare($dbver ?: '0.0.0', '1.4.0', '<' ) ) {
            if ( $dbver !== '1.4.0-migrating' ) {
                update_option(self::OPT_DBVER, '1.4.0-migrating', false);
            }
            $settings = get_option(self::OPT_SETTINGS, array());
            if ( isset($settings['case_sensitive']) ) unset($settings['case_sensitive']);
            if ( isset($settings['allow_regex']) )    unset($settings['allow_regex']);
            if ( ! isset($settings['skip_elements_internal']) ) $settings['skip_elements_internal'] = '';
            if ( ! isset($settings['skip_elements_external']) ) $settings['skip_elements_external'] = '';
            if ( ! isset($settings['cross_inline']) )            $settings['cross_inline'] = false;
            update_option(self::OPT_SETTINGS, $settings, false);

            try {
                $post_types = get_post_types(array('public' => true), 'names');
                if ( empty($post_types) ) $post_types = array('post','page');
                $batch  = 500; $offset = 0;
                do {
                    $q = new WP_Query(array(
                        'post_type'        => $post_types,
                        'post_status'      => 'any',
                        'fields'           => 'ids',
                        'posts_per_page'   => $batch,
                        'offset'           => $offset,
                        'no_found_rows'    => true,
                        'orderby'          => 'ID',
                        'order'            => 'ASC',
                    ));
                    if ( is_wp_error($q) ) break;
                    $ids = (array) $q->posts;
                    foreach ($ids as $pid){
                        $rules = get_post_meta($pid, self::META_RULES, true);
                        if ( !is_array($rules) ) continue;
                        $changed = false;
                        foreach ($rules as &$r){
                            if ( !is_array($r) ) { $r = array('phrase' => (string)$r); $changed = true; }
                            if ( ! array_key_exists('regex', $r) ) { $r['regex'] = !empty($r['regex']); $changed = true; }
                            if ( ! array_key_exists('case',  $r) ) { $r['case']  = false; $changed = true; }
                        }
                        if ( $changed ) update_post_meta($pid, self::META_RULES, $rules);
                    }
                    $offset += $batch;
                } while ( !empty($ids) && count($ids) === $batch );
            } catch (\Throwable $e) {
                if ( defined('WP_DEBUG') && WP_DEBUG ) {
                    $this->log_activity('[BeeClear ILM] Migration 1.4.0 failed: ' . $e->getMessage());
                }
                update_option(self::OPT_DBVER, '1.4.0-failed', false);
                return;
            }
            $this->rebuild_index();
        }

        update_option(self::OPT_DBVER, self::VERSION, false);
    }

    public function settings_link($links){
        $url = admin_url('admin.php?page=beeclear-ilm');
        $links[] = '<a href="'.esc_url($url).'">'.esc_html__('Settings', 'internal-external-link-manager-premium').'</a>';
        return $links;
    }

    public function admin_menu(){
        $cap = 'manage_options';
        $title = __('Internal & External Link Manager', 'internal-external-link-manager-premium');
        add_menu_page($title,$title,$cap,'beeclear-ilm',array($this,'render_dashboard'),'dashicons-admin-links',59);
        add_submenu_page('beeclear-ilm', __('Global settings', 'internal-external-link-manager-premium'), __('Global settings', 'internal-external-link-manager-premium'), $cap, 'beeclear-ilm', array($this,'render_dashboard'));
        add_submenu_page('beeclear-ilm', __('External linking', 'internal-external-link-manager-premium'), __('External linking', 'internal-external-link-manager-premium'), $cap, 'beeclear-ilm-external', array($this,'render_external'));
        add_submenu_page('beeclear-ilm', __('Linking Overview', 'internal-external-link-manager-premium'), __('Linking Overview', 'internal-external-link-manager-premium'), $cap, 'beeclear-ilm-internal-overview', array($this,'render_internal_overview'));
        add_submenu_page('beeclear-ilm', __('Import/Export', 'internal-external-link-manager-premium'), __('Import/Export', 'internal-external-link-manager-premium'), $cap, 'beeclear-ilm-impex', array($this,'render_impex'));
    }

    public function register_settings(){
        register_setting('beeclear_ilm_group', self::OPT_SETTINGS, array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_settings'),
            'default' => array(),
        ));
    }

    public function sanitize_settings($in){
        $out = array();
        $out['rel']                   = isset($in['rel']) ? sanitize_text_field($in['rel']) : '';
        $out['title_mode']            = in_array(($in['title_mode'] ?? 'phrase'), array('none','phrase','post_title','custom'), true) ? $in['title_mode'] : 'phrase';
        $out['title_custom']          = isset($in['title_custom']) ? sanitize_text_field($in['title_custom']) : '';
        $out['aria_mode']             = in_array(($in['aria_mode'] ?? 'phrase'), array('none','phrase','post_title','custom'), true) ? $in['aria_mode'] : 'phrase';
        $out['aria_custom']           = isset($in['aria_custom']) ? sanitize_text_field($in['aria_custom']) : '';
        $out['default_class']         = isset($in['default_class']) ? sanitize_html_class($in['default_class']) : '';
        $out['max_per_target']        = max(0, intval($in['max_per_target'] ?? 1));
        $out['max_total_per_page']    = max(0, intval($in['max_total_per_page'] ?? 0));
        $out['process_post_types']    = array_values(array_filter(array_map('sanitize_key', (array)($in['process_post_types'] ?? array('post','page')))));
        $out['min_content_length']    = max(0, intval($in['min_content_length'] ?? 200));
        $out['min_element_length']    = max(0, intval($in['min_element_length'] ?? 20));
        $tpl                          = isset($in['link_template']) ? (string)$in['link_template'] : '<a href="{url}"{rel}{title}{aria}{class}>{text}</a>';
        $out['link_template']         = (strpos($tpl, '{url}') !== false && strpos($tpl, '{text}') !== false) ? $tpl : '<a href="{url}"{rel}{title}{aria}{class}>{text}</a>';
        $out['clean_on_uninstall']    = !empty($in['clean_on_uninstall']);
        $out['clean_on_deactivation'] = !empty($in['clean_on_deactivation']);
        $out['process_on_archives']   = !empty($in['process_on_archives']);
        $out['auto_scan_on_save']     = !empty($in['auto_scan_on_save']);

        $tags_i = $this->parse_tag_list($in['skip_elements_internal'] ?? '');
        $tags_e = $this->parse_tag_list($in['skip_elements_external'] ?? '');
        $out['skip_elements_internal'] = implode(', ', $tags_i);
        $out['skip_elements_external'] = implode(', ', $tags_e);

        $out['cross_inline'] = !empty($in['cross_inline']);
        $out['log_internal_timing'] = !empty($in['log_internal_timing']);
        $out['auto_scan_on_external'] = !empty($in['auto_scan_on_external']);

        $log_limit_raw = isset($in['activity_log_limit']) ? trim((string) $in['activity_log_limit']) : '';
        $out['activity_log_limit'] = $log_limit_raw === '' ? '' : max(1, intval($log_limit_raw));

        return $out;
    }

    private function sanitize_external_rules($raw_rules){
        $clean = array();
        foreach ((array) $raw_rules as $r) {
            if ( ! is_array($r) ) continue;

            $phrase_raw = $r['phrase'] ?? '';
            $url_raw    = $r['url']    ?? '';
            $phrase = is_string($phrase_raw) ? trim(wp_unslash($phrase_raw)) : '';
            $url    = is_string($url_raw)    ? esc_url_raw(trim(wp_unslash($url_raw))) : '';
            if ( $phrase === '' || $url === '' ) continue;

            $regex = ! empty($r['regex']);
            $case  = ! empty($r['case']) && ! $regex;
            $rel   = sanitize_text_field($r['rel'] ?? '');

            $context_raw   = isset($r['context']) ? $r['context'] : '';
            if (is_array($context_raw)) {
                $context_raw = implode(', ', array_map('trim', $context_raw));
            }
            $context_str   = is_string($context_raw) ? (string) wp_unslash($context_raw) : '';
            $context_clean = trim($context_str);
            $context_terms = array();
            $context_parts = preg_split('/[,\n]+/', $context_clean);
            foreach ((array) $context_parts as $cp){
                $cp = trim($cp);
                if ($cp !== '') $context_terms[] = $cp;
            }
            $context = $context_terms;

            $context_regex = ! empty($r['context_regex']);
            $context_case  = ! empty($r['context_case']) && ! $context_regex;

            $allowed_tags_raw = is_string($r['allowed_tags'] ?? null) ? $r['allowed_tags'] : '';
            $allowed_tags = $this->parse_tag_list($allowed_tags_raw);
            $allowed_tags_clean = implode(', ', $allowed_tags);

            $title_mode   = in_array(($r['title_mode'] ?? 'phrase'), array('none','phrase','custom'), true) ? $r['title_mode'] : 'phrase';
            $title_custom = sanitize_text_field($r['title_custom'] ?? '');
            $aria_mode    = in_array(($r['aria_mode'] ?? 'phrase'), array('none','phrase','custom'), true) ? $r['aria_mode'] : 'phrase';
            $aria_custom  = sanitize_text_field($r['aria_custom'] ?? '');
            $class        = sanitize_html_class($r['class'] ?? '');
            $max_per_page = max(0, intval($r['max_per_page'] ?? 1));
            $types        = array_values(array_filter(array_map('sanitize_key', (array)($r['types'] ?? array()))));

            $exclude_ids_raw = is_string($r['exclude_ids'] ?? null) ? $r['exclude_ids'] : '';
            $exclude_ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,;]+/', $exclude_ids_raw)), function($id){ return $id > 0; })));

            $clean[] = compact('phrase','regex','case','context','context_regex','context_case','url','rel','title_mode','title_custom','aria_mode','aria_custom','class','max_per_page','types','exclude_ids') + array(
                'allowed_tags' => $allowed_tags_clean,
            );
        }

        return $clean;
    }

    private function parse_tag_list($str){
        $str = is_string($str) ? strtolower($str) : '';
               $str = str_replace(array("\n","\r","\t"), ' ', $str);
        $parts = preg_split('/[,\;\|\s\/\-]+/', $str);
        $out = array();
        foreach ((array)$parts as $p){
            $p = trim($p);
            if ($p === '') continue;
            if (preg_match('/^[a-z][a-z0-9]*$/', $p)) $out[$p] = true;
        }
        return array_keys($out);
    }

    private function node_in_tags($node, $tags){
        if (empty($tags)) return false;
        for ($n = $node->parentNode; $n; $n = $n->parentNode){
            $nn = strtolower($n->nodeName);
            if (in_array($nn, $tags, true)) return true;
        }
        return false;
    }

    private function get_text_for_node_element($node){
        $element = $this->get_closest_element($node);
        if ($element && isset($element->textContent)){
            return (string) $element->textContent;
        }
        return (string) $node->nodeValue;
    }

    private function get_closest_element($node){
        $element = $node;
        while ($element && $element->nodeType !== XML_ELEMENT_NODE){
            $element = $element->parentNode;
        }
        return $element instanceof DOMElement ? $element : null;
    }

    private function get_element_outer_html($node){
        $element = $this->get_closest_element($node);
        if (!($element instanceof DOMElement) || !($element->ownerDocument instanceof DOMDocument)) {
            return '';
        }
        $html = $element->ownerDocument->saveHTML($element);
        return is_string($html) ? trim($html) : '';
    }

    private function trim_html_fragment($html, $limit = 8000){
        $html = is_string($html) ? trim($html) : '';
        if ($html === '') return '';
        if ($limit <= 0) return $html;
        if (function_exists('mb_strlen')){
            if (mb_strlen($html, 'UTF-8') > $limit){
                return mb_substr($html, 0, $limit, 'UTF-8') . '…';
            }
            return $html;
        }
        if (strlen($html) > $limit){
            return substr($html, 0, $limit) . '…';
        }
        return $html;
    }

    private function store_link_context(&$contexts, $target, $phrase, $node, $manual = null){
        $phrase = is_string($phrase) ? trim($phrase) : '';
        if ($phrase === '') return;
        $html = $this->trim_html_fragment($this->get_element_outer_html($node));
        if ($html === '') return;
        $tag  = '';
        $element = $this->get_closest_element($node);
        if ($element instanceof DOMElement){
            $tag = strtolower($element->tagName);
        }
        if (!is_bool($manual)){
            $is_manual = strpos($html, 'beeclear-ilm-link') === false;
        } else {
            $is_manual = $manual;
        }

        if (!isset($contexts[$target]) || !is_array($contexts[$target])){
            $contexts[$target] = array();
        }
        $contexts[$target][] = array(
            'phrase' => $phrase,
            'html'   => $html,
            'tag'    => $tag,
            'manual' => $is_manual,
        );
    }

    private function merge_contexts($existing, $new, $max = 20){
        $merged = array();
        $seen = array();
        foreach (array_merge((array)$existing, (array)$new) as $ctx){
            if (!is_array($ctx)) continue;
            $phrase = isset($ctx['phrase']) ? (string)$ctx['phrase'] : '';
            $html   = isset($ctx['html'])   ? (string)$ctx['html']   : '';
            $tag    = isset($ctx['tag'])    ? (string)$ctx['tag']    : '';
            $manual = !empty($ctx['manual']);
            if ($phrase === '' && $html === '') continue;
            $key = md5($phrase.'|'.$html);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $merged[] = array(
                'phrase' => $phrase,
                'html'   => $html,
                'tag'    => $tag,
                'manual' => $manual,
            );
            if (count($merged) >= $max) break;
        }
        return $merged;
    }

    private function group_contexts_by_phrase($contexts){
        $grouped = array();
        foreach ((array)$contexts as $ctx){
            if (!is_array($ctx)) continue;
            $phrase = isset($ctx['phrase']) ? (string)$ctx['phrase'] : '';
            $html   = isset($ctx['html']) ? (string)$ctx['html'] : '';
            if ($phrase === '' || $html === '') continue;
            if (!isset($grouped[$phrase])) $grouped[$phrase] = array();
            $grouped[$phrase][] = array(
                'html'   => $html,
                'tag'    => isset($ctx['tag']) ? (string)$ctx['tag'] : '',
                'manual' => !empty($ctx['manual']),
            );
        }
        return $grouped;
    }

    private function sum_source_counts($sources){
        $total = 0;
        foreach ((array) $sources as $info){
            if (is_array($info)){
                $total += (int) ($info['count'] ?? 0);
            } else {
                $total += (int) $info;
            }
        }
        return $total;
    }

    private function format_context_html_for_popup($html, $tag){
        if ($tag === 'li'){
            return '<ul class="beeclear-ilm-context-list-preview">'.$html.'</ul>';
        }
        return $html;
    }

    private function unique_popup_id($base, &$registry){
        $base = is_string($base) ? trim($base) : '';
        if ($base === '') return '';
        if (!isset($registry[$base])){
            $registry[$base] = 1;
            return $base;
        }
        $registry[$base]++;
        return $base.'-'.$registry[$base];
    }

    private function collect_overview_scan_ids($post_types){
        $ids = array();

        if ( function_exists('wp_sitemaps_get_server') ) {
            $server = wp_sitemaps_get_server();
            if ( $server ) {
                $provider = null;

                if ( method_exists($server, 'get_provider') ) {
                    $provider = $server->get_provider('posts');
                } elseif ( property_exists($server, 'registry') && is_object($server->registry) && method_exists($server->registry, 'get_provider') ) {
                    $provider = $server->registry->get_provider('posts');
                }

                if ( $provider ) {
                    $subtypes = $provider->get_object_subtypes();
                    foreach ( (array) $subtypes as $subtype ) {
                        if ( ! in_array($subtype, $post_types, true) ) continue;
                        $page = 1;
                        do {
                            $urls = $provider->get_url_list($page, $subtype);
                            if ( empty($urls) ) break;
                            foreach ( $urls as $entry ) {
                                if ( empty($entry['loc']) ) continue;
                                $pid = url_to_postid($entry['loc']);
                                if ( $pid ) {
                                    $ids[(int) $pid] = true;
                                }
                            }
                            $page++;
                        } while ( ! empty($urls) );
                    }
                }
            }
        }

        if ( empty($ids) ) {
            if ( ! class_exists('WP_Query') ) return array();
            $q = new WP_Query(array(
                'post_type'      => $post_types,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ));
            if ( ! is_wp_error($q) ) {
                foreach ( (array) $q->posts as $pid ) {
                    $ids[(int) $pid] = true;
                }
            }
        }

        $ids = array_keys($ids);
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function scan_single_post_for_overview($post_id){
        $post = get_post($post_id);
        if ( ! $post || $post->post_status !== 'publish' ) return;

        $settings = get_option(self::OPT_SETTINGS, array());
        $pts = !empty($settings['process_post_types']) ? (array)$settings['process_post_types'] : array('post','page');
        if ( ! in_array($post->post_type, $pts, true) ) return;

        $content = get_post_field('post_content', $post);
        if ( ! is_string($content) || $content === '' ) return;

        $previous_post = isset($GLOBALS['post']) ? $GLOBALS['post'] : null;
        $GLOBALS['post'] = $post;
        setup_postdata($post);

        add_filter('beeclear_ilm_allow_admin_processing', '__return_true');
        $this->collect_external_matches = true;
        $this->external_matches_log = array();
        $this->autolink_content($content, true);
        $this->collect_external_matches = false;
        $this->persist_external_matches_for_post((int) $post->ID);
        remove_filter('beeclear_ilm_allow_admin_processing', '__return_true');

        wp_reset_postdata();
        $GLOBALS['post'] = $previous_post;
    }

    private function persist_external_matches_for_post($post_id){
        if ( empty($this->external_matches_log) ) return;

        $map = get_option(self::OPT_EXTERNAL_MAP, array());
        foreach ($this->external_matches_log as $entry){
            $idx = isset($entry['rule_idx']) ? (int)$entry['rule_idx'] : -1;
            $count = isset($entry['count']) ? (int)$entry['count'] : 0;
            if ($idx < 0 || $count <= 0) continue;

            $phrase = isset($entry['phrase']) ? (string)$entry['phrase'] : '';
            $url    = isset($entry['url']) ? (string)$entry['url'] : '';

            if (!isset($map[$idx])){
                $map[$idx] = array('count' => 0, 'sources' => array(), 'phrase' => $phrase, 'url' => $url);
            }
            $map[$idx]['count'] += $count;
            if ($phrase !== '') $map[$idx]['phrase'] = $phrase;
            if ($url !== '') $map[$idx]['url'] = $url;
            if ($post_id){
                $existing = $map[$idx]['sources'][$post_id] ?? 0;
                $existing_count   = is_array($existing) ? (int)($existing['count'] ?? 0) : (int)$existing;
                $existing_phrases = is_array($existing) ? (array)($existing['phrases'] ?? array()) : array();
                $existing_contexts = is_array($existing) ? (array)($existing['contexts'] ?? array()) : array();

                $phrases_for_rule = isset($entry['phrases']) ? (array)$entry['phrases'] : array();
                foreach ($phrases_for_rule as $ph => $pc){
                    $existing_phrases[(string)$ph] = ($existing_phrases[(string)$ph] ?? 0) + (int)$pc;
                }

                $contexts_for_rule = isset($entry['contexts']) ? (array)$entry['contexts'] : array();
                $merged_contexts = $this->merge_contexts($existing_contexts, $contexts_for_rule);

                $map[$idx]['sources'][$post_id] = array(
                    'count'    => $existing_count + $count,
                    'phrases'  => $existing_phrases,
                    'contexts' => $merged_contexts,
                );
            }
        }

        update_option(self::OPT_EXTERNAL_MAP, $map, false);
        $this->external_matches_log = array();
    }

    private function process_overview_scan_batch($batch_size = 5){
        $state = get_option(self::OPT_OVERVIEW_SCAN, array());
        $ids = isset($state['ids']) && is_array($state['ids']) ? $state['ids'] : array();
        $total = isset($state['total']) ? (int)$state['total'] : count($ids);
        $processed = isset($state['processed']) ? (int)$state['processed'] : 0;

        if ( empty($ids) || $processed >= $total ) {
            delete_option(self::OPT_OVERVIEW_SCAN);
            return array('done' => true, 'processed' => $processed, 'total' => $total);
        }

        $limit = max(1, (int) $batch_size);
        for ( $i = 0; $i < $limit && $processed < $total; $i++ ) {
            $pid = isset($ids[$processed]) ? (int) $ids[$processed] : 0;
            if ( $pid > 0 ) {
                $this->scan_single_post_for_overview($pid);
            }
            $processed++;
        }

        $state['processed'] = $processed;
        $state['total'] = $total;
        update_option(self::OPT_OVERVIEW_SCAN, $state, false);

            $done = $processed >= $total;
            if ( $done ) {
                delete_option(self::OPT_OVERVIEW_SCAN);
                $summary = $this->build_scan_summary($total, isset($state['started_at']) ? (int)$state['started_at'] : 0);
                $this->store_scan_summary($summary);
                $this->log_activity(sprintf(
                    /* translators: 1: scanned pages count, 2: internal links count, 3: external links count. */
                    __('Scan finished: %1$d pages, %2$d internal links, %3$d external links.', 'internal-external-link-manager-premium'),
                    (int) $summary['scanned'],
                    (int) $summary['internal_links'],
                    (int) $summary['external_links']
                ));
            }

        return array('done' => $done, 'processed' => $processed, 'total' => $total);
    }

    private function build_scan_summary($scanned, $started_at = 0){
        $map = get_option(self::OPT_LINKMAP, array());
        $internal_links = 0;
        if ( is_array($map) ) {
            foreach ( $map as $entry ) {
                if ( is_array($entry) && isset($entry['count']) ) {
                    $internal_links += (int) $entry['count'];
                }
            }
        }

        $external_map = get_option(self::OPT_EXTERNAL_MAP, array());
        $external_links = 0;
        if ( is_array($external_map) ) {
            foreach ( $external_map as $entry ) {
                if ( is_array($entry) && isset($entry['count']) ) {
                    $external_links += (int) $entry['count'];
                }
            }
        }

        $completed_at = current_time('timestamp');

        return array(
            'completed_at'   => (int) $completed_at,
            'started_at'     => $started_at ? (int) $started_at : (int) $completed_at,
            'scanned'        => (int) $scanned,
            'internal_links' => (int) $internal_links,
            'external_links' => (int) $external_links,
        );
    }

    private function store_scan_summary($summary){
        if ( ! is_array($summary) ) return;
        update_option(self::OPT_OVERVIEW_SCAN_SUMMARY, $summary, false);
    }

    private function get_scan_summary(){
        $summary = get_option(self::OPT_OVERVIEW_SCAN_SUMMARY, array());
        if ( ! is_array($summary) ) {
            $summary = array();
        }
        $defaults = array('completed_at' => 0, 'started_at' => 0, 'scanned' => 0, 'internal_links' => 0, 'external_links' => 0);
        return wp_parse_args($summary, $defaults);
    }

    private function log_activity($message){
        $entry = array(
            'time'    => current_time('timestamp'),
            'message' => (string) $message,
        );

        $log = get_option(self::OPT_ACTIVITY_LOG, array());
        if ( ! is_array($log) ) {
            $log = array();
        }

        array_unshift($log, $entry);

        $settings = get_option(self::OPT_SETTINGS, array());
        $max_entries_setting = $settings['activity_log_limit'] ?? '';
        if ($max_entries_setting !== '') {
            $max_entries_setting = max(1, (int) $max_entries_setting);
            $log = array_slice($log, 0, $max_entries_setting);
        }

        update_option(self::OPT_ACTIVITY_LOG, $log, false);
    }

    private function get_activity_log($limit = 100){
        $log = get_option(self::OPT_ACTIVITY_LOG, array());
        if ( ! is_array($log) ) {
            return array();
        }

        $limit = max(1, (int) $limit);
        return array_slice($log, 0, $limit);
    }

    private function get_activity_log_page($page = 1, $per_page = 50){
        $log = get_option(self::OPT_ACTIVITY_LOG, array());
        if ( ! is_array($log) ) {
            return array('entries' => array(), 'total' => 0);
        }

        $per_page = max(1, (int) $per_page);
        $page = max(1, (int) $page);
        $total = count($log);
        $offset = ($page - 1) * $per_page;

        return array(
            'entries' => array_slice($log, $offset, $per_page),
            'total'   => $total,
        );
    }

    private function context_patterns_match($text, $patterns){
        if (!is_string($text) || $text === '') return false;
        foreach ((array) $patterns as $pat){
            if (@preg_match($pat, $text) !== 1) return false;
        }
        return true;
    }

    private function is_format_tag($name){
        $name = strtolower($name);
        return in_array($name, array('b','strong','i','em','u','mark'), true);
    }

    public function admin_assets($hook){
        wp_enqueue_script('jquery');
        if ( wp_script_is('jquery-ui-sortable', 'registered') ) {
            wp_enqueue_script('jquery-ui-sortable');
        }

        $L = array(
            'phrase_or_regex' => __('Phrase or regex', 'internal-external-link-manager-premium'),
            'regex'           => __('Regex', 'internal-external-link-manager-premium'),
            'case_sensitive'  => __('Case-sensitive', 'internal-external-link-manager-premium'),
            'remove'          => __('Remove', 'internal-external-link-manager-premium'),
            'erase'           => __('Erase', 'internal-external-link-manager-premium'),
            'title'           => __('Title', 'internal-external-link-manager-premium'),
            'aria_label'      => __('Aria-label', 'internal-external-link-manager-premium'),
            'custom_title'    => __('Custom title', 'internal-external-link-manager-premium'),
            'custom_aria'     => __('Custom aria-label', 'internal-external-link-manager-premium'),
            'max_page'        => __('Max/page', 'internal-external-link-manager-premium'),
            'zero_unlimited'  => __('0 = unlimited', 'internal-external-link-manager-premium'),
            'context_words'   => __('Context words', 'internal-external-link-manager-premium'),
            'context_tokens'  => __('Supports token syntax (non-regex). Separate multiple entries with commas.', 'internal-external-link-manager-premium'),
            'context_placeholder' => __('Additional words required in the same element', 'internal-external-link-manager-premium'),
            'scan_failed'     => __('Scan failed.', 'internal-external-link-manager-premium'),
            'scan_done'       => __('Scan finished. Overview updated.', 'internal-external-link-manager-premium'),
            'scan_prepare'    => __('Preparing scan…', 'internal-external-link-manager-premium'),
            'scan_unable'     => __('Unable to start scan.', 'internal-external-link-manager-premium'),
            'scan_empty'      => __('Nothing to scan.', 'internal-external-link-manager-premium'),
            'scan_running'    => __('Overview scan in progress…', 'internal-external-link-manager-premium'),
        );
        wp_add_inline_script('jquery', 'window.BeeClearILM = window.BeeClearILM || {}; BeeClearILM.i18n = '.wp_json_encode($L).'; BeeClearILM.nonce = "'.wp_create_nonce(self::NONCE).'"; BeeClearILM.settingsUrl = "'.esc_url(admin_url('admin.php?page=beeclear-ilm')).'";', 'before');

        $js = <<<'JS'
jQuery(function($){
    var L = (window.BeeClearILM && BeeClearILM.i18n) ? BeeClearILM.i18n : {};

    // Metabox (internal rules)
    var $list = $("#beeclear-ilm-rules-list");
    if($list.length && $.fn.sortable){ $list.sortable({handle:".handle"}); }
    function toggleContextFields(enabled){
        $(".ilm-context-field").toggleClass("hidden", !enabled);
    }

    toggleContextFields($("#beeclear-ilm-context-toggle").prop("checked"));

    $("#beeclear-ilm-context-toggle").on("change", function(){
        toggleContextFields(this.checked);
    });

    $("#beeclear-ilm-add").on("click", function(e){
        e.preventDefault();
        var idx = $(".ilm-row").length;
        var ctxHidden = $("#beeclear-ilm-context-toggle").prop("checked") ? '' : ' hidden';
        var row = '<div class="ilm-row"><span class="handle" aria-hidden="true">☰</span>'
            + '<div class="ilm-rule-fields">'
            +   '<div class="ilm-field-group">'
            +     '<div class="ilm-field-controls">'
            +       '<input type="text" name="beeclear_ilm_rules['+idx+'][phrase]" class="regular-text" placeholder="'+(L.phrase_or_regex||'Phrase or regex')+'" aria-label="'+(L.phrase_or_regex||'Phrase or regex')+'">'
            +       '<div class="ilm-field-flags">'
            +         '<label><input type="checkbox" class="ilm-regex" name="beeclear_ilm_rules['+idx+'][regex]" value="1"> '+(L.regex||'Regex')+'</label>'
            +         '<label><input type="checkbox" class="ilm-case" name="beeclear_ilm_rules['+idx+'][case]" value="1"> '+(L.case_sensitive||'Case-sensitive')+'</label>'
            +         '<a href="#" class="button link-delete">'+(L.remove||'Remove')+'</a>'
            +       '</div>'
            +     '</div>'
            +   '</div>'
            +   '<div class="ilm-context-field'+ctxHidden+'">'
            +     '<div class="ilm-field-controls">'
            +       '<input type="text" name="beeclear_ilm_rules['+idx+'][context]" class="regular-text" placeholder="'+(L.context_placeholder||'Additional words required in the same element')+'" aria-label="'+(L.context_words||'Context words')+'">'
            +       '<div class="ilm-field-flags">'
            +         '<label><input type="checkbox" class="ilm-context-regex" name="beeclear_ilm_rules['+idx+'][context_regex]" value="1"> '+(L.regex||'Regex')+'</label>'
            +         '<label><input type="checkbox" class="ilm-context-case" name="beeclear_ilm_rules['+idx+'][context_case]" value="1"> '+(L.case_sensitive||'Case-sensitive')+'</label>'
            +         '<a href="#" class="button button-secondary ilm-context-erase">'+(L.erase||'Erase')+'</a>'
            +       '</div>'
            +     '</div>'
            +   '</div>'
            + '</div>'
            + '</div>';
        $list.append(row);
    });
    $(document).on("click",".ilm-row .link-delete", function(e){ e.preventDefault(); $(this).closest(".ilm-row").remove(); });
    function syncCaseToggle($regexBox, $caseBox){
        var isRegex = $regexBox.is(":checked");
        $caseBox.prop("disabled", isRegex);
        if (isRegex){ $caseBox.prop("checked", false); }
    }
    $(document).on("change",".ilm-regex", function(){
        syncCaseToggle($(this), $(this).closest(".ilm-row").find(".ilm-case"));
    });
    $(document).on("change",".ilm-context-regex", function(){
        syncCaseToggle($(this), $(this).closest(".ilm-row").find(".ilm-context-case"));
    });
    $(document).on("click", ".ilm-context-erase", function(e){
        e.preventDefault();
        var $field = $(this).closest('.ilm-context-field');
        $field.find('input[type="text"]').val('');
        var $regex = $field.find('.ilm-context-regex');
        var $case = $field.find('.ilm-context-case');
        $regex.prop('checked', false);
        $case.prop('checked', false).prop('disabled', false);
    });
    $(".ilm-regex, .ilm-context-regex").trigger("change");

    // External table
    var $ext = $("#beeclear-ilm-ext-table tbody");
    $("#beeclear-ilm-ext-add").on("click", function(e){
        e.preventDefault();
        var idx = $("#beeclear-ilm-ext-table tbody tr").length;
        var types = $("#beeclear-ilm-ext-types-template").html() || "";
        var urlId = 'beeclear-ilm-ext-'+idx+'-url';
        var phraseId = 'beeclear-ilm-ext-'+idx+'-phrase';
        var contextId = 'beeclear-ilm-ext-'+idx+'-context';
        var row = '<tr>'
          + '<td class=\"cell-phrase\">'
          +   '<div class=\"ext-field ext-destination\"><label class=\"ext-destination-label\" for=\"'+urlId+'\">'+(L.destination_url||'Destination URL')+'</label>'
          +   '<input type=\"url\" id=\"'+urlId+'\" name=\"beeclear_ilm_ext['+idx+'][url]\" class=\"regular-text\" placeholder=\"https://...\"></div>'
          +   '<div class=\"ext-field ext-phrase\"><label class=\"ext-phrase-label\" for=\"'+phraseId+'\">'+(L.phrase_or_regex||'Phrase or regex')+'</label>'
          +   '<input type=\"text\" id=\"'+phraseId+'\" name=\"beeclear_ilm_ext['+idx+'][phrase]\" class=\"regular-text\" placeholder=\"'+(L.phrase_or_regex||'Phrase or regex')+'\"></div>'
          +   '<div class=\"flags\"><label><input type=\"checkbox\" class=\"ext-regex\" name=\"beeclear_ilm_ext['+idx+'][regex]\" value=\"1\"> '+(L.regex||'Regex')+'</label>'
          +   '<label><input type=\"checkbox\" class=\"ext-case\" name=\"beeclear_ilm_ext['+idx+'][case]\" value=\"1\"> '+(L.case_sensitive||'Case-sensitive')+'</label></div>'
          +   '<div class=\"ext-field ext-context\"><label class=\"ext-context-label\" for=\"'+contextId+'\">'+(L.context_words||'Context words')+'</label>'
          +   '<input type=\"text\" id=\"'+contextId+'\" name=\"beeclear_ilm_ext['+idx+'][context]\" class=\"regular-text\" placeholder=\"'+(L.context_placeholder||'Additional words required in the same element')+'\">'
          +   '<div class=\"flags\"><label><input type=\"checkbox\" class=\"ext-context-regex\" name=\"beeclear_ilm_ext['+idx+'][context_regex]\" value=\"1\"> '+(L.regex||'Regex')+'</label>'
          +   '<label><input type=\"checkbox\" class=\"ext-context-case\" name=\"beeclear_ilm_ext['+idx+'][context_case]\" value=\"1\">'+(L.case_sensitive||'Case-sensitive')+'</label></div><p class=\"description\">'+(L.context_tokens||'Supports token syntax (non-regex). Separate multiple entries with commas.')+'</p></div></td>'
          + '<td class=\"cell-attrs\"><div class=\"attr-rows\">'
          +     '<div class=\"ar\"><label class=\"ar-label\">rel</label><div class=\"ar-field\"><input type=\"text\" name=\"beeclear_ilm_ext['+idx+'][rel]\" class=\"regular-text\" placeholder=\"nofollow noopener\"></div></div>'
          +     '<div class=\"ar\"><label class=\"ar-label\">'+(L.title||'Title')+'</label><div class=\"ar-field\"><div class=\"inline-field\"><select name=\"beeclear_ilm_ext['+idx+'][title_mode]\"><option value=\"none\">none</option><option value=\"phrase\">phrase</option><option value=\"custom\">custom</option></select></div><div class=\"inline-field\"><label class=\"screen-reader-text\">'+(L.custom_title||'Custom title')+'</label><input type=\"text\" name=\"beeclear_ilm_ext['+idx+'][title_custom]\" class=\"regular-text\" placeholder=\"'+(L.custom_title||'Custom title')+'\"></div></div></div>'
          +     '<div class=\"ar\"><label class=\"ar-label\">'+(L.aria_label||'Aria-label')+'</label><div class=\"ar-field\"><div class=\"inline-field\"><select name=\"beeclear_ilm_ext['+idx+'][aria_mode]\"><option value=\"none\">none</option><option value=\"phrase\">phrase</option><option value=\"custom\">custom</option></select></div><div class=\"inline-field\"><label class=\"screen-reader-text\">'+(L.custom_aria||'Custom aria-label')+'</label><input type=\"text\" name=\"beeclear_ilm_ext['+idx+'][aria_custom]\" class=\"regular-text\" placeholder=\"'+(L.custom_aria||'Custom aria-label')+'\"></div></div></div>'
          +     '<div class=\"ar\"><label class=\"ar-label\">CSS class</label><div class=\"ar-field\"><input type=\"text\" name=\"beeclear_ilm_ext['+idx+'][class]\" class=\"regular-text\" placeholder=\"beeclear-ilm-link\"></div></div>'
          + '</div></td>'
          + '<td class=\"cell-types\">'+types.replace(/__IDX__/g, idx)+'</td>'
          + '<td class=\"cell-actions\"><a href=\"#\" class=\"button ext-delete\">'+(L.remove||'Remove')+'</a></td>'
          + '</tr>';
        $ext.append(row);
    });
    $(document).on('click','.ext-delete', function(e){ e.preventDefault(); $(this).closest('tr').remove(); });
    $(document).on('change','.ext-regex', function(){
        var row = $(this).closest('tr');
        row.find('.ext-case').prop('disabled', this.checked).prop('checked', this.checked ? false : row.find('.ext-case').prop('checked'));
    });
    $(document).on('change','.ext-context-regex', function(){
        var row = $(this).closest('tr');
        row.find('.ext-context-case').prop('disabled', this.checked).prop('checked', this.checked ? false : row.find('.ext-context-case').prop('checked'));
    });
    $('.ext-regex').trigger('change');
    $('.ext-context-regex').trigger('change');

    var scanMessages = {
        failed: L.scan_failed || 'Scan failed.',
        done: L.scan_done || 'Scan finished. Overview updated.',
        prepare: L.scan_prepare || 'Preparing scan…',
        unable: L.scan_unable || 'Unable to start scan.',
        empty: L.scan_empty || 'Nothing to scan.'
    };
    var scanNonce = (window.BeeClearILM && BeeClearILM.nonce) ? BeeClearILM.nonce : '',
        settingsUrl = (window.BeeClearILM && BeeClearILM.settingsUrl) ? BeeClearILM.settingsUrl : '';

    var $scanBtn = $('#beeclear-ilm-start-overview-scan'),
        $scanProgress = $('#beeclear-ilm-progress'),
        $scanBar = $('#beeclear-ilm-progress .beeclear-progress__bar'),
        $scanLabel = $('#beeclear-ilm-progress .beeclear-progress__label'),
        $scanSummary = $('#beeclear-ilm-scan-summary');

    if($scanBtn.length){
        var scanRunning = false,
            scanTotal = 0,
            idleText = $scanLabel.text();

        setProgressVisibility(false);

        function setProgressVisibility(isVisible){
            if(isVisible){
                $scanProgress.removeAttr('hidden').css('display', 'block');
            } else {
                $scanProgress.attr('hidden', 'hidden').css('display', 'none');
            }
        }

        function toggleAdminbarScan(running){
            var $menu = $('#wp-admin-bar-root-default');
            if(!$menu.length) return;
            var $item = $('#wp-admin-bar-beeclear-ilm-scan');
            if(running){
                if(!$item.length){
                    $item = $('<li>', {id: 'wp-admin-bar-beeclear-ilm-scan'}).append(
                        $('<a>', {class: 'ab-item', href: settingsUrl || '#', text: L.scan_running || 'Overview scan in progress…'})
                    );
                    $menu.append($item);
                } else {
                    $item.find('a').text(L.scan_running || 'Overview scan in progress…');
                }
            } else {
                $item.remove();
            }
        }

        function resetProgress(){
            $scanBar.css('width', '0%');
            $scanLabel.text(idleText);
        }

        function finalizeScan(msg){
            scanRunning = false;
            $scanBtn.prop('disabled', false);
            if(msg){
                $scanLabel.text(msg);
            }
            toggleAdminbarScan(false);
            setProgressVisibility(true);
        }

        function updateScanStatus(processed){
            var percent = scanTotal ? Math.round((processed / scanTotal) * 100) : 0;
            percent = Math.max(0, Math.min(100, percent));
            setProgressVisibility(true);
            $scanBar.css('width', percent + '%');
            if(!scanTotal){
                $scanLabel.text(idleText);
                return;
            }
            $scanLabel.text(percent + '% — ' + processed + '/' + scanTotal);
        }

        function scanFail(msg){
            finalizeScan(msg || idleText);
        }

        function runScanStep(){
            $.post(ajaxurl,{action:'beeclear_ilm_step_overview_scan', batch:5, _ajax_nonce: scanNonce}, function(resp){
                if(!resp || !resp.success){
                    scanFail(resp && resp.data && resp.data.message ? resp.data.message : scanMessages.failed);
                    return;
                }
                var data = resp.data || {};
                scanTotal = data.total || scanTotal;
                updateScanStatus(data.processed || 0);
                if(!data.done){
                    setTimeout(runScanStep, 300);
                } else {
                    $scanLabel.text(scanMessages.done);
                    if(data.summary_html && $scanSummary.length){
                        $scanSummary.html(data.summary_html);
                    }
                    finalizeScan();
                }
            }).fail(function(){
                scanFail(scanMessages.failed);
            });
        }

        $scanBtn.on('click', function(e){
            e.preventDefault();
            if(scanRunning) return;
            scanRunning = true;
            scanTotal = 0;
            $scanBtn.prop('disabled', true);
            resetProgress();
            $scanLabel.text(scanMessages.prepare);
            setProgressVisibility(true);
            toggleAdminbarScan(true);

            $.post(ajaxurl,{action:'beeclear_ilm_start_overview_scan', _ajax_nonce: scanNonce}, function(resp){
                if(!resp || !resp.success){
                    scanFail(resp && resp.data && resp.data.message ? resp.data.message : scanMessages.unable);
                    return;
                }
                scanTotal = resp.data && resp.data.total ? resp.data.total : 0;
                if(!scanTotal){
                    scanFail(scanMessages.empty);
                    return;
                }
                updateScanStatus(0);
                runScanStep();
            }).fail(function(){
                scanFail(scanMessages.unable);
            });
        });
    }

    var $exportArea = $('#beeclear-ilm-export-json');
    $('#beeclear-ilm-export-download').on('click', function(e){
        e.preventDefault();
        if(!$exportArea.length) return;
        var blob = new Blob([$exportArea.val()], {type:'application/json'});
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = 'beeclear-ilm-export.json';
        document.body.appendChild(link);
        link.click();
        setTimeout(function(){ URL.revokeObjectURL(url); link.remove(); }, 200);
    });

    $('#beeclear-ilm-import-file').on('change', function(){
        var file = this.files && this.files[0];
        if(!file) return;
        var reader = new FileReader();
        reader.onload = function(evt){
            $('#beeclear-ilm-import-json').val(evt.target.result);
        };
        reader.readAsText(file);
    });

    var $logWrap = $('#beeclear-ilm-log');
    if($logWrap.length){
        var logNonce = (window.BeeClearILM && BeeClearILM.nonce) ? BeeClearILM.nonce : '';
        var logTimer;

        function replaceLogs(data){
            if(data.entries_html){
                var $box = $logWrap.find('.beeclear-card__logs');
                if($box.length){
                    $box.replaceWith(data.entries_html);
                } else {
                    $logWrap.prepend(data.entries_html);
                }
            }
            if(typeof data.pagination_html !== 'undefined'){
                var $pagination = $logWrap.find('.beeclear-log-pagination');
                if($pagination.length){
                    $pagination.replaceWith(data.pagination_html);
                } else {
                    $logWrap.append(data.pagination_html);
                }
            }
            if(typeof data.page !== 'undefined'){
                $logWrap.attr('data-current-page', data.page);
            }
            if(typeof data.total_pages !== 'undefined'){
                $logWrap.attr('data-total-pages', data.total_pages);
            }
        }

        function fetchLogs(page){
            $.post(ajaxurl,{action:'beeclear_ilm_fetch_logs', _ajax_nonce: logNonce, page: page}, function(resp){
                if(resp && resp.success && resp.data){
                    replaceLogs(resp.data);
                }
            });
        }

        $logWrap.on('click', '.beeclear-log-pagination button[data-log-page]', function(){
            var targetPage = parseInt($(this).data('log-page'), 10) || 1;
            fetchLogs(targetPage);
        });

        function scheduleLogRefresh(){
            clearInterval(logTimer);
            logTimer = setInterval(function(){
                var currentPage = parseInt($logWrap.attr('data-current-page'), 10) || 1;
                fetchLogs(currentPage);
            }, 10000);
        }

        scheduleLogRefresh();
    }

    var activeContext = null;

    function closeContextPopups(){
        $(".beeclear-ilm-context-popup").attr("hidden", true);
        $(".beeclear-ilm-context-btn").attr("aria-expanded", false);
        activeContext = null;
    }

    function positionContextPopup($btn, $popup){
        if(!$btn.length || !$popup.length || $popup.attr('hidden')) return;
        var rect = $btn[0].getBoundingClientRect();
        var popupRect = $popup[0].getBoundingClientRect();
        var viewportWidth = $(window).width();
        var viewportHeight = $(window).height();
        var left = rect.left;
        var top = rect.bottom + 8;
        var maxLeft = viewportWidth - popupRect.width - 12;
        var maxTop = viewportHeight - popupRect.height - 12;
        if (popupRect.width > viewportWidth) {
            left = 8;
        } else {
            left = Math.max(8, Math.min(left, maxLeft));
        }
        top = Math.max(8, Math.min(top, maxTop));
        $popup.css({left:left, top:top, right:"auto", bottom:"auto"});
    }

    $(document).on('click', '.beeclear-ilm-context-btn', function(e){
        e.preventDefault();
        var target = $(this).attr('data-target');
        if(!target) return;
        var $popup = $("#"+target);
        if(!$popup.length) return;
        var isOpen = !$popup.attr('hidden');
        closeContextPopups();
        if(!isOpen){
            $popup.removeAttr('hidden');
            $(this).attr('aria-expanded', true);
            activeContext = {btn: $(this), popup: $popup};
            positionContextPopup($(this), $popup);
        }
    });

    $(document).on('click', function(e){
        if($(e.target).closest('.beeclear-ilm-context-popup, .beeclear-ilm-context-btn').length) return;
        closeContextPopups();
    });

    $(window).on('scroll resize', function(){
        if(activeContext && activeContext.popup && !activeContext.popup.attr('hidden')){
            positionContextPopup(activeContext.btn, activeContext.popup);
        }
    });

    $(document).on('keydown', function(e){
        if(e.key === 'Escape'){
            closeContextPopups();
        }
    });
});
JS;
        wp_add_inline_script('jquery', $js);

        if ( wp_style_is('common', 'registered') ) {
            wp_enqueue_style('common');
            wp_add_inline_style('common', $this->admin_css_block());
            $this->admin_css_printed = true;
        }
    }

    public function admin_head_fallback_css(){
        if ( $this->admin_css_printed ) {
            return;
        }
        $this->admin_css_printed = true;
        printf('<style>%s</style>', esc_html($this->admin_css_block()));
    }
    private function admin_css_block(){
        return '
            /* Global */
            .ilm-wrap {display: flex; gap: 18px; flex-direction: column;}
            .wrap .form-table th{vertical-align:middle;padding:14px 10px 14px 0;font-weight:600;color:#1d2327;width:260px;}
            .wrap .form-table td{padding:12px 0;display:flex;flex-direction:column;gap:8px;}
            .wrap .form-table td .description, .wrap .form-table td .inline-help{display:block;opacity:.75;line-height:1.2}
            .wrap .form-table td input.regular-text,
            .wrap .form-table td select{max-width:400px;width: 100%;}
            .wrap .form-table td textarea{max-width:100%}
            .wrap .form-table td input[type="number"]{width:140px;}
			
            /* Inline tips */
            .beeclear-inline-info{display:flex;gap:12px;align-items:flex-start;border:1px solid #dfe4f2;border-left:4px solid #3858e9;border-radius:12px;padding:12px 14px;background:linear-gradient(120deg,#f7f9ff,#ffffff);box-shadow:0 8px 20px rgba(0,0,0,.05);margin:12px 0;}
            .beeclear-inline-info__icon{width:34px;height:34px;border-radius:10px;background:#ebf0ff;display:flex;align-items:center;justify-content:center;color:#3858e9;flex-shrink:0;}
            .beeclear-inline-info__body{display:flex;flex-direction:column;gap:6px;}
            .beeclear-inline-info__title{margin:0;font-weight:700;color:#1d2327;font-size:14px;}
            .beeclear-inline-info__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;}
            .beeclear-inline-info__label{font-weight:600;color:#1d2327;margin-bottom:4px;}
            .beeclear-inline-info__list{margin:6px 0 0 18px;padding:0;list-style:disc;line-height:1.5;}

            /* Cards / Grid for Global settings */
            .beeclear-grid{display:grid;grid-template-columns:1fr;gap:18px;margin-top:8px;max-width:100%}
            .beeclear-grid--with-sidebar{align-items:start}
            @media (min-width: 992px){
                .beeclear-grid--with-sidebar{grid-template-columns:2fr 1fr;gap:20px}
            }
			.beeclear-grid p.submit{margin: 0px;padding: 0px;}
            .beeclear-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,.06);max-width:100%}
            .beeclear-card h2{margin-top:0}
            .beeclear-actions{display:flex;gap:12px;flex-direction:column;align-items:flex-start;margin:6px 0 0;width:100%}
            .beeclear-actions__buttons{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
            .beeclear-actions__row{display:flex;flex-wrap:wrap;align-items:center;gap:10px;width:100%}
            .beeclear-actions__buttons .button{width:auto;max-width:320px}
            .beeclear-inline-action{margin:0}
            .beeclear-actions .beeclear-progress{margin-top:0;max-width:480px;width:100%}
            .beeclear-progress--inline{display:flex;align-items:center;gap:10px;height:32px;padding:6px 12px;width:auto;min-width:260px}
            .beeclear-progress--inline .beeclear-progress__track{height:8px;margin:0}
            .beeclear-progress--inline .beeclear-progress__label{margin:0}
            .beeclear-inline-form{display:flex;flex-direction:column;gap:10px;width:100%}
            .beeclear-form{max-width:none;width:100%;display:flex;flex-direction:column;gap:12px}
            .ilm-section-title{display:flex;align-items:center;gap:8px;margin:0 0 12px}
            .ilm-section-title .dashicons{color:#50575e;font-size:20px;height:20px;width:20px}
            .ilm-field-grid{display:grid;gap:10px 14px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}
            .ilm-checkbox-grid{display:flex;flex-wrap:wrap;gap:8px 16px;margin-top:6px}
            .ilm-checkbox{display:flex;gap:6px;align-items:center;font-weight:500}
            details > summary{cursor:pointer}
            details.ilm-tips{background:#f6f7f7;border:1px solid #e2e4e7;border-radius:6px;padding:10px 12px}
            details.ilm-tips .description{margin-top:8px}

            /* Metabox settings */
            .ilm-metabox-settings-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;margin:12px 0}
            .ilm-metabox-card{background:#fff;border:1px solid #e2e5ea;border-radius:10px;padding:14px 16px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
            .ilm-metabox-title{margin:0 0 10px;font-size:15px;display:flex;align-items:center;gap:6px}
            .ilm-metabox-field{display:flex;flex-direction:column;gap:6px;margin-bottom:12px}
            .ilm-metabox-label{font-weight:600;color:#1d2327}
            .ilm-metabox-checkbox{display:flex;gap:8px;align-items:center;font-weight:500}

            /* Metabox rules list */
            #beeclear-ilm-rules-list .ilm-row:first-of-type{margin-top:1rem;}
            #beeclear-ilm-rules-list .ilm-row{display:grid;grid-template-columns:40px 1fr;align-items:start;margin-bottom:12px;padding:12px 14px;border:1px solid #e2e5ea;border-radius:10px;background:#fff}
            #beeclear-ilm-rules-list .handle{cursor:move;opacity:.6;display:flex;align-items:center;justify-content:center;grid-row:1/span 2;align-self:center;padding:0;height:auto}
            #beeclear-ilm-rules-list .ilm-rule-fields{grid-column:2/3;display:flex;flex-direction:column;gap:12px}
            #beeclear-ilm-rules-list .ilm-field-group{display:flex;align-items:stretch;gap:12px;min-width:0;width:100%}
            #beeclear-ilm-rules-list .ilm-field-controls{display:flex;flex-wrap:wrap;gap:10px 12px;flex:1;min-width:0;align-items:center}
            #beeclear-ilm-rules-list .ilm-field-controls input[type="text"]{flex:1;min-width:220px}
            #beeclear-ilm-rules-list .ilm-field-flags{display:flex;flex-wrap:wrap;gap:12px;white-space:nowrap;align-items:center;width:auto;margin-left:auto;justify-content:flex-end}
            #beeclear-ilm-rules-list .ilm-field-flags label{display:flex;align-items:center;gap:6px;width:auto}
            #beeclear-ilm-rules-list .ilm-field-flags .link-delete{margin-left:4px;width:70px;text-align:center;}
            #beeclear-ilm-rules-list .ilm-context-field{display:flex;gap:12px;align-items:center;width:100%;flex-wrap:wrap}
            #beeclear-ilm-rules-list .ilm-context-field .ilm-field-controls{align-items:center;width:100%}
            #beeclear-ilm-rules-list .ilm-field-flags .ilm-context-erase{margin-left:4px;width:70px;text-align:center;}
            #beeclear-ilm-rules-list .ilm-context-field.hidden{display:none}
            @media (max-width:782px){
                .ilm-metabox-settings-grid{grid-template-columns:1fr;gap:12px;margin:10px 0}
                #beeclear-ilm-rules-list .ilm-row{grid-template-columns:1fr;align-items:stretch}
                #beeclear-ilm-rules-list .handle{grid-row:auto;width:auto}
                #beeclear-ilm-rules-list .ilm-rule-fields{grid-column:1/-1}
                #beeclear-ilm-rules-list .ilm-field-group{width:100%;flex-direction:column;gap:10px}
                #beeclear-ilm-rules-list .ilm-field-controls{width:100%}
                #beeclear-ilm-rules-list .ilm-field-controls input[type="text"]{min-width:0;width:100%}
                #beeclear-ilm-rules-list .ilm-field-flags{width:100%;justify-content:flex-start;gap:10px}
                #beeclear-ilm-rules-list .ilm-field-flags .link-delete{width:100%;max-width:180px;text-align:center;}
                #beeclear-ilm-rules-list .ilm-context-field{flex-direction:column;align-items:stretch}
            }

            /* External table base */
                        #beeclear-ilm-ext-table .ar-field{display:flex;gap:8px}
            #beeclear-ilm-ext-table{border-collapse:separate;border-spacing:0;width:100%;background:#fff;border:1px solid #e2e5ea;border-radius:8px;}
            #beeclear-ilm-ext-table th,#beeclear-ilm-ext-table td{padding:12px 10px}
            #beeclear-ilm-ext-table th{background:#f8fafc;font-weight:600}
            #beeclear-ilm-ext-table td{vertical-align:top}
            #beeclear-ilm-ext-table tr+tr td{border-top:1px solid #edf0f3}
            #beeclear-ilm-ext-table .cell-phrase input[type="text"]{width:100%}
            #beeclear-ilm-ext-table .cell-phrase .ext-field{display:flex;flex-direction:column;gap:6px;margin-bottom:10px}
            #beeclear-ilm-ext-table .cell-phrase .ext-field:last-child{margin-bottom:0}
            #beeclear-ilm-ext-table .cell-phrase label{display:block;font-weight:600;margin:0 0 4px}
            #beeclear-ilm-ext-table .cell-phrase .ext-context{margin-top:2px}
            #beeclear-ilm-ext-table .cell-phrase .description{margin:4px 0 0}
            #beeclear-ilm-ext-table .cell-url input[type="url"]{width:100%}
            #beeclear-ilm-ext-table .flags{margin-top:2px;display:flex;gap:12px;align-items:center}
            #beeclear-ilm-ext-table .max-per-page-field input[type="number"]{width:90px}
                        #beeclear-ilm-ext-table .regular-text{width:200px;max-width:100%}

            /* Rows layout for attributes */
            #beeclear-ilm-ext-table .attr-rows{display:flex;flex-direction:column;gap:10px}
            #beeclear-ilm-ext-table .attr-rows .ar{display:flex;flex-wrap:wrap;align-items:center;gap:10px}
            #beeclear-ilm-ext-table .attr-rows .ar .ar-label{width:120px;min-width:120px;font-weight:600;opacity:.9}
            #beeclear-ilm-ext-table .attr-rows .ar .ar-field{flex:1;min-width:220px;display:flex;flex-wrap:wrap;gap:8px}
            #beeclear-ilm-ext-table .attr-rows input.regular-text,
            #beeclear-ilm-ext-table .attr-rows select{width:100%;max-width:460px}
            #beeclear-ilm-ext-table .attr-rows .inline-field{min-width:180px;flex:1}
            #beeclear-ilm-ext-table .cell-types .types-stack{display:flex;flex-direction:column;gap:12px}
            #beeclear-ilm-ext-table .cell-types .field-stack{display:flex;flex-direction:column;gap:6px}
            #beeclear-ilm-ext-table .cell-types .field-label{font-weight:600}
            #beeclear-ilm-ext-table .cell-types .max-per-page-field{display:flex;align-items:center;gap:10px}
            #beeclear-ilm-ext-table .cell-types .max-label{min-width:74px;font-weight:600}
            #beeclear-ilm-ext-table .cell-types .desc{font-size:12px;opacity:.8}
            #beeclear-ilm-ext-table .cell-types .types-checklist{display:flex;flex-wrap:wrap;gap:12px;align-items:center}
            #beeclear-ilm-ext-table .ilm-types-checkbox{margin:0 12px 6px 0;display:inline-flex;align-items:center;gap:6px;font-weight:500}
            #beeclear-ilm-ext-table .cell-types input.regular-text{max-width:100%}
            @media (max-width: 782px){
            #beeclear-ilm-ext-table .attr-rows .ar{flex-direction:column;align-items:stretch}
                #beeclear-ilm-ext-table .attr-rows .ar .ar-label{width:auto;min-width:0}
            #beeclear-ilm-ext-table .attr-rows .ar .ar-field{min-width:0}
            }

            /* Overview */
            .beeclear-progress{width:100%;max-width:360px;border:1px solid #dcdcde;border-radius:6px;overflow:hidden;background:#f8f9fa;position:relative;display:flex;flex-direction:column;gap:6px;padding:8px}
            .beeclear-progress__track{width:100%;height:8px;background:#e9ecf1;border-radius:999px;overflow:hidden}
            .beeclear-progress__bar{height:100%;background:#4f46e5;width:0;transition:width .25s ease}
            .beeclear-progress__label{font-size:12px;line-height:1.4;opacity:.8}
            .beeclear-scan-summary ul{margin:8px 0 0 18px;padding:0;list-style:disc}
            .beeclear-card__logs-wrap{display:flex;flex-direction:column;gap:10px}
            .beeclear-card__logs{max-height:260px;overflow:auto;border:1px solid #e2e5ea;border-radius:8px;background:#f9fafb;padding:10px 12px;box-shadow:inset 0 1px 1px rgba(0,0,0,.02)}
            .beeclear-log-entry{padding:6px 4px;border-bottom:1px solid #e2e5ea;font-size:13px;line-height:1.4}
            .beeclear-log-entry:last-child{border-bottom:none}
            .beeclear-log-entry__time{display:block;font-size:11px;opacity:.75}
            .beeclear-log-pagination{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
            .beeclear-log-pagination__status{font-weight:600}
            .beeclear-author-note{margin-top:4px;font-size:13px;color:#3c434a;display:flex;align-items:center;gap:8px}
            .beeclear-author-note a{color:#3858e9;text-decoration:none;font-weight:600}
            .beeclear-author-note a:hover{text-decoration:underline}
            .beeclear-ilm-overview .button.button-small{height:auto;line-height:1.4;padding:2px 8px}
            .beeclear-ilm-edit .dashicons{vertical-align:middle}
            .beeclear-ilm-overview-controls{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;}
            .beeclear-ilm-view-toggle{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
            .beeclear-ilm-filter{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0}
            .beeclear-ilm-overview-meta{display:flex;align-items:center;gap:10px;margin-left:auto}
            .beeclear-ilm-overview-table-wrap{width:100%;overflow-x:auto;}
            .beeclear-ilm-overview{border-collapse:separate;border-spacing:0;width:100%;background:#fff;border:1px solid #e2e5ea;border-radius:8px;overflow:visible}
            .beeclear-ilm-overview th,.beeclear-ilm-overview td{padding:12px 10px}
            .beeclear-ilm-overview th{background:#f8fafc;font-weight:600;text-align:left}
            .beeclear-ilm-overview td{vertical-align:top}
            .beeclear-ilm-overview tr+tr td{border-top:1px solid #edf0f3}            
            .beeclear-ilm-overview .col-phrases,.beeclear-ilm-overview .col-inbound{white-space:nowrap}
            .beeclear-ilm-overview .col-sources,.beeclear-ilm-overview .col-targets{white-space:nowrap}            
            @media (max-width:782px){
                .beeclear-ilm-overview th,.beeclear-ilm-overview td{padding:10px 8px}
                .beeclear-ilm-overview .col-phrases,.beeclear-ilm-overview .col-inbound,.beeclear-ilm-overview .col-sources,.beeclear-ilm-overview .col-targets{width:auto}
            }
            .beeclear-ilm-source-item{position:relative;}
            .beeclear-ilm-source-phrase{display:inline-flex;gap:4px;align-items:center;}
            .beeclear-ilm-manual-badge{display:inline-flex;align-items:center;gap:4px;padding:0;color:#8f3900;font-size:12px;line-height:1;opacity:.8;}
            .beeclear-ilm-manual-badge .dashicons{font-size:16px;line-height:1;}
            .beeclear-ilm-context-btn{display:inline-flex;align-items:center;gap:4px;padding:0 2px;border:none;background:none;color:#3858e9;cursor:pointer;text-decoration:none;}
            .beeclear-ilm-context-btn:hover{color:#1d2327;}
            .beeclear-ilm-context-btn .dashicons{margin-top:2px;font-size:16px;}
            .beeclear-ilm-context-popup{position:fixed;z-index:9999;max-width:min(720px, calc(100vw - 40px));width:auto;min-width:260px;background:#fff;border:1px solid #dcdcde;box-shadow:0 8px 24px rgba(0,0,0,.12);border-radius:8px;padding:12px;}
            .beeclear-ilm-context-fragment{padding:8px;border-bottom:1px solid #eef0f3;}
            .beeclear-ilm-context-fragment:last-child{border-bottom:0;}
            .beeclear-ilm-context-tag{font-size:11px;text-transform:uppercase;letter-spacing:.02em;color:#50575e;margin-bottom:6px;font-weight:600;}
            .beeclear-ilm-context-html{max-height:none;overflow:visible;white-space:normal;word-break:break-word;overflow-wrap:anywhere;background:#f6f7f7;border:1px solid #e2e4e7;border-radius:6px;padding:10px;}
            .beeclear-ilm-context-list-preview{margin:0;padding-left:18px;list-style:disc;}
            @media (max-width:782px){
                .beeclear-ilm-overview{max-width:100%;}
                .beeclear-ilm-overview th,.beeclear-ilm-overview td{white-space:normal;}
                .beeclear-ilm-source-item{white-space:normal;}
            }
        ';
    }

    private function render_author_note(){
        if ($this->author_note === null){
            $this->author_note = '<p class="beeclear-author-note">'
                .'<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>'
                .'<span>'.esc_html__('Author:', 'internal-external-link-manager-premium').' <a href="https://beeclear.pl" target="_blank" rel="noopener">BeeClear</a></span>'
                .'</p>';
        }
        return $this->author_note;
    }

    private function render_token_tips_html(){
        if ($this->token_tips_html === null){
            $this->token_tips_html  = '<details class="ilm-tips"><summary>'.esc_html__('Tips: Token syntax (non-regex mode)', 'internal-external-link-manager-premium').'</summary>';
            $this->token_tips_html .= '<div class="description">';
            $this->token_tips_html .= '<ul style="list-style:disc; padding-left:18px">';
            $this->token_tips_html .= '<li><code>wordpre[string]</code>, <code>[string:5]</code>, <code>[string:max5]</code>, <code>[string:min3]</code> — '.esc_html__('letters appended to prefix (letters only, no hyphen/space).', 'internal-external-link-manager-premium').'</li>';
            $this->token_tips_html .= '<li><code>wordpress [words] plugin</code>, <code>[words:1]</code>, <code>[words:max2]</code>, <code>[words:min2]</code> — '.esc_html__('word(s) between parts, default 1–3.', 'internal-external-link-manager-premium').'</li>';
            $this->token_tips_html .= '<li>'.esc_html__('Tokens work only when "Regex" is OFF. You can mix tokens, e.g. ', 'internal-external-link-manager-premium').'<code>wordpre[string:max5] [words:max2] plug[string:max5]</code></li>';
            $this->token_tips_html .= '</ul></div></details>';
        }
        return $this->token_tips_html;
    }

    public function add_metabox(){
        $settings = get_option(self::OPT_SETTINGS, array());
        $pts = !empty($settings['process_post_types']) ? (array)$settings['process_post_types'] : array('post','page');
        foreach($pts as $pt){
            add_meta_box('beeclear_ilm_box', __('Internal Link Phrases/Rules (BeeClear)', 'internal-external-link-manager-premium'), array($this,'render_metabox'), $pt, 'normal', 'high');
        }
    }

    public function render_metabox($post){
        wp_nonce_field(self::NONCE, self::NONCE);
        echo '<input type="hidden" name="beeclear_ilm_rules_present" value="1">';
        echo '<input type="hidden" name="beeclear_ilm_rules_intent" value="update">';

        $rules = get_post_meta($post->ID, self::META_RULES, true);
        if(!is_array($rules)) $rules = array();

        $no_out = !empty(get_post_meta($post->ID, self::META_NO_OUT, true));
        $max_per_target_override = get_post_meta($post->ID, self::META_MAX_PER_TARGET, true);
        $target_priority = get_post_meta($post->ID, self::META_TARGET_PRIORITY, true);

        echo '<p>'.esc_html__('Add phrases (or regex). Order matters: top has the highest priority. Case-sensitive applies only when Regex is off.', 'internal-external-link-manager-premium').'</p>';

        echo wp_kses_post($this->render_token_tips_html());

        $allowed_tags_raw = get_post_meta($post->ID, self::META_ALLOWED_TAGS, true);
        $context_flag = !empty(get_post_meta($post->ID, self::META_CONTEXT_FLAG, true));

        echo '<div id="beeclear-ilm-rules-list">';
        $i=0;
        foreach($rules as $r){
            $phrase = isset($r['phrase']) ? $r['phrase'] : '';
            $regex  = !empty($r['regex']);
            $case   = !empty($r['case']);
            $context_regex = !empty($r['context_regex']);
            $context_case  = !empty($r['context_case']) && empty($r['context_regex']);
            $context_words = '';
            if (!empty($r['context']) && is_array($r['context'])){
                $context_words = implode(', ', array_map('trim', $r['context']));
            }
            $phrase_label = esc_html__('Phrase or regex', 'internal-external-link-manager-premium');
            $context_label = esc_html__('Context words or regex', 'internal-external-link-manager-premium');
            $index_attr = (int) $i;
            echo '<div class="ilm-row"><span class="handle" aria-hidden="true">☰</span>';
            echo '<div class="ilm-rule-fields">';
            echo '<div class="ilm-field-group">';
            echo '<div class="ilm-field-controls">';
            printf(
                '<input type="text" name="beeclear_ilm_rules[%1$s][phrase]" value="%2$s" class="regular-text" placeholder="%3$s" aria-label="%4$s">',
                esc_attr($index_attr),
                esc_attr($phrase),
                esc_attr($phrase_label),
                esc_attr($phrase_label)
            );
            echo '<div class="ilm-field-flags">';
            printf(
                '<label><input type="checkbox" class="ilm-regex" name="beeclear_ilm_rules[%1$s][regex]" value="1" %2$s> %3$s</label>',
                esc_attr($index_attr),
                checked($regex, true, false),
                esc_html__('Regex', 'internal-external-link-manager-premium')
            );
            printf(
                '<label><input type="checkbox" class="ilm-case" name="beeclear_ilm_rules[%1$s][case]" value="1" %2$s> %3$s</label>',
                esc_attr($index_attr),
                checked($case, true, false).($regex ? ' disabled' : ''),
                esc_html__('Case-sensitive', 'internal-external-link-manager-premium')
            );
            echo '<a href="#" class="button link-delete">'.esc_html__('Remove', 'internal-external-link-manager-premium').'</a>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
            echo '<div class="ilm-context-field'.($context_flag ? '' : ' hidden').'">';
            echo '<div class="ilm-field-controls">';
            printf(
                '<input type="text" name="beeclear_ilm_rules[%1$s][context]" value="%2$s" class="regular-text" placeholder="%3$s" aria-label="%4$s">',
                esc_attr($index_attr),
                esc_attr($context_words),
                esc_attr__('Additional words required in the same element', 'internal-external-link-manager-premium'),
                esc_attr($context_label)
            );
            echo '<div class="ilm-field-flags">';
            printf(
                '<label><input type="checkbox" class="ilm-context-regex" name="beeclear_ilm_rules[%1$s][context_regex]" value="1" %2$s> %3$s</label>',
                esc_attr($index_attr),
                checked($context_regex, true, false),
                esc_html__('Regex', 'internal-external-link-manager-premium')
            );
            printf(
                '<label><input type="checkbox" class="ilm-context-case" name="beeclear_ilm_rules[%1$s][context_case]" value="1" %2$s> %3$s</label>',
                esc_attr($index_attr),
                checked($context_case, true, false).($context_regex ? ' disabled' : ''),
                esc_html__('Case-sensitive', 'internal-external-link-manager-premium')
            );
            echo '<a href="#" class="button button-secondary ilm-context-erase">'.esc_html__('Erase', 'internal-external-link-manager-premium').'</a>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
            echo '</div>'; $i++;
        }
        echo '</div>';
        echo '<p><a href="#" class="button" id="beeclear-ilm-add">'.esc_html__('Add phrase/rule', 'internal-external-link-manager-premium').'</a></p>';

        echo '<div class="ilm-metabox-settings-grid">';
        echo '<div class="ilm-metabox-card">';
        echo '<h3 class="ilm-metabox-title">'.esc_html__('Search scope', 'internal-external-link-manager-premium').'</h3>';
        echo '<div class="ilm-metabox-field">';
        echo '<label class="ilm-metabox-label">'.esc_html__('Allowed HTML elements', 'internal-external-link-manager-premium').'</label>';
        echo '<input type="text" name="beeclear_ilm_allowed_elements" value="'.esc_attr($allowed_tags_raw).'" class="regular-text" placeholder="p, ul, ol" aria-describedby="ilm-allowed-elements-help">';
        echo '<p id="ilm-allowed-elements-help" class="description">'.esc_html__('Comma-separated tag names (e.g., p, ul). Leave empty to search everywhere.', 'internal-external-link-manager-premium').'</p>';
        echo '</div>';
        echo '<div class="ilm-metabox-field">';
        echo '<label class="ilm-metabox-label">'.esc_html__('Context matching', 'internal-external-link-manager-premium').'</label>';
        echo '<label class="ilm-metabox-checkbox"><input type="checkbox" id="beeclear-ilm-context-toggle" name="beeclear_ilm_context_enabled" value="1" '.checked($context_flag, true, false).'> ';
        echo esc_html__('Require extra words/phrases in the same element as the matched phrase', 'internal-external-link-manager-premium').'</label>';
        echo '<p class="description">'.esc_html__('When enabled, each phrase can specify additional words that must appear in the same HTML element (supports tokens and regex).', 'internal-external-link-manager-premium').'</p>';
        echo '</div>';
        echo '</div>';

        echo '<div class="ilm-metabox-card">';
        echo '<h3 class="ilm-metabox-title">'.esc_html__('Per-target linking rules', 'internal-external-link-manager-premium').'</h3>';
        echo '<div class="ilm-metabox-field">';
        echo '<label class="ilm-metabox-label" for="beeclear-ilm-max-per-target">'.esc_html__('Max links per INTERNAL target (this page)', 'internal-external-link-manager-premium').'</label>';
        echo '<input id="beeclear-ilm-max-per-target" type="number" min="0" name="beeclear_ilm_max_per_target" value="'.esc_attr($max_per_target_override).'" placeholder="'.esc_attr__('Use global', 'internal-external-link-manager-premium').'">';
        echo '<p class="description">'.esc_html__('Leave empty to use the global cap. 0 = unlimited for this target.', 'internal-external-link-manager-premium').'</p>';
        echo '</div>';

        echo '<div class="ilm-metabox-field">';
        echo '<label class="ilm-metabox-label" for="beeclear-ilm-target-priority">'.esc_html__('Priority of this target for internal links', 'internal-external-link-manager-premium').'</label>';
        echo '<input id="beeclear-ilm-target-priority" type="number" min="0" max="100" name="beeclear_ilm_target_priority" value="'.esc_attr($target_priority === '' ? '0' : $target_priority).'" placeholder="0">';
        echo '<p class="description">'.esc_html__('Higher number (0–100) = this post/page is linked first when limits apply. Default 0.', 'internal-external-link-manager-premium').'</p>';
        echo '</div>';

        echo '<div class="ilm-metabox-field">';
        echo '<label class="ilm-metabox-checkbox"><input type="checkbox" name="beeclear_ilm_no_outgoing" value="1" '.checked($no_out, true, false).'> ';
        echo esc_html__('Disable autolinking from this post (no outgoing links)', 'internal-external-link-manager-premium').'</label>';
        echo '<p class="description">'.esc_html__('When enabled, the plugin will not inject internal or external links into this post content.', 'internal-external-link-manager-premium').'</p>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        // echo '<p><label><input type="checkbox" name="beeclear_ilm_rules_clear" value="1"> '.
        //     esc_html__('Clear all phrases on save (explicit action)', 'internal-external-link-manager-premium').
        //     '</label><br><span class="description">'.
        //     esc_html__('If not checked, the plugin will NEVER wipe phrases because of empty/partial POST (e.g., after closing an autosave notice).', 'internal-external-link-manager-premium').
        //     '</span></p>';

        $settings_url = esc_url(admin_url('admin.php?page=beeclear-ilm'));
        $menu_name = esc_html__('Internal & External Link Manager', 'internal-external-link-manager-premium');
        echo '<hr><p><strong>'.esc_html__('Admin menu name:', 'internal-external-link-manager-premium').'</strong> '
            . '<a href="' . esc_url($settings_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($menu_name) . '</a></p>';
        echo '<p><strong>'.esc_html__('Author:', 'internal-external-link-manager-premium').'</strong> <a href="https://beeclear.pl">BeeClear</a></p>';
    }

    public function save_post_rules($post_id){
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;

        $nonce = isset($_POST[self::NONCE]) ? sanitize_text_field(wp_unslash($_POST[self::NONCE])) : '';
        if ( $nonce === '' || ! wp_verify_nonce($nonce, self::NONCE) ) return;
        if ( ! current_user_can('edit_post', $post_id) ) return;

        if ( empty($_POST['beeclear_ilm_rules_present']) ) return;

        $no_out = ! empty($_POST['beeclear_ilm_no_outgoing']) ? '1' : '';
        if ( $no_out === '1' ){
            update_post_meta($post_id, self::META_NO_OUT, '1');
        } else {
            delete_post_meta($post_id, self::META_NO_OUT);
        }

        $meta_changed = false;

        $allowed_raw   = isset($_POST['beeclear_ilm_allowed_elements']) ? sanitize_text_field((string) wp_unslash($_POST['beeclear_ilm_allowed_elements'])) : '';
        $allowed_tags  = $this->parse_tag_list($allowed_raw);
        $allowed_clean = implode(', ', $allowed_tags);
        $current_allowed = (string) get_post_meta($post_id, self::META_ALLOWED_TAGS, true);
        if ($allowed_clean === ''){
            if ($current_allowed !== ''){ delete_post_meta($post_id, self::META_ALLOWED_TAGS); $meta_changed = true; }
        } else {
            if ($current_allowed !== $allowed_clean){ update_post_meta($post_id, self::META_ALLOWED_TAGS, $allowed_clean); $meta_changed = true; }
        }

        $context_flag = ! empty($_POST['beeclear_ilm_context_enabled']);
        $context_meta = ! empty(get_post_meta($post_id, self::META_CONTEXT_FLAG, true));
        if ($context_flag && ! $context_meta){ update_post_meta($post_id, self::META_CONTEXT_FLAG, '1'); $meta_changed = true; }
        if ( ! $context_flag && $context_meta){ delete_post_meta($post_id, self::META_CONTEXT_FLAG); $meta_changed = true; }

        $current_rules = get_post_meta($post_id, self::META_RULES, true);
        if ( ! is_array($current_rules) ) $current_rules = array();

        $current_limit_meta = get_post_meta($post_id, self::META_MAX_PER_TARGET, true);
        $limit_raw = isset($_POST['beeclear_ilm_max_per_target']) ? sanitize_text_field(trim((string) sanitize_text_field( wp_unslash($_POST['beeclear_ilm_max_per_target']) ))) : '';
        $limit_changed = false;
        $current_priority_meta = get_post_meta($post_id, self::META_TARGET_PRIORITY, true);
        $priority_raw = isset($_POST['beeclear_ilm_target_priority']) ? sanitize_text_field(trim((string) sanitize_text_field( wp_unslash($_POST['beeclear_ilm_target_priority']) ))) : '';
        $priority_changed = false;
        if ($limit_raw === '') {
            if ($current_limit_meta !== '') {
                delete_post_meta($post_id, self::META_MAX_PER_TARGET);
                $limit_changed = true;
            }
        } else {
            $limit_val = max(0, intval($limit_raw));
            $normalized_limit = (string) $limit_val;
            if ($current_limit_meta !== $normalized_limit) {
                update_post_meta($post_id, self::META_MAX_PER_TARGET, $normalized_limit);
                $limit_changed = true;
            }
        }

        if ($priority_raw === '') {
            if ($current_priority_meta !== '') {
                delete_post_meta($post_id, self::META_TARGET_PRIORITY);
                $priority_changed = true;
            }
        } else {
            $priority_val = min(100, max(0, intval($priority_raw)));
            $normalized_priority = (string) $priority_val;
            if ($current_priority_meta !== $normalized_priority) {
                update_post_meta($post_id, self::META_TARGET_PRIORITY, $normalized_priority);
                $priority_changed = true;
            }
        }

        $explicit_clear = ! empty($_POST['beeclear_ilm_rules_clear']);
        if ( $explicit_clear ) {
            if ( ! empty($current_rules) ) {
                update_post_meta($post_id, self::META_RULES, array());
                $this->rebuild_index();
            } elseif (($limit_changed || $priority_changed || $meta_changed) && ! empty($current_limit_meta)) {
                $this->rebuild_index();
            }
            return;
        }

        if ( ! array_key_exists('beeclear_ilm_rules', $_POST) ) {
            if (($limit_changed || $priority_changed || $meta_changed) && ! empty($current_rules)) {
                $this->rebuild_index();
            }
            return;
        }

        $raw_input = isset($_POST['beeclear_ilm_rules']) ? sanitize_text_field( wp_unslash($_POST['beeclear_ilm_rules']) ) : array();
        if ( is_string($raw_input) ) {
            $decoded = json_decode(wp_unslash($raw_input), true);
            $raw = is_array($decoded) ? $decoded : array();
        } else {
            $raw = is_array($raw_input) ? wp_unslash($raw_input) : array();
        }

        $rules = array();
        foreach($raw as $r){
            $phrase = isset($r['phrase']) ? sanitize_text_field( trim( wp_unslash($r['phrase']) ) ) : '';
            if($phrase==='') continue;
            $regex  = !empty($r['regex']);
            $case   = !empty($r['case']) && !$regex;
            $context_regex = !empty($r['context_regex']);
            $context_case  = !empty($r['context_case']) && ! $context_regex;
            $context_terms = array();
            if ($context_flag && isset($r['context'])) {
                $raw_context = is_string($r['context']) ? sanitize_text_field( wp_unslash($r['context']) ) : '';
                $parts = preg_split('/[,\n]+/', (string) $raw_context);
                foreach ((array) $parts as $p){
                    $p = trim($p);
                    if ($p !== '') $context_terms[] = $p;
                }
            }
            $rules[] = array(
                'phrase' => $phrase,
                'regex'  => $regex ? true : false,
                'case'   => $case ? true : false,
                'context'=> $context_terms,
                'context_regex' => $context_regex ? true : false,
                'context_case'  => $context_case ? true : false,
            );
        }

        if ( empty($rules) && ! empty($current_rules) ) {
            if (($limit_changed || $priority_changed || $meta_changed) && ! empty($current_rules)) {
                $this->rebuild_index();
            }
            return;
        }

        if ( $current_rules === $rules ) {
            if (($limit_changed || $priority_changed || $meta_changed) && ! empty($current_rules)) {
                $this->rebuild_index();
            }
            return;
        }

        update_post_meta($post_id, self::META_RULES, $rules);
        $this->rebuild_index();
    }

    public function on_post_delete($post_id){
        delete_post_meta($post_id, self::META_RULES);
        delete_post_meta($post_id, self::META_NO_OUT);
        delete_post_meta($post_id, self::META_MAX_PER_TARGET);
        delete_post_meta($post_id, self::META_ALLOWED_TAGS);
        delete_post_meta($post_id, self::META_CONTEXT_FLAG);
        delete_post_meta($post_id, self::META_TARGET_PRIORITY);
        $this->rebuild_index();
    }

    public function trigger_full_rebuild_after_save($post_ID, $post, $update){
        if (empty($post) || is_wp_error($post)) return;
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( wp_is_post_autosave( $post_ID ) || wp_is_post_revision( $post_ID ) ) return;

        $settings = get_option(self::OPT_SETTINGS, array());
        $pts = !empty($settings['process_post_types']) ? (array)$settings['process_post_types'] : array('post','page');
        if (!in_array($post->post_type, $pts, true)) return;
        if (get_post_status($post_ID) !== 'publish') return;
        $this->rebuild_index();

        $this->maybe_run_overview_scan_after_save();
    }

    public function on_status_change($new_status, $old_status, $post){
        if ($new_status === $old_status) return;
        if ($new_status !== 'publish') return;
        $this->trigger_full_rebuild_after_save($post->ID, $post, true);
    }

    private function start_overview_scan_now($post_types, $log_message = ''){
        $pts = !empty($post_types) ? array_values(array_unique((array) $post_types)) : array('post','page');
        $ids = $this->collect_overview_scan_ids($pts);

        update_option(self::OPT_LINKMAP, array(), false);
        update_option(self::OPT_EXTERNAL_MAP, array(), false);

        if ( empty($ids) ) {
            delete_option(self::OPT_OVERVIEW_SCAN);
            return false;
        }

        $state = array(
            'ids'       => $ids,
            'processed' => 0,
            'total'     => count($ids),
            'started_at'=> current_time('timestamp'),
        );
        update_option(self::OPT_OVERVIEW_SCAN, $state, false);

        if ($log_message !== ''){
            $this->log_activity(sprintf($log_message, count($ids)));
        }

        do {
            $result = $this->process_overview_scan_batch(5);
        } while ( empty($result['done']) );

        return true;
    }

    private function maybe_run_overview_scan_after_save(){
        $settings = get_option(self::OPT_SETTINGS, array());
        if ( empty($settings['auto_scan_on_save']) ) return;

        $pts = !empty($settings['process_post_types']) ? (array)$settings['process_post_types'] : array('post','page');
        /* translators: %d: number of pages queued for the overview scan. */
        $this->start_overview_scan_now($pts, __('Auto scan started after save: %d pages queued.', 'internal-external-link-manager-premium'));
    }

    private function rebuild_index(){
        $this->last_rebuild_error = '';
        $settings = get_option(self::OPT_SETTINGS, array());
        $pts = !empty($settings['process_post_types']) ? (array)$settings['process_post_types'] : array('post','page');

        if ( ! class_exists('WP_Query') ) {
            $this->last_rebuild_error = __('Index rebuild failed: WP_Query is unavailable.', 'internal-external-link-manager-premium');
            update_option(self::OPT_INDEX, array(), false);
            return array();
        }

        $q = new WP_Query(array(
            'post_type'      => $pts,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ));
        if ( is_wp_error($q) ) {
            /* translators: %s: WP_Query error message. */
            $this->last_rebuild_error = sprintf(__('Index rebuild failed: %s', 'internal-external-link-manager-premium'), $q->get_error_message());
            update_option(self::OPT_INDEX, array(), false);
            return array();
        }

        $index = array();
        foreach((array)$q->posts as $pid){
            $rules = get_post_meta($pid, self::META_RULES, true);
            if(!is_array($rules) || empty($rules)) continue;
            $per_target_limit = get_post_meta($pid, self::META_MAX_PER_TARGET, true);
            $per_target_limit = ($per_target_limit === '' ? null : max(0, (int) $per_target_limit));
            $target_priority = (int) get_post_meta($pid, self::META_TARGET_PRIORITY, true);
            if ($target_priority < 0) $target_priority = 0;
            if ($target_priority > 100) $target_priority = 100;
            $allowed_tags = $this->parse_tag_list(get_post_meta($pid, self::META_ALLOWED_TAGS, true));
            $context_flag = !empty(get_post_meta($pid, self::META_CONTEXT_FLAG, true));
            $prio = 0;
            foreach($rules as $r){
                $phrase = isset($r['phrase']) ? trim((string)$r['phrase']) : '';
                if($phrase==='') continue;
                $context_regex = !empty($r['context_regex']);
                $context_case  = !empty($r['context_case']) && empty($r['context_regex']);
                $context_terms = array();
                if ($context_flag && !empty($r['context']) && is_array($r['context'])) {
                    foreach ($r['context'] as $ctx){
                        $ctx = is_string($ctx) ? trim($ctx) : '';
                        if ($ctx !== '') $context_terms[] = $ctx;
                    }
                }
                $index[] = array(
                    'phrase'   => $phrase,
                    'regex'    => !empty($r['regex']),
                    'case'     => !empty($r['case']),
                    'target'   => (int)$pid,
                    'max_per_target' => $per_target_limit,
                    'priority' => $prio++,
                    'target_priority' => $target_priority,
                    'allowed_tags' => $allowed_tags,
                    'context_enabled' => $context_flag,
                    'context' => $context_terms,
                    'context_regex' => $context_regex,
                    'context_case' => $context_case,
                );
            }
        }
        usort($index, function($a,$b){
            if($a['target_priority'] !== $b['target_priority']){
                return $a['target_priority'] > $b['target_priority'] ? -1 : 1;
            }
            if($a['priority'] === $b['priority']){
                if($a['target'] === $b['target']) return strcmp($a['phrase'], $b['phrase']);
                return $a['target'] - $b['target'];
            }
            return $a['priority'] - $b['priority'];
        });
        update_option(self::OPT_INDEX, $index, false);
        return $index;
    }

    public function autolink_excerpt($excerpt){ return $this->autolink_content($excerpt, true); }
    public function autolink_widget_text($text){ return $this->autolink_content($text, true); }

    public function autolink_content($content, $force=false){
        $start = null;
        $track_timing = false;
        try {
            $allow_admin_processing = apply_filters('beeclear_ilm_allow_admin_processing', false, $force);
            if ( (is_admin() || is_feed() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('DOING_AJAX') && DOING_AJAX)) && ! $allow_admin_processing ) return $content;
            if ( ! is_string($content) || $content === '' ) return $content;

            static $settings_cache = null;
            if ($settings_cache === null){
                $settings_cache = get_option(self::OPT_SETTINGS, array());
            }
            $settings = $settings_cache;
            $track_timing = !empty($settings['log_internal_timing']);
            if ($track_timing){
                $start = microtime(true);
            }
            $minlen = isset($settings['min_content_length']) ? (int)$settings['min_content_length'] : 200;
            $min_element_length = isset($settings['min_element_length']) ? (int)$settings['min_element_length'] : 20;

            $plain = wp_strip_all_tags($content);
            if ( function_exists('mb_strlen') ) { if ( mb_strlen($plain, 'UTF-8') < $minlen ) return $content; }
            else { if ( strlen($plain) < $minlen ) return $content; }

            if ( ! $force ){
                $on_archives = !empty($settings['process_on_archives']);
                if ( ! is_singular() && ! $on_archives ) return $content;
            }

            global $post;
            if ($post instanceof WP_Post){
                $pts = !empty($settings['process_post_types']) ? (array)$settings['process_post_types'] : array('post','page');
                if ( ! in_array($post->post_type, $pts, true) ) return $content;

                if ( ! empty(get_post_meta($post->ID, self::META_NO_OUT, true)) ){
                    return $content;
                }
            }

            static $permalink_cache = array();

            static $internal_cache = null, $external_cache = null;
            if ($internal_cache === null) $internal_cache = get_option(self::OPT_INDEX, array());
            if ($external_cache === null) $external_cache = get_option(self::OPT_EXT_RULES, array());
            $internal = $internal_cache;
            $external = $external_cache;
            if ( empty($internal) && empty($external) ) return $content;

            $skip_int = $this->parse_tag_list($settings['skip_elements_internal'] ?? '');
            $skip_ext = $this->parse_tag_list($settings['skip_elements_external'] ?? '');
            $enable_cross = !empty($settings['cross_inline']);

            $current_post_type = ($post instanceof WP_Post) ? $post->post_type : '';
            $current_id = ($post instanceof WP_Post) ? (int)$post->ID : 0;

            $prepared_internal = array();
            foreach( (array)$internal as $rule ){
                $phrase = isset($rule['phrase']) ? trim((string)$rule['phrase']) : '';
                $target = isset($rule['target']) ? (int)$rule['target'] : 0;
                if($phrase === '' || !$target) continue;
                if($current_id && $target === $current_id) continue;
                $rule_limit = null;
                if (array_key_exists('max_per_target', $rule) && $rule['max_per_target'] !== null) {
                    $rule_limit = max(0, (int) $rule['max_per_target']);
                }

                $context_patterns = array();
                if (!empty($rule['context_enabled']) && !empty($rule['context']) && is_array($rule['context'])) {
                    foreach ($rule['context'] as $ctx){
                        $ctx_pattern = $this->build_pattern($ctx, !empty($rule['context_regex']), !empty($rule['context_case']));
                        if ($ctx_pattern) $context_patterns[] = $ctx_pattern;
                    }
                }

                $pattern = $this->build_pattern($phrase, !empty($rule['regex']), !empty($rule['case']));
                if(!$pattern) continue;

                if ( !array_key_exists($target, $permalink_cache) ){
                    $permalink_cache[$target] = get_permalink($target) ?: '';
                }
                if ($permalink_cache[$target] === '') continue;

                $prepared_internal[] = array(
                    'phrase'  => $phrase,
                    'regex'   => !empty($rule['regex']),
                    'case'    => !empty($rule['case']),
                    'target'  => $target,
                    'pattern' => $pattern,
                    'url'     => $permalink_cache[$target],
                    'max_per_target' => $rule_limit,
                    'allowed_tags' => isset($rule['allowed_tags']) && is_array($rule['allowed_tags']) ? array_values($rule['allowed_tags']) : array(),
                    'context_patterns' => $context_patterns,
                );
            }

            if ( class_exists('DOMDocument') && class_exists('DOMXPath') ) {
                $prepared_external = array();
                foreach( (array)$external as $idx => $r ){
                    $phrase = isset($r['phrase']) ? trim((string)$r['phrase']) : '';
                    $url    = isset($r['url'])    ? esc_url_raw($r['url']) : '';
                    if ($phrase==='' || $url==='') continue;
                    $types  = isset($r['types']) ? (array)$r['types'] : array();
                    if ( ! empty($types) && $current_post_type && ! in_array($current_post_type, $types, true) ) continue;
                    $excluded_ids = array_map('intval', (array)($r['exclude_ids'] ?? array()));
                    if ( $current_id && in_array($current_id, $excluded_ids, true) ) continue;
                    $pattern = $this->build_pattern($phrase, !empty($r['regex']), !empty($r['case']));
                    if ( ! $pattern ) continue;

                    $context_patterns = array();
                    $raw_contexts = isset($r['context']) ? $r['context'] : array();
                    if ( ! is_array($raw_contexts) ) {
                        $raw_contexts = preg_split('/[,\n]+/', (string) $raw_contexts);
                    }
                    foreach ((array) $raw_contexts as $ctx_raw){
                        $ctx = trim((string) $ctx_raw);
                        if ($ctx === '') continue;
                        $ctx_pattern = $this->build_pattern($ctx, !empty($r['context_regex']), !empty($r['context_case']));
                        if ($ctx_pattern) $context_patterns[] = $ctx_pattern;
                    }

                    $prepared_external[] = array(
                        'idx'    => (int)$idx,
                        'phrase' => $phrase,
                        'regex'  => !empty($r['regex']),
                        'case'   => !empty($r['case']) && empty($r['regex']),
                        'url'    => $url,
                        'pattern'=> $pattern,
                        'rel'    => isset($r['rel']) ? sanitize_text_field($r['rel']) : '',
                        'title_mode'   => in_array(($r['title_mode'] ?? 'phrase'), array('none','phrase','custom'), true) ? $r['title_mode'] : 'phrase',
                        'title_custom' => isset($r['title_custom']) ? (string)$r['title_custom'] : '',
                        'aria_mode'    => in_array(($r['aria_mode'] ?? 'phrase'), array('none','phrase','custom'), true) ? $r['aria_mode'] : 'phrase',
                        'aria_custom'  => isset($r['aria_custom']) ? (string)$r['aria_custom'] : '',
                        'class'        => isset($r['class']) ? sanitize_html_class($r['class']) : '',
                        'max_per_page' => max(0, intval($r['max_per_page'] ?? 1)),
                        'allowed_tags' => $this->parse_tag_list($r['allowed_tags'] ?? ''),
                        'context_patterns' => $context_patterns,
                    );
                }

                if (empty($prepared_internal) && empty($prepared_external)) return $content;

                $flags = 0;
                if (defined('LIBXML_HTML_NODEFDTD')) $flags |= LIBXML_HTML_NODEFDTD;
                if (defined('LIBXML_HTML_NOIMPLIED')) $flags |= LIBXML_HTML_NOIMPLIED;

                $dom = new DOMDocument();
                $enc = 'UTF-8';
                $wrapped = '<div>'. (function_exists('mb_convert_encoding') ? mb_convert_encoding($content, 'HTML-ENTITIES', $enc) : $content) .'</div>';
                libxml_use_internal_errors(true);
                $loaded = @$dom->loadHTML($wrapped, $flags);
                libxml_clear_errors();
                if ( ! $loaded ) return $content;

                $global_max_per_target = isset($settings['max_per_target']) ? (int)$settings['max_per_target'] : 1;
                $max_total            = isset($settings['max_total_per_page']) ? (int)$settings['max_total_per_page'] : 0;

                $linked_counts_internal   = array();
                $linked_counts_external   = array();
                $linked_phrases_internal  = array(); // per target => [matched_text => count]
                $linked_contexts_internal = array(); // per target => list of fragments (html + tag + phrase)
                $linked_phrases_external  = array(); // per rule idx => [matched_text => count]
                $linked_contexts_external = array(); // per rule idx => list of fragments (html + tag + phrase)
                $total_count = 0;

                $xpath = new DOMXPath($dom);
                $textNodes = $xpath->query('//text()[normalize-space(.) != ""]');

                foreach($textNodes as $node){
                    // skip base
                    $skip_base = false;
                    for($n=$node->parentNode; $n; $n=$n->parentNode){
                        $nn = strtolower($n->nodeName);
                        if($nn==='a' || $nn==='script' || $nn==='style' || $nn==='code' || $nn==='pre'){ $skip_base=true; break; }
                        if($n->attributes && $n->attributes->getNamedItem('class')){
                            if (strpos(' '.$n->attributes->getNamedItem('class')->nodeValue.' ', ' beeclear-ilm--no-autolink ') !== false){ $skip_base = true; break; }
                        }
                    }
                    if($skip_base) continue;

                    $skip_internal_here = $this->node_in_tags($node, $skip_int);
                    $base_skip_external_here = $this->node_in_tags($node, $skip_ext);
                    if ( $skip_internal_here && $base_skip_external_here ) continue;

                    $txt = $node->nodeValue;
                    if ($txt === '') continue;
                    $element_text = null;

                    if ($min_element_length > 0){
                        $element_text = $this->get_text_for_node_element($node);
                        $element_len = function_exists('mb_strlen') ? mb_strlen($element_text, 'UTF-8') : strlen($element_text);
                        if ($element_len < $min_element_length) continue;
                    }

                    // INTERNAL
                    if ( ! $skip_internal_here ){
                        foreach($prepared_internal as $rule){
                            $target = (int)$rule['target'];
                            $target_limit = isset($rule['max_per_target']) && $rule['max_per_target'] !== null ? (int) $rule['max_per_target'] : $global_max_per_target;
                            if($target_limit > 0 && isset($linked_counts_internal[$target]) && $linked_counts_internal[$target] >= $target_limit) continue;
                            if($max_total > 0 && $total_count >= $max_total) break 2;

                            if (!empty($rule['allowed_tags']) && ! $this->node_in_tags($node, $rule['allowed_tags'])) continue;

                            if (!empty($rule['context_patterns'])){
                                if ($element_text === null){
                                    $element_text = $this->get_text_for_node_element($node);
                                }
                                if (! $this->context_patterns_match($element_text, $rule['context_patterns'])) continue;
                            }

                            $pattern = $rule['pattern'];
                            if( $pattern ){
                                $found = false;
                                $matched_phrase = null;
                                $context_element = $this->get_closest_element($node);
                                $newTxt = @preg_replace_callback($pattern, function($m) use ($rule, $target, $settings, &$found, &$linked_phrases_internal, &$matched_phrase){
                                    $found = true;
                                    $text = $m[0];
                                    $url = $rule['url'];
                                    if ( ! $url ) { $found = false; return $m[0]; }
                                    $rel = trim((string)($settings['rel'] ?? ''));
                                    $title = $this->build_attr_from_mode_rule_or_global($settings, null, 'title_mode','title_custom',$target,$text);
                                    $aria  = $this->build_attr_from_mode_rule_or_global($settings, null, 'aria_mode','aria_custom',$target,$text);
                                    $class = trim((string)($settings['default_class'] ?? 'beeclear-ilm-link'));
                                    $tpl = (string)($settings['link_template'] ?? '<a href="{url}"{rel}{title}{aria}{class}>{text}</a>');

                                    // ZAPISUJEMY KONKRETNY TEKST DOPASOWANIA, NIE NAZWĘ REGUŁY
                                    $ph = (string)$text;
                                    if ($ph !== '') {
                                        if (!isset($linked_phrases_internal[$target])) $linked_phrases_internal[$target] = array();
                                        $linked_phrases_internal[$target][$ph] = ($linked_phrases_internal[$target][$ph] ?? 0) + 1;
                                        $matched_phrase = $ph;
                                    }

                                    $repl = strtr($tpl, array(
                                        '{url}'   => esc_url($url),
                                        '{text}'  => esc_html($text),
                                        '{rel}'   => $rel!=='' ? ' rel="'.esc_attr($rel).'"' : '',
                                        '{title}' => $title!=='' ? ' title="'.esc_attr($title).'"' : '',
                                        '{aria}'  => $aria!=='' ? ' aria-label="'.esc_attr($aria).'"' : '',
                                        '{class}' => $class!=='' ? ' class="'.esc_attr($class).'"' : '',
                                    ));
                                    return $repl;
                                }, $txt, 1);

                                if($found && is_string($newTxt)){
                                    $frag = $dom->createDocumentFragment();
                                    if (@$frag->appendXML($newTxt)) {
                                        $node->parentNode->replaceChild($frag, $node);
                                        if ($matched_phrase !== null){
                                            $this->store_link_context($linked_contexts_internal, $target, $matched_phrase, $context_element ?: $node, false);
                                        }
                                    }
                                    $linked_counts_internal[$target] = isset($linked_counts_internal[$target]) ? $linked_counts_internal[$target]+1 : 1;
                                    $total_count++;
                                    continue 2;
                                }
                            }

                            // CROSS-INLINE (literal only)
                            if ( $enable_cross && empty($rule['regex']) && ! $this->contains_tokens($rule['phrase']) ){
                                if ( $this->cross_inline_try($dom, $node, $rule['phrase'], !empty($rule['case']), function($anchor, $matched) use ($settings, $rule, $target, &$linked_phrases_internal){
                                        $url = $rule['url'];
                                        if ( ! $url ) return false;
                                        if ($settings['rel'] ?? '') $anchor->setAttribute('rel', $settings['rel']);
                                        $title = $this->build_attr_from_mode_rule_or_global($settings, null, 'title_mode','title_custom',$target, $matched);
                                        $aria  = $this->build_attr_from_mode_rule_or_global($settings, null, 'aria_mode','aria_custom',$target, $matched);
                                        if ($title !== '') $anchor->setAttribute('title', $title);
                                        if ($aria  !== '') $anchor->setAttribute('aria-label', $aria);
                                        $class = trim((string)($settings['default_class'] ?? 'beeclear-ilm-link'));
                                        if ($class !== '') $anchor->setAttribute('class', $class);
                                        $anchor->setAttribute('href', esc_url($url));

                                        // ZAPISUJEMY KONKRETNY TEKST DOPASOWANIA
                                        $ph = (string)$matched;
                                        if ($ph !== '') {
                                            if (!isset($linked_phrases_internal[$target])) $linked_phrases_internal[$target] = array();
                                            $linked_phrases_internal[$target][$ph] = ($linked_phrases_internal[$target][$ph] ?? 0) + 1;
                                        }
                                        return true;
                                    }, function($anchorNode, $matchedText) use (&$linked_contexts_internal, $target){
                                        $this->store_link_context($linked_contexts_internal, $target, $matchedText, $anchorNode, false);
                                    }) ){
                                    $linked_counts_internal[$target] = isset($linked_counts_internal[$target]) ? $linked_counts_internal[$target]+1 : 1;
                                    $total_count++;
                                    continue 2;
                                }
                            }
                        }
                    }

                    // EXTERNAL (bez zmian logicznych)
                    foreach($prepared_external as $er){
                        $skip_external_here = !empty($er['allowed_tags'])
                            ? ! $this->node_in_tags($node, $er['allowed_tags'])
                            : $base_skip_external_here;
                        if ( $skip_external_here ) continue;

                        if (!empty($er['context_patterns'])){
                            if ($element_text === null){
                                $element_text = $this->get_text_for_node_element($node);
                            }
                            if (! $this->context_patterns_match($element_text, $er['context_patterns'])) continue;
                        }

                        if($max_total > 0 && $total_count >= $max_total) break 2;
                        $idx = $er['idx'];
                        $maxp = (int)$er['max_per_page'];
                        if($maxp > 0 && isset($linked_counts_external[$idx]) && $linked_counts_external[$idx] >= $maxp) continue;

                        $pattern = $er['pattern'];
                        if( $pattern ){
                            $found = false;
                            $matched_phrase = null;
                            $context_element = $this->get_closest_element($node);
                            $newTxt = @preg_replace_callback($pattern, function($m) use ($er, $settings, &$found, &$matched_phrase){
                                $found = true;
                                $text = $m[0];
                                $url  = $er['url'];
                                $rel  = trim((string)($er['rel'] ?? ''));
                                $title = $this->build_attr_from_mode_rule_or_global($settings, $er, 'title_mode','title_custom',0,$text);
                                $aria  = $this->build_attr_from_mode_rule_or_global($settings, $er, 'aria_mode','aria_custom',0,$text);
                                $class = trim((string)($er['class'] !== '' ? $er['class'] : ($settings['default_class'] ?? 'beeclear-ilm-link')));
                                $tpl = (string)($settings['link_template'] ?? '<a href="{url}"{rel}{title}{aria}{class}>{text}</a>');
                                $matched_phrase = (string) $text;
                                $repl = strtr($tpl, array(
                                    '{url}'   => esc_url($url),
                                    '{text}'  => esc_html($text),
                                    '{rel}'   => $rel!=='' ? ' rel="'.esc_attr($rel).'"' : '',
                                    '{title}' => $title!=='' ? ' title="'.esc_attr($title).'"' : '',
                                    '{aria}'  => $aria!=='' ? ' aria-label="'.esc_attr($aria).'"' : '',
                                    '{class}' => $class!=='' ? ' class="'.esc_attr($class).'"' : '',
                                ));
                                return $repl;
                            }, $txt, 1);

                            if($found && is_string($newTxt)){
                                $frag = $dom->createDocumentFragment();
                                if (@$frag->appendXML($newTxt)) {
                                    $node->parentNode->replaceChild($frag, $node);
                                    if ($matched_phrase !== null){
                                        if (!isset($linked_phrases_external[$idx])) $linked_phrases_external[$idx] = array();
                                        $linked_phrases_external[$idx][$matched_phrase] = ($linked_phrases_external[$idx][$matched_phrase] ?? 0) + 1;
                                        $this->store_link_context($linked_contexts_external, $idx, $matched_phrase, $context_element ?: $node, false);
                                    }
                                }
                                $linked_counts_external[$idx] = isset($linked_counts_external[$idx]) ? $linked_counts_external[$idx]+1 : 1;
                                $total_count++;
                                continue 2;
                            }
                        }

                        if ( $enable_cross && empty($er['regex']) && ! $this->contains_tokens($er['phrase']) ){
                            if ( $this->cross_inline_try($dom, $node, $er['phrase'], !empty($er['case']), function($anchor, $matched) use ($settings, $er){
                                    $url = $er['url'];
                                    if ( ! $url ) return false;
                                    if ($er['rel'] ?? '') $anchor->setAttribute('rel', $er['rel']);
                                    $title = $this->build_attr_from_mode_rule_or_global($settings, $er, 'title_mode','title_custom',0, $matched);
                                    $aria  = $this->build_attr_from_mode_rule_or_global($settings, $er, 'aria_mode','aria_custom',0, $matched);
                                    if ($title !== '') $anchor->setAttribute('title', $title);
                                    if ($aria  !== '') $anchor->setAttribute('aria-label', $aria);
                                    $class = trim((string)($er['class'] !== '' ? $er['class'] : ($settings['default_class'] ?? 'beeclear-ilm-link')));
                                    if ($class !== '') $anchor->setAttribute('class', $class);
                                    $anchor->setAttribute('href', esc_url($url));
                                    return true;
                                }, function($anchorNode, $matchedText) use (&$linked_contexts_external, &$linked_phrases_external, $idx){
                                    $matchedText = (string) $matchedText;
                                    if ($matchedText !== ''){
                                        if (!isset($linked_phrases_external[$idx])) $linked_phrases_external[$idx] = array();
                                        $linked_phrases_external[$idx][$matchedText] = ($linked_phrases_external[$idx][$matchedText] ?? 0) + 1;
                                    }
                                    $this->store_link_context($linked_contexts_external, $idx, $matchedText, $anchorNode, false);
                                }) ){
                                $linked_counts_external[$idx] = isset($linked_counts_external[$idx]) ? $linked_counts_external[$idx]+1 : 1;
                                $total_count++;
                                continue 2;
                            }
                        }
                    }
                }

                // Zapisz mapę: per TARGET przechowuj sumę i źródła z frazami (matched text)
                if (!empty($post->ID)){
                    $map = get_option(self::OPT_LINKMAP, array());
                    $current_id = (int)$post->ID;
                    $map_changed = false;

                    // Usuń poprzednie wpisy dla tego źródła, by nie dublować po kolejnych renderach lub po usunięciu linków
                    foreach ($map as $target_id => $entry){
                        if (!isset($entry['sources'][$current_id])) continue;
                        unset($map[$target_id]['sources'][$current_id]);
                        $map[$target_id]['count'] = $this->sum_source_counts($map[$target_id]['sources']);
                        if ($map[$target_id]['count'] <= 0 || empty($map[$target_id]['sources'])){
                            unset($map[$target_id]);
                        }
                        $map_changed = true;
                    }

                    if (!empty($linked_counts_internal)){
                        foreach($linked_counts_internal as $t=>$c){
                            $map[$t] = $map[$t] ?? array('count'=>0,'sources'=>array());

                            // scal konkretne dopasowania
                            $match_for_target = isset($linked_phrases_internal[$t]) ? (array)$linked_phrases_internal[$t] : array();
                            $existing_phrases = array();
                            foreach($match_for_target as $ph=>$pc){
                                $existing_phrases[$ph] = (int) $pc;
                            }

                            $context_for_target = isset($linked_contexts_internal[$t]) ? (array)$linked_contexts_internal[$t] : array();
                            $merged_contexts = $this->merge_contexts(array(), $context_for_target);

                            $map[$t]['sources'][$current_id] = array(
                                'count'   => (int) $c,
                                'phrases' => $existing_phrases, // klucze = KONKRETNE frazy z treści
                                'contexts'=> $merged_contexts,
                            );
                            $map[$t]['count'] = $this->sum_source_counts($map[$t]['sources']);
                            $map_changed = true;
                        }
                    }

                    if ($map_changed){
                        update_option(self::OPT_LINKMAP, $map, false);
                    }
                }

                if ($this->collect_external_matches && !empty($linked_counts_external)){
                    $prepared_by_idx = array();
                    foreach ($prepared_external as $er){
                        $prepared_by_idx[$er['idx']] = $er;
                    }
                    foreach ($linked_counts_external as $idx=>$count){
                        $rule = $prepared_by_idx[$idx] ?? array();
                        $this->external_matches_log[] = array(
                            'rule_idx' => $idx,
                            'post_id'  => $current_id ?? 0,
                            'count'    => (int) $count,
                            'phrase'   => isset($rule['phrase']) ? $rule['phrase'] : '',
                            'url'      => isset($rule['url']) ? $rule['url'] : '',
                            'phrases'  => isset($linked_phrases_external[$idx]) ? (array) $linked_phrases_external[$idx] : array(),
                            'contexts' => isset($linked_contexts_external[$idx]) ? (array) $linked_contexts_external[$idx] : array(),
                        );
                    }
                }

                $html = $dom->saveHTML();
                if (substr($html,0,5)==='<div>' && substr($html,-6)==='</div>'){ $html = substr($html,5,-6); }
                return $html;
            }

            return $this->fallback_link_once_both($content, $internal, $external, $settings, isset($post)?$post:null);
        } catch (\Throwable $e) {
            return $content;
        } finally {
            if ($track_timing && $start !== null) {
                $this->autolink_timing_ms += (microtime(true) - $start) * 1000;
            }
        }
    }

    public function disable_free_version_if_active(){
        if ( ! function_exists('is_plugin_active') ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if ( is_plugin_active(self::BASE_PLUGIN) ) {
            deactivate_plugins(array(self::BASE_PLUGIN));
            $this->enqueue_premium_notice(__('Internal & External Link Manager has been deactivated because the premium version is active.', 'internal-external-link-manager-premium'), 'warning');
        }
    }

    public function maybe_block_free_plugin_activation(){
        if ( ! is_admin() ) return;
        if ( ! current_user_can('activate_plugins') ) return;

        $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : '';
        $plugin = isset($_GET['plugin']) ? sanitize_text_field(wp_unslash($_GET['plugin'])) : '';

        if ( 'activate' === $action && self::BASE_PLUGIN === $plugin && $this->is_premium_active() ) {
            $this->enqueue_premium_notice(__('You cannot activate the free Internal & External Link Manager while the premium version is active.', 'internal-external-link-manager-premium'));
            wp_safe_redirect(admin_url('plugins.php'));
            exit;
        }
    }

    public function render_premium_admin_notices(){
        $notices = get_transient(self::NOTICE_TRANSIENT);
        if ( ! is_array($notices) ) {
            return;
        }

        delete_transient(self::NOTICE_TRANSIENT);

        foreach ($notices as $notice){
            $class = empty($notice['class']) ? 'notice notice-error' : 'notice notice-' . sanitize_html_class($notice['class']);
            $message = isset($notice['message']) ? $notice['message'] : '';
            if ( empty($message) ) continue;

            echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
        }
    }

    public function maybe_replace_free_plugin_actions($actions){
        if ( ! $this->is_premium_active() ) return $actions;

        $message = esc_html__('Premium version is activated', 'internal-external-link-manager-premium');
        $premium_message = '<span class="beeclear-ilm-premium-active">' . $message . '</span>';

        unset($actions['activate']);

        if (in_array($premium_message, $actions, true)) return $actions;

        array_unshift($actions, $premium_message);

        return $actions;
    }

    private function enqueue_premium_notice($message, $class = 'error'){
        $notices = get_transient(self::NOTICE_TRANSIENT);
        if ( ! is_array($notices) ) {
            $notices = array();
        }

        $notices[] = array(
            'class'   => $class,
            'message' => $message,
        );

        set_transient(self::NOTICE_TRANSIENT, $notices, 60);
    }

    private function is_premium_active(){
        if ( ! function_exists('is_plugin_active') ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active(plugin_basename(__FILE__));
    }

    // CROSS-INLINE helper
    private function cross_inline_try(DOMDocument $dom, DOMText $node, $phrase, $case, callable $configure_anchor, ?callable $after_match = null){
        $parent = $node->parentNode;
        if (!($parent instanceof DOMElement)) return false;
        if (!$this->is_format_tag($parent->nodeName)) return false;

        $after = $parent->nextSibling;
        if (!($after instanceof DOMText)) return false;

        $t1 = $node->nodeValue;
        $t2 = $after->nodeValue;
        if ($t1 === '' || $t2 === '') return false;

        $len1 = function_exists('mb_strlen') ? mb_strlen($t1, 'UTF-8') : strlen($t1);
        $plen = function_exists('mb_strlen') ? mb_strlen($phrase, 'UTF-8') : strlen($phrase);
        if ($plen === 0) return false;

        $haystack = $t1.$t2;
        $pos = $case
            ? (function_exists('mb_strpos') ? mb_strpos($haystack, $phrase, 0, 'UTF-8') : strpos($haystack, $phrase))
            : (function_exists('mb_stripos') ? mb_stripos($haystack, $phrase, 0, 'UTF-8') : stripos($haystack, $phrase));
        if ($pos === false) return false;

        if ($pos >= $len1) return false;
        $end = $pos + $plen;
        if ($end <= $len1) return false;

        $need2 = $end - $len1;
        $take1 = $len1 - $pos;

        $before1 = function_exists('mb_substr') ? mb_substr($t1, 0, $pos, 'UTF-8') : substr($t1, 0, $pos);
        $match1  = function_exists('mb_substr') ? mb_substr($t1, $pos, $take1, 'UTF-8') : substr($t1, $pos, $take1);
        $node->nodeValue = $before1;

        $match2 = function_exists('mb_substr') ? mb_substr($t2, 0, $need2, 'UTF-8') : substr($t2, 0, $need2);
        $after2 = function_exists('mb_substr') ? mb_substr($t2, $need2, null, 'UTF-8') : substr($t2, $need2);
        $after->nodeValue = $match2;
        if ($after2 !== ''){
            $restNode = $dom->createTextNode($after2);
            if ($after->parentNode) $after->parentNode->insertBefore($restNode, $after->nextSibling);
        }

        $a = $dom->createElement('a');
        $fmtClone = $parent->cloneNode(false);
        $fmtClone->appendChild($dom->createTextNode($match1));

        $container = $parent->parentNode;
        if (!($container instanceof DOMNode)) return false;

        $container->insertBefore($a, $parent->nextSibling);
        $a->appendChild($fmtClone);
        $a->appendChild($after);

        $matchedText = $match1 . $match2;
        if ( ! $configure_anchor($a, $matchedText) ) return false;

        if ($after_match) {
            $after_match($a, $matchedText);
        }

        return true;
    }

    private function contains_tokens($phrase){
        if (!is_string($phrase) || $phrase==='') return false;
        return (bool) preg_match('/\[(?:string|words)(?::[^\]]+)?\]/iu', $phrase);
    }

    private function compile_token_phrase_pattern($phrase, $is_case){
        if (!is_string($phrase) || $phrase==='') return null;

        $boundary = '[\p{L}\p{N}_]';
        $letter   = '\p{L}';
        $word     = '\p{L}+';

        $body = '';
        $prev_ended_with_space = false;

        $re = '/\[(string|words)(?::(?:(min|max)?(\d+)|(\d+)))?\]/iu';
        $offset = 0;
        while (preg_match($re, $phrase, $m, $flags = PREG_OFFSET_CAPTURE, $offset)){
            $m0 = $m[0][0];
            $pos = $m[0][1];

            $lit = substr($phrase, $offset, $pos - $offset);
            if ($lit !== ''){
                $parts = preg_split('/(\s+)/u', $lit, -1, PREG_SPLIT_DELIM_CAPTURE);
                foreach($parts as $p){
                    if ($p === '') continue;
                    if (preg_match('/^\s+$/u', $p)){
                        $body .= '\s+';
                        $prev_ended_with_space = true;
                    } else {
                        $body .= preg_quote($p, '/');
                        $prev_ended_with_space = false;
                    }
                }
            }

            $type = strtolower($m[1][0]);
            $minmax = isset($m[2][0]) ? strtolower((string)$m[2][0]) : '';
            $num_from_minmax = isset($m[3][0]) ? (int)$m[3][0] : 0;
            $num_exact = isset($m[4][0]) ? (int)$m[4][0] : 0;

            if ($type === 'string'){
                if ($num_exact > 0){
                    $q = '{'.$num_exact.'}';
                } elseif ($minmax === 'min' && $num_from_minmax > 0){
                    $q = '{'.$num_from_minmax.',}';
                } elseif ($minmax === 'max' && $num_from_minmax > 0){
                    $q = '{1,'.$num_from_minmax.'}';
                } else {
                    $q = '{1,}';
                }
                $body .= '(?:'.$letter.$q.')';
                $prev_ended_with_space = false;
            } else {
                if ($num_exact > 0){
                    $minW = $maxW = $num_exact;
                } elseif ($minmax === 'min' && $num_from_minmax > 0){
                    $minW = $num_from_minmax; $maxW = -1;
                } elseif ($minmax === 'max' && $num_from_minmax > 0){
                    $minW = 1; $maxW = $num_from_minmax;
                } else {
                    $minW = 1; $maxW = 3;
                }

                if ($minW === $maxW){
                    if ($prev_ended_with_space){
                        if ($minW >= 1){
                            $k = $minW;
                            if ($k === 1){
                                $body .= '(?:'.$word.')';
                            } else {
                                $body .= '(?:'.$word.')(?:\s+'.$word.'){'.($k-1).'}';
                            }
                        }
                    } else {
                        $body .= '(?:\s+'.$word.'){'.$minW.'}';
                    }
                } else {
                    if ($maxW === -1){
                        if ($prev_ended_with_space){
                            if ($minW <= 1){
                                $body .= '(?:'.$word.')(?:\s+'.$word.'){1,}';
                            } else {
                                $body .= '(?:'.$word.')(?:\s+'.$word.'){'.($minW-1).',}';
                            }
                        } else {
                            $body .= '(?:\s+'.$word.'){'.$minW.',}';
                        }
                    } else {
                        if ($prev_ended_with_space){
                            $low  = max(1, $minW);
                            $high = max($low, $maxW);
                            $body .= '(?:'.$word.')(?:\s+'.$word.'){'.($low-1).','.($high-1).'}';
                        } else {
                            $body .= '(?:\s+'.$word.'){'.$minW.','.$maxW.'}';
                        }
                    }
                }
                $prev_ended_with_space = false;
            }

            $offset = $pos + strlen($m0);
        }

        $rest = substr($phrase, $offset);
        if ($rest !== ''){
            $parts = preg_split('/(\s+)/u', $rest, -1, PREG_SPLIT_DELIM_CAPTURE);
            foreach($parts as $p){
                if ($p === '') continue;
                if (preg_match('/^\s+$/u', $p)){
                    $body .= '\s+';
                    $prev_ended_with_space = true;
                } else {
                    $body .= preg_quote($p, '/');
                    $prev_ended_with_space = false;
                }
            }
        }

        if ($body === '') return null;

        $mods = $is_case ? 'u' : 'iu';
        return '/(?<!'.$boundary.')('.$body.')(?!'.$boundary.')/'.$mods;
    }

    private function build_pattern($phrase, $is_regex, $is_case){
        if ($phrase==='') return null;
        $boundary = '[\p{L}\p{N}_]';
        if ( $is_regex ){
            $mod = $is_case ? 'u' : 'iu';
            if (@preg_match('/'.$phrase.'/'.$mod, '') === false) return null;
            return '/'.$phrase.'/'.$mod;
        } else {
            if ($this->contains_tokens($phrase)){
                $compiled = $this->compile_token_phrase_pattern($phrase, $is_case);
                if ($compiled) return $compiled;
            }
            $q = preg_quote($phrase, '/');
            return $is_case
                ? '/(?<!'.$boundary.')(' . $q . ')(?!'.$boundary.')/u'
                : '/(?<!'.$boundary.')(' . $q . ')(?!'.$boundary.')/iu';
        }
    }

    private function build_attr_from_mode_rule_or_global($settings, $ruleOrNull, $mode_key, $custom_key, $target_id, $phrase_text){
        if ($ruleOrNull && isset($ruleOrNull[$mode_key])) {
            $mode = $ruleOrNull[$mode_key];
            switch($mode){
                case 'none': return '';
                case 'custom': return (string)($ruleOrNull[$custom_key] ?? '');
                case 'phrase':
                default: return $phrase_text;
            }
        }
        $mode = isset($settings[$mode_key]) ? $settings[$mode_key] : 'phrase';
        switch($mode){
            case 'none': return '';
            case 'post_title': return $target_id ? get_the_title($target_id) : '';
            case 'custom': return (string)($settings[$custom_key] ?? '');
            case 'phrase':
            default: return $phrase_text;
        }
    }

    private function fallback_link_once_both($content, $internal, $external, $settings, $post){
        $apply_once = function($content, $pattern, $build_cb){
            if ( ! $pattern ) return $content;
            $res = @preg_replace_callback($pattern, function($m) use ($build_cb){ return $build_cb($m[0]); }, $content, 1);
            return is_string($res) ? $res : $content;
        };

        if ($post instanceof WP_Post && ! empty(get_post_meta($post->ID, self::META_NO_OUT, true)) ){
            return $content;
        }

        if (is_array($internal)){
            foreach($internal as $rule){
                $target = (int)$rule['target'];
                if ($post instanceof WP_Post && $target === (int)$post->ID) continue;
                $url = get_permalink($target);
                if ( ! $url ) continue;
                $pattern = $this->build_pattern($rule['phrase'], !empty($rule['regex']), !empty($rule['case']));
                $content2 = $apply_once($content, $pattern, function($text) use ($url, $settings, $target){
                    $rel = trim((string)($settings['rel'] ?? ''));
                    $title = $this->build_attr_from_mode_rule_or_global($settings, null, 'title_mode','title_custom',$target,$text);
                    $aria  = $this->build_attr_from_mode_rule_or_global($settings, null, 'aria_mode','aria_custom',$target,$text);
                    $class = trim((string)($settings['default_class'] ?? 'beeclear-ilm-link'));
                    $tpl = (string)($settings['link_template'] ?? '<a href="{url}"{rel}{title}{aria}{class}>{text}</a>');
                    return strtr($tpl, array(
                        '{url}'   => esc_url($url),
                        '{text}'  => esc_html($text),
                        '{rel}'   => $rel!=='' ? ' rel="'.esc_attr($rel).'"' : '',
                        '{title}' => $title!=='' ? ' title="'.esc_attr($title).'"' : '',
                        '{aria}'  => $aria!=='' ? ' aria-label="'.esc_attr($aria).'"' : '',
                        '{class}' => $class!=='' ? ' class="'.esc_attr($class).'"' : '',
                    ));
                });
                if ( $content2 !== $content ) return $content2;
            }
        }

        if (is_array($external)){
            foreach($external as $er){
                $phrase = isset($er['phrase']) ? trim((string)$er['phrase']) : '';
                $url    = isset($er['url'])    ? esc_url_raw($er['url']) : '';
                if ($phrase==='' || $url==='') continue;
                $pattern = $this->build_pattern($phrase, !empty($er['regex']), !empty($er['case']) && empty($er['regex']));
                $content2 = $apply_once($content, $pattern, function($text) use ($url, $settings, $er){
                    $rel = trim((string)($er['rel'] ?? ''));
                    $title = $this->build_attr_from_mode_rule_or_global($settings, $er, 'title_mode','title_custom',0,$text);
                    $aria  = $this->build_attr_from_mode_rule_or_global($settings, $er, 'aria_mode','aria_custom',0,$text);
                    $class = trim((string)($er['class'] !== '' ? $er['class'] : ($settings['default_class'] ?? 'beeclear-ilm-link')));
                    $tpl = (string)($settings['link_template'] ?? '<a href="{url}"{rel}{title}{aria}{class}>{text}</a>');
                    return strtr($tpl, array(
                        '{url}'   => esc_url($url),
                        '{text}'  => esc_html($text),
                        '{rel}'   => $rel!=='' ? ' rel="'.esc_attr($rel).'"' : '',
                        '{title}' => $title!=='' ? ' title="'.esc_attr($title).'"' : '',
                        '{aria}'  => $aria!=='' ? ' aria-label="'.esc_attr($aria).'"' : '',
                        '{class}' => $class!=='' ? ' class="'.esc_attr($class).'"' : '',
                    ));
                });
                if ( $content2 !== $content ) return $content2;
            }
        }

        return $content;
    }

    public function frontend_assets(){
        $handle = 'beeclear-ilm-frontend';
        wp_register_style($handle, false, array(), self::VERSION);
        $css_block = "/* BeeClear ILM default styles */\n.beeclear-ilm-link { text-decoration: underline; }\n.beeclear-ilm-link--highlight { font-weight: bold; }\n";
        wp_enqueue_style($handle);
        wp_add_inline_style($handle, $css_block);
    }

    public function render_timing_log_script(){
        if ( is_admin() ) return;

        $settings = get_option(self::OPT_SETTINGS, array());
        if ( empty($settings['log_internal_timing']) ) return;
        if ( $this->autolink_timing_ms <= 0 ) return;

        $ms = round($this->autolink_timing_ms, 2);
        /* translators: %.2f: time in milliseconds added by internal linking. */
        printf('<script>console.log("%s");</script>', esc_js(sprintf(__('Internal linking added %.2f ms to render time.', 'internal-external-link-manager-premium'), $ms)));
    }

    public function render_dashboard(){
        if ( ! current_user_can('manage_options') ) return;

        $this->rebuild_index();
        $settings = get_option(self::OPT_SETTINGS, array());
        $scan_summary = $this->get_scan_summary();

        if ( isset($_POST['beeclear_ilm_reindex_now']) && check_admin_referer(self::NONCE, self::NONCE) ){
            $index = $this->rebuild_index();
            if ( $this->last_rebuild_error !== '' ) {
                echo '<div class="notice notice-error"><p>'.esc_html($this->last_rebuild_error).'</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>'.esc_html__('Index rebuilt.', 'internal-external-link-manager-premium').'</p></div>';
                $this->log_activity(__('Index rebuilt from dashboard.', 'internal-external-link-manager-premium'));
            }
        }

        if(isset($_POST['beeclear_ilm_clear_data']) && check_admin_referer(self::NONCE, self::NONCE)){
            update_option(self::OPT_INDEX, array(), false);
            update_option(self::OPT_LINKMAP, array(), false);
            update_option(self::OPT_EXTERNAL_MAP, array(), false);
            delete_option(self::OPT_OVERVIEW_SCAN_SUMMARY);
            delete_option(self::OPT_OVERVIEW_SCAN);
            $this->rebuild_index();
            if ( $this->last_rebuild_error !== '' ) {
                echo '<div class="notice notice-error"><p>'.esc_html($this->last_rebuild_error).'</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>'.esc_html__('Data cleared and index rebuilt.', 'internal-external-link-manager-premium').'</p></div>';
                $this->log_activity(__('Data cleared and index rebuilt from dashboard.', 'internal-external-link-manager-premium'));
            }
        }

                if ( isset($_POST['beeclear_ilm_purge_db']) && check_admin_referer(self::NONCE, self::NONCE) ){
                        $this->purge_database();
                        delete_option(self::OPT_OVERVIEW_SCAN_SUMMARY);
                        delete_option(self::OPT_OVERVIEW_SCAN);
                        $this->rebuild_index();
                        if ( $this->last_rebuild_error !== '' ) {
                            echo '<div class="notice notice-error"><p>'.esc_html($this->last_rebuild_error).'</p></div>';
                        } else {
                            echo '<div class="notice notice-success"><p>'.esc_html__('Database purged. Index rebuilt.', 'internal-external-link-manager-premium').'</p></div>';
                            $this->log_activity(__('Database purged and index rebuilt from dashboard.', 'internal-external-link-manager-premium'));
                        }
                }

        $s = wp_parse_args($settings, array(
            'rel'=>'nofollow','title_mode'=>'phrase','title_custom'=>'','aria_mode'=>'phrase','aria_custom'=>'',
            'default_class'=>'beeclear-ilm-link','max_per_target'=>1,'max_total_per_page'=>0,
            'process_post_types'=>array('post','page'),'min_content_length'=>200,'min_element_length'=>20,
            'link_template'=>'<a href="{url}"{rel}{title}{aria}{class}>{text}</a>',
            'clean_on_uninstall'=>false,'clean_on_deactivation'=>false,'process_on_archives'=>false,
            'skip_elements_internal'=>'','skip_elements_external'=>'','cross_inline'=>false,
            'log_internal_timing'=>false,
            'auto_scan_on_save'=>false,
            'activity_log_limit'=>''
        ));
        $summary_now = $this->summarize_index(get_option(self::OPT_INDEX, array()));

        echo '<div class="wrap">';
        echo '<h1>'.esc_html__('Internal Link Manager (BeeClear) — Global settings', 'internal-external-link-manager-premium').'</h1>';
        echo wp_kses_post($this->render_author_note());

        echo '<div class="beeclear-grid beeclear-grid--with-sidebar">';

        echo '<div class="ilm-wrap">';

        echo '<form method="post" action="options.php" class="beeclear-form">';
        settings_fields('beeclear_ilm_group');

        echo '<div class="beeclear-card">';
        echo '<h2 class="ilm-section-title"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>'.esc_html__('Global settings', 'internal-external-link-manager-premium').'</h2>';
        echo '<p class="description">'.esc_html__('Fine-tune how automatic links look, where they appear, and how the plugin maintains its data.', 'internal-external-link-manager-premium').'</p>';
        echo '</div>';

        echo '<div class="beeclear-card">';
        echo '<h3 class="ilm-section-title"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span>'.esc_html__('Link output defaults', 'internal-external-link-manager-premium').'</h3>';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th>'.esc_html__('rel attribute (default)', 'internal-external-link-manager-premium').'</th><td><input type="text" name="' . esc_attr( self::OPT_SETTINGS ) . '[rel]" value="'.esc_attr($s['rel']).'" class="regular-text"><span class="inline-help">'.esc_html__('e.g. nofollow, sponsored, noopener', 'internal-external-link-manager-premium').'</span></td></tr>';

        echo '<tr><th>'.esc_html__('Default Title & Aria for INTERNAL links', 'internal-external-link-manager-premium').'</th><td>';
        echo '<div class="ilm-field-grid">';
        echo '<label>'.esc_html__('Title', 'internal-external-link-manager-premium').'<br><select name="' . esc_attr( self::OPT_SETTINGS ) . '[title_mode]">';
        foreach(array('none','phrase','post_title','custom') as $mode){ printf('<option value="%1$s"%2$s>%1$s</option>', esc_attr($mode), selected($s['title_mode'],$mode,false)); }
        echo '</select></label>';
        echo '<label>'.esc_html__('Aria-label', 'internal-external-link-manager-premium').'<br><select name="' . esc_attr( self::OPT_SETTINGS ) . '[aria_mode]">';
        foreach(array('none','phrase','post_title','custom') as $mode){ printf('<option value="%1$s"%2$s>%1$s</option>', esc_attr($mode), selected($s['aria_mode'],$mode,false)); }
        echo '</select></label></div>';

        echo '<div class="ilm-field-grid">';
        echo '<input type="text" name="' . esc_attr( self::OPT_SETTINGS ) . '[title_custom]" value="'.esc_attr($s['title_custom']).'" placeholder="'.esc_attr__('Custom title text', 'internal-external-link-manager-premium').'" class="regular-text">';
        echo '<input type="text" name="' . esc_attr( self::OPT_SETTINGS ) . '[aria_custom]" value="'.esc_attr($s['aria_custom']).'" placeholder="'.esc_attr__('Custom aria-label', 'internal-external-link-manager-premium').'" class="regular-text">';
        echo '</div>';
        echo '<span class="inline-help">'.esc_html__('External links define their own Title/Aria per rule.', 'internal-external-link-manager-premium').'</span>';
        echo '</td></tr>';

        echo '<tr><th>'.esc_html__('Default CSS class', 'internal-external-link-manager-premium').'</th><td><input type="text" name="' . esc_attr( self::OPT_SETTINGS ) . '[default_class]" value="'.esc_attr($s['default_class']).'" class="regular-text"></td></tr>';
        echo '<tr><th>'.esc_html__('Link template', 'internal-external-link-manager-premium').'</th><td><textarea name="' . esc_attr( self::OPT_SETTINGS ) . '[link_template]" rows="3" class="large-text code">'.esc_textarea($s['link_template']).'</textarea><span class="inline-help">'.esc_html__('Placeholders: {url} {text} {rel} {title} {aria} {class}. Empty attributes are omitted.', 'internal-external-link-manager-premium').'</span></td></tr>';
        echo '<tr><th>'.esc_html__('Cross-inline (formatting tags)', 'internal-external-link-manager-premium').'</th><td><label><input type="checkbox" name="' . esc_attr( self::OPT_SETTINGS ) . '[cross_inline]" value="1" '.checked(!empty($s['cross_inline']), true, false).'> '.esc_html__('Allow matching across u, i/em, b/strong, mark (literal phrases only).', 'internal-external-link-manager-premium').'</label><span class="inline-help">'.esc_html__('Lets a phrase span across simple formatting tags. Example: "<strong>Word</strong>Press".', 'internal-external-link-manager-premium').'</span></td></tr>';
        echo '</table>';
        echo '</div>';

        echo '<div class="beeclear-card">';
        echo '<h3 class="ilm-section-title"><span class="dashicons dashicons-visibility" aria-hidden="true"></span>'.esc_html__('Placement & targeting', 'internal-external-link-manager-premium').'</h3>';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th>'.esc_html__('Skip elements (INTERNAL)', 'internal-external-link-manager-premium').'</th><td><input type="text" name="' . esc_attr( self::OPT_SETTINGS ) . '[skip_elements_internal]" value="'.esc_attr($s['skip_elements_internal']).'" class="regular-text" placeholder="h2 - li - h4"><span class="inline-help">'.esc_html__('Do not inject INTERNAL links inside these HTML tags. Separate with dashes, commas or spaces. Example: h2 - li - h4', 'internal-external-link-manager-premium').'</span></td></tr>';
        echo '<tr><th>'.esc_html__('Skip elements (EXTERNAL)', 'internal-external-link-manager-premium').'</th><td><input type="text" name="' . esc_attr( self::OPT_SETTINGS ) . '[skip_elements_external]" value="'.esc_attr($s['skip_elements_external']).'" class="regular-text" placeholder="h2 - li - h4"><span class="inline-help">'.esc_html__('Do not inject EXTERNAL links inside these HTML tags. Separate with dashes, commas or spaces. Example: h2 - li - h4', 'internal-external-link-manager-premium').'</span></td></tr>';
        echo '<tr><th>'.esc_html__('Process INTERNAL post types', 'internal-external-link-manager-premium').'</th><td>';
        echo '<div class="ilm-checkbox-grid">';
        $pts = get_post_types(array('public'=>true),'objects');
        foreach($pts as $pt){
            printf('<label class="ilm-checkbox"><input type="checkbox" name="%1$s[process_post_types][]" value="%2$s"%3$s> %4$s</label>',
                esc_attr(self::OPT_SETTINGS),
                esc_attr($pt->name),
                checked(in_array($pt->name,(array)$s['process_post_types'],true), true, false),
                esc_html($pt->labels->singular_name)
            );
        }
        echo '</div>';
        echo '</td></tr>';
        echo '<tr><th>'.esc_html__('Process INTERNAL links on archives (lists)', 'internal-external-link-manager-premium').'</th><td><label><input type="checkbox" name="' . esc_attr( self::OPT_SETTINGS ) . '[process_on_archives]" value="1" '.checked($s['process_on_archives'], true, false).'> '.esc_html__('Enable (may add load).', 'internal-external-link-manager-premium').'</label></td></tr>';
        echo '<tr><th>'.esc_html__('Minimum content length to process', 'internal-external-link-manager-premium').'</th><td><input type="number" min="0" name="' . esc_attr( self::OPT_SETTINGS ) . '[min_content_length]" value="'.esc_attr($s['min_content_length']).'"></td></tr>';
        echo '<tr><th>'.esc_html__('Minimum element length to process', 'internal-external-link-manager-premium').'</th><td><input type="number" min="0" name="' . esc_attr( self::OPT_SETTINGS ) . '[min_element_length]" value="'.esc_attr($s['min_element_length']).'"><span class="inline-help">'.esc_html__('Skip paragraphs, headings, or list items shorter than this when autolinking.', 'internal-external-link-manager-premium').'</span></td></tr>';
        echo '</table>';
        echo '</div>';

        echo '<div class="beeclear-card">';
        echo '<h3 class="ilm-section-title"><span class="dashicons dashicons-filter" aria-hidden="true"></span>'.esc_html__('Limits & caps', 'internal-external-link-manager-premium').'</h3>';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th>'.esc_html__('Max links per INTERNAL target (per page)', 'internal-external-link-manager-premium').'</th><td><input type="number" min="0" name="' . esc_attr( self::OPT_SETTINGS ) . '[max_per_target]" value="'.esc_attr($s['max_per_target']).'"><span class="inline-help">'.esc_html__('0 = unlimited', 'internal-external-link-manager-premium').'</span></td></tr>';
        echo '<tr><th>'.esc_html__('Max TOTAL links injected per page (internal + external)', 'internal-external-link-manager-premium').'</th><td><input type="number" min="0" name="' . esc_attr( self::OPT_SETTINGS ) . '[max_total_per_page]" value="'.esc_attr($s['max_total_per_page']).'"><span class="inline-help">'.esc_html__('0 = unlimited', 'internal-external-link-manager-premium').'</span></td></tr>';
        echo '</table>';
        echo '</div>';

        echo '<div class="beeclear-card">';
        echo '<h3 class="ilm-section-title"><span class="dashicons dashicons-update" aria-hidden="true"></span>'.esc_html__('Automation & logs', 'internal-external-link-manager-premium').'</h3>';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th>'.esc_html__('Auto-run scan after saving content', 'internal-external-link-manager-premium').'</th><td><label><input type="checkbox" name="' . esc_attr( self::OPT_SETTINGS ) . '[auto_scan_on_save]" value="1" '.checked(!empty($s['auto_scan_on_save']), true, false).'> '.esc_html__('Trigger “Scan site” whenever a supported post/page is added or updated.', 'internal-external-link-manager-premium').'</label><span class="inline-help">'.esc_html__('Runs a full overview scan (including external links) after publishing updates.', 'internal-external-link-manager-premium').'</span></td></tr>';
        echo '<tr><th>'.esc_html__('Auto-run scan after saving external rules', 'internal-external-link-manager-premium').'</th><td><label><input type="checkbox" name="' . esc_attr( self::OPT_SETTINGS ) . '[auto_scan_on_external]" value="1" '.checked(!empty($s['auto_scan_on_external']), true, false).'> '.esc_html__('Trigger “Scan site” whenever external linking rules are added or updated.', 'internal-external-link-manager-premium').'</label><span class="inline-help">'.esc_html__('Starts an overview scan after saving external link destinations to capture new outbound links.', 'internal-external-link-manager-premium').'</span></td></tr>';
        echo '<tr><th>'.esc_html__('Activity log size', 'internal-external-link-manager-premium').'</th><td><input type="number" min="1" name="' . esc_attr( self::OPT_SETTINGS ) . '[activity_log_limit]" value="'.esc_attr($s['activity_log_limit']).'" placeholder="'.esc_attr__('Unlimited', 'internal-external-link-manager-premium').'">';
        echo '<span class="inline-help">'.esc_html__('Limit how many entries are kept in the activity log. Leave empty for no limit.', 'internal-external-link-manager-premium').'</span></td></tr>';
        echo '<tr><th>'.esc_html__('Debug: log autolink timing', 'internal-external-link-manager-premium').'</th><td><label><input type="checkbox" name="' . esc_attr( self::OPT_SETTINGS ) . '[log_internal_timing]" value="1" '.checked(!empty($s['log_internal_timing']), true, false).'> '.esc_html__('Console-log extra render time added by internal linking logic.', 'internal-external-link-manager-premium').'</label><span class="inline-help">'.esc_html__('Shows how many milliseconds were spent in autolinking during this page load (front-end only).', 'internal-external-link-manager-premium').'</span></td></tr>';
        echo '</table>';
        echo '</div>';

        echo '<div class="beeclear-card">';
        echo '<h3 class="ilm-section-title"><span class="dashicons dashicons-shield" aria-hidden="true"></span>'.esc_html__('Cleanup preferences', 'internal-external-link-manager-premium').'</h3>';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th>'.esc_html__('Clean all plugin data on uninstall', 'internal-external-link-manager-premium').'</th><td><label><input type="checkbox" name="' . esc_attr( self::OPT_SETTINGS ) . '[clean_on_uninstall]" value="1" '.checked($s['clean_on_uninstall'], true, false).'> '.esc_html__('Yes, remove data on uninstall', 'internal-external-link-manager-premium').'</label></td></tr>';
        echo '<tr><th>'.esc_html__('Clean runtime data on deactivation', 'internal-external-link-manager-premium').'</th><td><label><input type="checkbox" name="' . esc_attr( self::OPT_SETTINGS ) . '[clean_on_deactivation]" value="1" '.checked($s['clean_on_deactivation'], true, false).'> '.esc_html__('Clear index & counters when plugin is deactivated', 'internal-external-link-manager-premium').'</label></td></tr>';
        echo '</table>';
        echo '</div>';

        echo '<div class="beeclear-card">';
        submit_button();
        echo '</div>';

        echo '</form>';

        echo '</div>';

        echo '<div class="ilm-wrap">';

        echo '<div class="beeclear-card">';
        echo '<h2 class="ilm-section-title"><span class="dashicons dashicons-controls-repeat" aria-hidden="true"></span>'.esc_html__('Actions', 'internal-external-link-manager-premium').'</h2>';
        echo '<div class="beeclear-actions">';
        echo '<div class="beeclear-actions__row">';
        echo '<form method="post" class="beeclear-inline-action">';
        wp_nonce_field(self::NONCE, self::NONCE);
        echo '<button class="button button-primary" name="beeclear_ilm_reindex_now" value="1">'.esc_html__('Rebuild index now', 'internal-external-link-manager-premium').'</button>';
        echo '</form>';
        echo '<button type="button" class="button button-secondary" id="beeclear-ilm-start-overview-scan">'.esc_html__('Scan site to refresh overview', 'internal-external-link-manager-premium').'</button>';
        echo '<div class="beeclear-progress beeclear-progress--inline" id="beeclear-ilm-progress" aria-live="polite" hidden>';
        echo '<div class="beeclear-progress__track"><div class="beeclear-progress__bar" style="width:0%"></div></div>';
        echo '<div class="beeclear-progress__label">'.esc_html__('Idle — no scan in progress.', 'internal-external-link-manager-premium').'</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '<div class="beeclear-card">';
        echo '<h2 class="ilm-section-title"><span class="dashicons dashicons-visibility" aria-hidden="true"></span>'.esc_html__('Last scan summary', 'internal-external-link-manager-premium').'</h2>';
        echo '<div id="beeclear-ilm-scan-summary">'.wp_kses_post($this->render_scan_summary_html()).'</div>';
        echo '</div>';

        echo '<div class="beeclear-card">';
        echo '<h2 class="ilm-section-title"><span class="dashicons dashicons-admin-tools" aria-hidden="true"></span>'.esc_html__('Maintenance', 'internal-external-link-manager-premium').'</h2>';
        echo '<div class="beeclear-actions beeclear-actions--stacked">';

        echo '<form method="post" class="beeclear-inline-form">';
        wp_nonce_field(self::NONCE, self::NONCE);
        echo '<label><input type="checkbox" name="confirm" required> '.esc_html__('Confirm clearing runtime data (index & linkmap).', 'internal-external-link-manager-premium').'</label>';
        echo '<button class="button button-secondary" name="beeclear_ilm_clear_data" value="1">'.esc_html__('Clear data & rebuild index', 'internal-external-link-manager-premium').'</button>';
        echo '</form>';

        echo '<form method="post" class="beeclear-inline-form">';
        wp_nonce_field(self::NONCE, self::NONCE);
        echo '<label><input type="checkbox" name="confirm" required> ';
        echo esc_html__('Confirm full cleanup: remove internal rules (per post), external rules, link counters & index.', 'internal-external-link-manager-premium');
        echo '</label>';
        echo '<button class="button button-secondary" name="beeclear_ilm_purge_db" value="1">'.esc_html__('Purge database (ILM) & rebuild index', 'internal-external-link-manager-premium').'</button>';
        echo '</form>';

        echo '</div>';
        echo '</div>';

        echo '<div class="beeclear-card">';
        echo '<h2 class="ilm-section-title"><span class="dashicons dashicons-media-text" aria-hidden="true"></span>'.esc_html__('Activity log', 'internal-external-link-manager-premium').'</h2>';
        echo wp_kses_post($this->render_activity_log_html());
        echo '</div>';

        echo '</div>';

        echo '</div>';
        echo '</div>';
    }

    private function summarize_index($index){
        $targets = array();
        foreach((array)$index as $r){
            $t = (int)$r['target'];
            $targets[$t] = ($targets[$t] ?? 0) + 1;
        }
        $total_phrases = array_sum($targets);
        $total_targets = count($targets);
        arsort($targets);
        return array('total_phrases'=> (int)$total_phrases,'total_targets'=> (int)$total_targets,'per_target'=>$targets);
    }
    private function render_index_summary_html($summary){
        if(!$summary) return '';
        $out  = '<p>';
        $out .= esc_html__('Total phrases:', 'internal-external-link-manager-premium').' <strong>'.intval($summary['total_phrases']).'</strong> &nbsp; ';
        $out .= esc_html__('Targets with phrases:', 'internal-external-link-manager-premium').' <strong>'.intval($summary['total_targets']).'</strong>';
        $out .= '</p>';
        if(!empty($summary['per_target'])){
            $out .= '<details><summary>'.esc_html__('Per target breakdown (top 50):', 'internal-external-link-manager-premium').'</summary>';
            $out .= '<table class="widefat striped" style="margin-top:8px"><thead><tr><th>ID</th><th>'.esc_html__('Title', 'internal-external-link-manager-premium').'</th><th>'.esc_html__('# phrases', 'internal-external-link-manager-premium').'</th></tr></thead><tbody>';
            $i=0;
            foreach($summary['per_target'] as $tid=>$cnt){
                if($i++>=50) break;
                $title = get_the_title($tid);
                $out .= '<tr><td>'.intval($tid).'</td><td>'.esc_html($title).'</td><td>'.intval($cnt).'</td></tr>';
            }
            $out .= '</tbody></table></details>';
        }
        return $out;
    }

    private function render_scan_summary_html(){
        $summary = $this->get_scan_summary();
        if ( empty($summary['completed_at']) ) {
            return '<p class="description">'.esc_html__('No scan has been completed yet.', 'internal-external-link-manager-premium').'</p>';
        }

        $completed = date_i18n(get_option('date_format').' '.get_option('time_format'), (int) $summary['completed_at']);

        $out  = '<div class="beeclear-scan-summary">';
        $out .= '<p><strong>'.esc_html__('Last scan', 'internal-external-link-manager-premium').':</strong> '.esc_html($completed).'</p>';
        $out .= '<ul>';
        $out .= '<li>'.esc_html__('Scanned pages', 'internal-external-link-manager-premium').': <strong>'.intval($summary['scanned']).'</strong></li>';
        $out .= '<li>'.esc_html__('Internal links in index', 'internal-external-link-manager-premium').': <strong>'.intval($summary['internal_links']).'</strong></li>';
        $out .= '<li>'.esc_html__('External links in index', 'internal-external-link-manager-premium').': <strong>'.intval($summary['external_links']).'</strong></li>';
        $out .= '</ul>';
        $out .= '</div>';

        return $out;
    }

    private function render_activity_log_entries_html($entries){
        if ( empty($entries) ) {
            return '<div class="beeclear-card__logs" role="log" aria-live="polite"><p class="description">'.esc_html__('No log entries yet.', 'internal-external-link-manager-premium').'</p></div>';
        }

        $out = '<div class="beeclear-card__logs" role="log" aria-live="polite">';
        foreach ( $entries as $entry ) {
            $time = isset($entry['time']) ? (int) $entry['time'] : 0;
            $message = isset($entry['message']) ? $entry['message'] : '';
            $formatted = $time ? date_i18n(get_option('date_format').' '.get_option('time_format'), $time) : '';
            $out .= '<div class="beeclear-log-entry">';
            if ( $formatted ) {
                $out .= '<span class="beeclear-log-entry__time">'.esc_html($formatted).'</span>';
            }
            $out .= '<span class="beeclear-log-entry__message">'.esc_html($message).'</span>';
            $out .= '</div>';
        }
        $out .= '</div>';

        return $out;
    }

    private function render_activity_log_pagination_html($page, $total_pages){
        $page = max(1, (int) $page);
        $total_pages = max(1, (int) $total_pages);

        if ( $total_pages <= 1 ) {
            return '';
        }

        $prev_disabled = $page <= 1 ? ' disabled' : '';
        $next_disabled = $page >= $total_pages ? ' disabled' : '';

        $out  = '<div class="beeclear-log-pagination" aria-label="'.esc_attr__('Activity log pagination', 'internal-external-link-manager-premium').'">';
        $out .= '<button type="button" class="button" data-log-page="'.intval(max(1, $page - 1)).'"'. $prev_disabled .'>'.esc_html__('Previous', 'internal-external-link-manager-premium').'</button>';
        /* translators: 1: current page number, 2: total number of pages. */
        $out .= '<span class="beeclear-log-pagination__status">'.esc_html( sprintf( __('Page %1$d of %2$d', 'internal-external-link-manager-premium'), $page, $total_pages ) ).'</span>';
        $out .= '<button type="button" class="button" data-log-page="'.intval(min($total_pages, $page + 1)).'"'. $next_disabled .'>'.esc_html__('Next', 'internal-external-link-manager-premium').'</button>';
        $out .= '</div>';

        return $out;
    }

    private function render_activity_log_html($page = 1, $per_page = 50){
        $page_data = $this->get_activity_log_page($page, $per_page);
        $total_pages = max(1, (int) ceil(($page_data['total'] ?: 0) / $per_page));
        $page = min(max(1, (int) $page), $total_pages);

        $out  = '<div id="beeclear-ilm-log" class="beeclear-card__logs-wrap" data-per-page="'.intval($per_page).'" data-total-pages="'.intval($total_pages).'" data-current-page="'.intval($page).'">';
        $out .= $this->render_activity_log_entries_html($page_data['entries']);
        $out .= $this->render_activity_log_pagination_html($page, $total_pages);
        $out .= '</div>';

        return $out;
    }

    private function build_external_overview_rows(){
        $rules = get_option(self::OPT_EXT_RULES, array());
        $map   = get_option(self::OPT_EXTERNAL_MAP, array());

        $rows = array();
        foreach ((array) $rules as $idx => $rule){
            if ( ! is_array($rule) ) continue;
            $url = isset($rule['url']) ? trim((string) $rule['url']) : '';
            if ($url === '') continue;

            $phrase = isset($rule['phrase']) ? (string) $rule['phrase'] : '';
            if (!isset($rows[$url])){
                $rows[$url] = array(
                    'id'          => md5($url),
                    'url'         => $url,
                    'title'       => $url,
                    'perma'       => $url,
                    'edit'        => '',
                    'phrases'     => array(),
                    'link_count'  => 0,
                    'sources'     => array(),
                );
            }

            if ($phrase !== ''){
                $rows[$url]['phrases'][$phrase] = true;
            }

            $map_entry = isset($map[$idx]) && is_array($map[$idx]) ? $map[$idx] : array();
            $rows[$url]['link_count'] += isset($map_entry['count']) ? (int) $map_entry['count'] : 0;

            $sources = isset($map_entry['sources']) && is_array($map_entry['sources']) ? $map_entry['sources'] : array();
            foreach ($sources as $sid => $info){
                $source_id = (int) $sid;
                if ($source_id <= 0) continue;

                $source_count   = is_array($info) ? (int) ($info['count'] ?? 0) : (int) $info;
                $source_phrases = is_array($info) ? (array) ($info['phrases'] ?? array()) : array();
                $source_contexts = is_array($info) ? (array) ($info['contexts'] ?? array()) : array();

                if (!isset($rows[$url]['sources'][$source_id])){
                    $rows[$url]['sources'][$source_id] = array('count' => 0, 'phrases' => array(), 'contexts' => array());
                }
                $rows[$url]['sources'][$source_id]['count'] += $source_count;

                foreach ($source_phrases as $ph => $pc){
                    $rows[$url]['sources'][$source_id]['phrases'][(string)$ph] = ($rows[$url]['sources'][$source_id]['phrases'][(string)$ph] ?? 0) + (int) $pc;
                }
                if ($phrase !== ''){
                    $rows[$url]['sources'][$source_id]['phrases'][$phrase] = ($rows[$url]['sources'][$source_id]['phrases'][$phrase] ?? 0) + (int) $source_count;
                }

                $existing_contexts = isset($rows[$url]['sources'][$source_id]['contexts']) ? (array)$rows[$url]['sources'][$source_id]['contexts'] : array();
                $rows[$url]['sources'][$source_id]['contexts'] = $this->merge_contexts($existing_contexts, $source_contexts);
            }
        }

        foreach ($rows as &$row){
            $row['phrases'] = array_keys($row['phrases']);
            $row['phrases_count'] = count($row['phrases']);
        }
        unset($row);

        return array_values($rows);
    }

    private function render_overview_table(){
        $index = get_option(self::OPT_INDEX, array());
        $map   = get_option(self::OPT_LINKMAP, array());

        $allowed_views = array('targets', 'sources', 'external');
        $nonce_valid = isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), self::NONCE);
        $view_param = $nonce_valid && isset($_GET['ilm_view']) ? sanitize_text_field(wp_unslash($_GET['ilm_view'])) : 'targets';
        $view = in_array($view_param, $allowed_views, true) ? $view_param : 'targets';
        $per_page = 50;
        $page_raw = $nonce_valid && isset($_GET['ilm_page']) ? sanitize_text_field(wp_unslash($_GET['ilm_page'])) : 1;
        $page = max(1, (int) $page_raw);
        $search_raw = $nonce_valid && isset($_GET['ilm_q']) ? sanitize_text_field( wp_unslash($_GET['ilm_q']) ) : '';
        $search = $search_raw;
        $search_lower = function_exists('mb_strtolower') ? mb_strtolower($search, 'UTF-8') : strtolower($search);

        $phrases_per_target = array();
        $phrases_list = array();
        foreach($index as $r){
            $t = (int)$r['target'];
            $phrases_per_target[$t] = ($phrases_per_target[$t] ?? 0) + 1;
            $phrases_list[$t][] = $r;
        }

        $rows = array();
        if ($view === 'targets'){
            if(empty($phrases_per_target)){
                echo '<p>'.esc_html__('No phrases defined yet.', 'internal-external-link-manager-premium').'</p>';
                return;
            }
            foreach ($phrases_per_target as $tid => $cnt) {
                $rows[] = array(
                    'id'       => $tid,
                    'title'    => get_the_title($tid),
                    'perma'    => get_permalink($tid),
                    'edit'     => get_edit_post_link($tid),
                    'phrases'  => $phrases_list[$tid] ?? array(),
                    'phrases_count' => (int) $cnt,
                    'inbound'  => ( isset($map[$tid]['sources']) && is_array($map[$tid]['sources']) )
                        ? count($map[$tid]['sources'])
                        : 0,
                );
            }
        } elseif ($view === 'sources') {
            foreach((array)$map as $target_id => $entry){
                $sources = isset($entry['sources']) && is_array($entry['sources']) ? $entry['sources'] : array();
                foreach($sources as $source_id => $info){
                    if (!isset($rows[$source_id])){
                        $rows[$source_id] = array(
                            'id'       => $source_id,
                            'title'    => get_the_title($source_id),
                            'perma'    => get_permalink($source_id),
                            'edit'     => get_edit_post_link($source_id),
                            'targets'  => array(),
                            'phrases'  => array(),
                            'outbound' => 0,
                        );
                    }

                    $count = is_array($info) ? (int)($info['count'] ?? 0) : (int)$info;
                    $rows[$source_id]['outbound'] += $count;

                    $phrases = is_array($info) ? (array)($info['phrases'] ?? array()) : array();
                    foreach($phrases as $ph=>$pc){
                        $rows[$source_id]['phrases'][$ph] = ($rows[$source_id]['phrases'][$ph] ?? 0) + (int)$pc;
                    }

                    $contexts = is_array($info) ? (array)($info['contexts'] ?? array()) : array();
                    $rows[$source_id]['targets'][$target_id] = array(
                        'phrases'  => $phrases,
                        'contexts' => $contexts,
                    );
                }
            }
            $rows = array_values($rows);
            foreach ($rows as &$row){
                $row['phrases_count'] = count($row['phrases']);
            }
            unset($row);

            if (empty($rows)){
                echo '<p>'.esc_html__('No links recorded yet.', 'internal-external-link-manager-premium').'</p>';
                return;
            }
        } else {
            $rows = $this->build_external_overview_rows();
            if (empty($rows)){
                echo '<p>'.esc_html__('No external rules defined yet.', 'internal-external-link-manager-premium').'</p>';
                return;
            }
        }

        usort($rows, function($a, $b){
            $at = isset($a['title']) ? (string) $a['title'] : '';
            $bt = isset($b['title']) ? (string) $b['title'] : '';
            return strcasecmp($at, $bt);
        });

        if ($search !== ''){
            $rows = array_values(array_filter($rows, function($row) use ($search_lower, $view){
                $fields = array((string)($row['title'] ?? ''), (string)($row['perma'] ?? ''));
                if ($view === 'targets'){
                    foreach((array)$row['phrases'] as $r){
                        if (isset($r['phrase'])) $fields[] = (string)$r['phrase'];
                        if (!empty($r['context']) && is_array($r['context'])){
                            foreach($r['context'] as $ctx){
                                if (is_string($ctx)) $fields[] = $ctx;
                            }
                        }
                    }
                } elseif ($view === 'sources') {
                    foreach((array)$row['phrases'] as $ph => $pc){
                        $fields[] = (string)$ph;
                    }
                    foreach((array)$row['targets'] as $tid => $info){
                        $fields[] = (string) get_the_title($tid);
                        $fields[] = (string) get_permalink($tid);
                        $contexts = isset($info['contexts']) ? (array)$info['contexts'] : array();
                        foreach($contexts as $ctx){
                            if (is_array($ctx) && isset($ctx['html'])){
                                $fields[] = (string)$ctx['html'];
                            }
                        }
                    }
                } else {
                    foreach((array)($row['phrases'] ?? array()) as $ph){
                        $fields[] = (string)$ph;
                    }
                    foreach((array)($row['sources'] ?? array()) as $sid => $info){
                        $fields[] = (string) get_the_title($sid);
                        $fields[] = (string) get_permalink($sid);
                        foreach((array)($info['phrases'] ?? array()) as $ph => $pc){
                            $fields[] = (string)$ph;
                        }
                    }
                }
                foreach($fields as $field){
                    $field_lower = function_exists('mb_strtolower') ? mb_strtolower($field, 'UTF-8') : strtolower($field);
                    if ($field_lower !== '' && strpos($field_lower, $search_lower) !== false) return true;
                }
                return false;
            }));
        }

        $total_items = count($rows);
        $total_pages = max(1, (int) ceil($total_items / $per_page));
        $page = min($page, $total_pages);
        $offset = ($page - 1) * $per_page;
        $paged_rows = array_slice($rows, $offset, $per_page);

        $nonce_param = wp_create_nonce(self::NONCE);
        $base_args = array('page' => 'beeclear-ilm-internal-overview', 'ilm_view' => $view, '_wpnonce' => $nonce_param);
        if ($search !== '') {
            $base_args['ilm_q'] = $search;
        }
        $base_url = add_query_arg($base_args, admin_url('admin.php'));
        $pagination_base = add_query_arg(array_merge($base_args, array('ilm_page' => '%#%')), admin_url('admin.php'));
        $pagination_links = paginate_links(array(
            'base'      => $pagination_base,
            'format'    => '',
            'current'   => $page,
            'total'     => $total_pages,
            'prev_text' => __('« Previous', 'internal-external-link-manager-premium'),
            'next_text' => __('Next »', 'internal-external-link-manager-premium'),
            'add_args'  => false,
        ));

        $toggle_target_url = wp_nonce_url(add_query_arg(array_merge($base_args, array('ilm_view' => 'targets', 'ilm_page' => 1)), admin_url('admin.php')), self::NONCE);
        $toggle_source_url = wp_nonce_url(add_query_arg(array_merge($base_args, array('ilm_view' => 'sources', 'ilm_page' => 1)), admin_url('admin.php')), self::NONCE);
        $toggle_external_url = wp_nonce_url(add_query_arg(array_merge($base_args, array('ilm_view' => 'external', 'ilm_page' => 1)), admin_url('admin.php')), self::NONCE);

        echo '<div class="beeclear-ilm-overview-controls">';
        echo '<div class="beeclear-ilm-view-toggle" role="group" aria-label="'.esc_attr__('Change overview layout', 'internal-external-link-manager-premium').'">';
        echo '<a class="button'.($view === 'targets' ? ' button-primary' : '').'" href="'.esc_url($toggle_target_url).'">'.esc_html__('By target', 'internal-external-link-manager-premium').'</a>';
        echo '<a class="button'.($view === 'sources' ? ' button-primary' : '').'" href="'.esc_url($toggle_source_url).'">'.esc_html__('By source', 'internal-external-link-manager-premium').'</a>';
        echo '<a class="button'.($view === 'external' ? ' button-primary' : '').'" href="'.esc_url($toggle_external_url).'">'.esc_html__('External targets', 'internal-external-link-manager-premium').'</a>';
        echo '</div>';
        echo '<form method="get" class="beeclear-ilm-filter">';
        echo '<input type="hidden" name="page" value="'.esc_attr('beeclear-ilm-internal-overview').'">';
        echo '<input type="hidden" name="ilm_view" value="'.esc_attr($view).'">';
        echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($nonce_param).'">';
        echo '<label for="beeclear-ilm-filter" class="screen-reader-text">'.esc_html__('Search', 'internal-external-link-manager-premium').'</label>';
        echo '<input id="beeclear-ilm-filter" type="search" name="ilm_q" value="'.esc_attr($search).'" placeholder="'.esc_attr__('Title, URL, phrase, or rule', 'internal-external-link-manager-premium').'"> ';
        echo '<button class="button" type="submit">'.esc_html__('Filter', 'internal-external-link-manager-premium').'</button> ';
        if ($search !== ''){
            echo '<a class="button" href="'.esc_url($base_url).'">'.esc_html__('Reset', 'internal-external-link-manager-premium').'</a>';
        }
        echo '</form>';
        echo '<div class="beeclear-ilm-overview-meta">';
        echo '<span class="displaying-num">'.intval($total_items).' '.esc_html__('items', 'internal-external-link-manager-premium').'</span>';
        if ($pagination_links){
            echo '<span class="pagination-links">'.wp_kses_post($pagination_links).'</span>';
        }
        echo '</div>';
        echo '</div>';

        if (empty($paged_rows)){
            echo '<p>'.esc_html__('No results match your search.', 'internal-external-link-manager-premium').'</p>';
            return;
        }

        echo '<div class="beeclear-ilm-overview-table-wrap">';
        echo '<table id="beeclear-ilm-ext-table" class="widefat striped beeclear-ilm-overview"><thead><tr>';
        $target_label = $view === 'targets' ? __('Target', 'internal-external-link-manager-premium') : ($view === 'sources' ? __('Source', 'internal-external-link-manager-premium') : __('External target', 'internal-external-link-manager-premium'));
        echo '<th class="col-target">'.esc_html($target_label).'</th>';
        echo '<th class="col-phrases">'.esc_html__('# phrases', 'internal-external-link-manager-premium').'</th>';
        $links_label = $view === 'targets' ? __('# inbound links', 'internal-external-link-manager-premium') : ($view === 'sources' ? __('# outbound links', 'internal-external-link-manager-premium') : __('# links', 'internal-external-link-manager-premium'));
        echo '<th class="col-inbound">'.esc_html($links_label).'</th>';
        $middle_label = $view === 'sources' ? __('Targets', 'internal-external-link-manager-premium') : __('Sources', 'internal-external-link-manager-premium');
        echo '<th class="'.esc_attr($view === 'sources' ? 'col-targets' : 'col-sources').'">'.esc_html($middle_label).'</th>';
        if ($view === 'targets' || $view === 'external'){
            echo '<th class="col-defined">'.esc_html__('Defined phrases', 'internal-external-link-manager-premium').'</th>';
        }
        echo '</tr></thead><tbody>';

        foreach($paged_rows as $row){
            $title = $row['title'];
            $perma = $row['perma'];
            $edit  = $row['edit'];

            $entry_id_attr = esc_attr((string)$row['id']);
            $btn = '<button type="button" class="button button-small beeclear-ilm-expand" data-entry="'.esc_attr($entry_id_attr).'" data-view="'.esc_attr($view).'">'.esc_html__('Show', 'internal-external-link-manager-premium').'</button>';
            $plist = '';
            if ($view === 'targets' && !empty($row['phrases'])){
                $items = array();
                foreach($row['phrases'] as $r){
                    $badge = !empty($r['regex']) ? 'regex' : (!empty($r['case']) ? 'case' : ($this->contains_tokens($r['phrase']) ? 'tokens' : ''));
                    $items[] = esc_html($r['phrase']).($badge? ' <span style="opacity:.7">['.esc_html($badge).']</span>' : '');
                }
                $plist = '<div style="max-width:380px; white-space:normal">'.implode(', ',$items).'</div>';
            } elseif ($view === 'external' && !empty($row['phrases'])){
                $items = array();
                foreach($row['phrases'] as $phrase){
                    $items[] = esc_html($phrase);
                }
                $plist = '<div style="max-width:380px; white-space:normal">'.implode(', ', $items).'</div>';
            }
            $container_id = 'beeclear-ilm-expansion-'.esc_attr($view).'-'.esc_attr($entry_id_attr);
            echo '<tr>';
            echo '<td class="col-target">'.($perma?'<a href="'.esc_url($perma).'" target="_blank" rel="noopener">':'').esc_html($title).($perma?'</a>':'');
            if ($edit){
                echo ' <a href="'.esc_url($edit).'" class="beeclear-ilm-edit" title="'.esc_attr__('Edit', 'internal-external-link-manager-premium').'"><span class="dashicons dashicons-edit"></span></a>';
            }
            echo '</td>';
            echo '<td class="col-phrases">'.(int)$row['phrases_count'].'</td>';
            if ($view === 'targets'){
                $link_count = (int)$row['inbound'];
            } elseif ($view === 'sources') {
                $link_count = (int)($row['outbound'] ?? 0);
            } else {
                $link_count = (int)($row['link_count'] ?? 0);
            }
            echo '<td class="col-inbound">'.esc_html($link_count).'</td>';
            $col_class = $view === 'sources' ? 'col-targets' : 'col-sources';
            echo '<td class="'.esc_attr($col_class).'">'.wp_kses_post($btn).'<div id="'.esc_attr($container_id).'" style="margin-top:6px"></div></td>';
            if ($view === 'targets' || $view === 'external'){
                echo '<td class="col-defined">'.wp_kses_post($plist).'</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';

        if ($pagination_links){
            echo '<div class="tablenav bottom" style="margin: 12px 0;">';
            echo '<div class="tablenav-pages">';
            echo '<span class="displaying-num">'.intval($total_items).' '.esc_html__('items', 'internal-external-link-manager-premium').'</span>';
            echo '<span class="pagination-links">'.wp_kses_post($pagination_links).'</span>';
            echo '</div>';
            echo '</div>';
        }
        echo '<script>
        jQuery(function($){
            $(document).on("click",".beeclear-ilm-expand", function(){
                var $b=$(this), id=$b.data("entry"), view=$b.data("view"), box=$("#beeclear-ilm-expansion-"+view+"-"+id);
                if(box.data("loaded")){ box.toggle(); return; }
                $b.prop("disabled",true).text("…");
                $.post(ajaxurl,{action:"beeclear_ilm_expand_sources", id:id, view:view, _ajax_nonce:"'.esc_js(wp_create_nonce(self::NONCE)).'"}, function(html){
                    box.html(html).data("loaded",true);
                }).always(function(){ $b.prop("disabled",false).text("'.esc_js(__('Toggle', 'internal-external-link-manager-premium')).'"); });
            });
        });
        </script>';
    }

    public function ajax_expand_sources(){
        check_ajax_referer(self::NONCE);
        if ( ! current_user_can('manage_options') ) wp_die();
        $raw_id = isset($_POST['id']) ? sanitize_text_field( wp_unslash($_POST['id']) ) : '';
        $view_param = isset($_POST['view']) ? sanitize_text_field(wp_unslash($_POST['view'])) : 'targets';
        $view = in_array($view_param, array('targets','sources','external'), true) ? $view_param : 'targets';
        $id = ($view === 'external') ? sanitize_text_field($raw_id) : (int) $raw_id;
        $map = get_option(self::OPT_LINKMAP, array());
        if ($view === 'sources'){
            $targets = array();
            foreach((array)$map as $target_id => $entry){
                $sources = isset($entry['sources']) ? (array)$entry['sources'] : array();
                if (!isset($sources[$id])) continue;
                $info = $sources[$id];
                $targets[$target_id] = is_array($info) ? $info : array('count' => (int)$info, 'phrases' => array(), 'contexts' => array());
            }
            if(empty($targets)){ echo '<em>'.esc_html__('No targets recorded yet.', 'internal-external-link-manager-premium').'</em>'; wp_die(); }

            $popup_registry = array();
            echo '<ul class="ilm-list">';
            foreach($targets as $tid=>$info){
                $t = get_the_title($tid);
                $perma = get_permalink($tid);
                $edit  = get_edit_post_link($tid);

                $phrases = is_array($info) ? (array)($info['phrases'] ?? array()) : array();
                $contexts = is_array($info) ? (array)($info['contexts'] ?? array()) : array();
                $contexts_by_phrase = $this->group_contexts_by_phrase($contexts);

                $title_html = $perma ? '<a href="'.esc_url($perma).'" target="_blank" rel="noopener">'.esc_html($t).'</a>' : esc_html($t);
                $edit_html  = $edit  ? ' <a class="beeclear-ilm-edit" href="'.esc_url($edit).'" title="'.esc_attr__('Edit', 'internal-external-link-manager-premium').'"><span class="dashicons dashicons-edit"></span></a>' : '';

                $details = '';
                $popups  = '';
                if (!empty($phrases)){
                    $parts = array();
                    foreach($phrases as $ph => $pc){
                        $part = '<span class="beeclear-ilm-source-phrase">'.esc_html($ph).'</span>';
                        $has_manual = false;
                        $popup_inner = '';

                        if (!empty($contexts_by_phrase[$ph])){
                            foreach ($contexts_by_phrase[$ph] as $ctx){
                                if (!empty($ctx['manual'])){
                                    $has_manual = true;
                                }
                                $tag_label = '';
                                if (!empty($ctx['tag'])){
                                    /* translators: %s: HTML tag name where the phrase was found. */
                                    $tag_label = '<div class="beeclear-ilm-context-tag">'.sprintf(esc_html__('Element: %s', 'internal-external-link-manager-premium'), esc_html($ctx['tag'])).'</div>';
                                }
                                $html_for_popup = $this->format_context_html_for_popup($ctx['html'], $ctx['tag'] ?? '');
                                $popup_inner .= '<div class="beeclear-ilm-context-fragment">'.$tag_label.'<div class="beeclear-ilm-context-html">'.wp_kses_post($html_for_popup).'</div></div>';
                            }
                        }

                        if ($popup_inner !== ''){
                            $base_popup_id = 'beeclear-ilm-context-'.$tid.'-'.$id.'-'.md5($ph);
                            $popup_id = $this->unique_popup_id($base_popup_id, $popup_registry);
                            $popup_attr = esc_attr($popup_id);
                            $part .= ' <button type="button" class="button-link beeclear-ilm-context-btn" data-target="'.$popup_attr.'" aria-expanded="false" aria-controls="'.$popup_attr.'" title="'.esc_attr__('Show source element', 'internal-external-link-manager-premium').'"><span class="dashicons dashicons-format-chat" aria-hidden="true"></span><span class="screen-reader-text">'.esc_html__('Show source element', 'internal-external-link-manager-premium').'</span></button>';
                            if ($has_manual){
                                $manual_label = esc_attr__('Manual insertion link', 'internal-external-link-manager-premium');
                                $part .= ' <span class="beeclear-ilm-manual-badge" title="'.$manual_label.'" aria-label="'.$manual_label.'">'
                                    .'<span class="dashicons dashicons-admin-tools" aria-hidden="true"></span>'
                                    .'<span class="screen-reader-text">'.esc_html__('Manual insertion link', 'internal-external-link-manager-premium').'</span>'
                                .'</span>';
                            }
                            if ($popup_id !== ''){
                                $popups .= '<div id="'.$popup_attr.'" class="beeclear-ilm-context-popup" hidden>'.$popup_inner.'</div>';
                            }
                        }

                        $parts[] = $part;
                    }
                    $details = implode(', ', $parts);
                }

                echo '<li class="beeclear-ilm-source-item">'.wp_kses_post($title_html).wp_kses_post($edit_html).($details !== '' ? ' — '.wp_kses_post($details) : '').wp_kses_post($popups).'</li>';
            }
            echo '</ul>';
        } elseif ($view === 'external') {
            $external_rows = $this->build_external_overview_rows();
            $target = null;
            foreach ($external_rows as $row){
                if ($row['id'] === $id){
                    $target = $row;
                    break;
                }
            }

            if ($target === null){ echo '<em>'.esc_html__('Target not found.', 'internal-external-link-manager-premium').'</em>'; wp_die(); }
            $sources = isset($target['sources']) ? $target['sources'] : array();
            if (empty($sources)){ echo '<em>'.esc_html__('No sources recorded yet.', 'internal-external-link-manager-premium').'</em>'; wp_die(); }

            $popup_registry = array();
            echo '<ul class="ilm-list">';
            foreach($sources as $sid => $info){
                $title = get_the_title($sid);
                $perma = get_permalink($sid);
                $edit  = get_edit_post_link($sid);

                $title_html = $perma ? '<a href="'.esc_url($perma).'" target="_blank" rel="noopener">'.esc_html($title).'</a>' : esc_html($title);
                $edit_html  = $edit ? ' <a class="beeclear-ilm-edit" href="'.esc_url($edit).'" title="'.esc_attr__('Edit', 'internal-external-link-manager-premium').'"><span class="dashicons dashicons-edit"></span></a>' : '';

                $phrases = isset($info['phrases']) ? (array) $info['phrases'] : array();
                $contexts = is_array($info) ? (array)($info['contexts'] ?? array()) : array();
                $contexts_by_phrase = $this->group_contexts_by_phrase($contexts);
                $list_rows = array();

                if (!empty($contexts_by_phrase)){
                    foreach ($contexts_by_phrase as $ph => $ctx_list){
                        foreach ($ctx_list as $ctx_index => $ctx){
                            $part = '<span class="beeclear-ilm-source-phrase">'.esc_html($ph).'</span>';
                            $popup_inner = '';
                            $popup_html = '';
                            $has_manual = !empty($ctx['manual']);

                        $tag_label = '';
                        if (!empty($ctx['tag'])){
                            /* translators: %s: HTML tag name where the phrase was found. */
                            $tag_label = '<div class="beeclear-ilm-context-tag">'.sprintf(esc_html__('Element: %s', 'internal-external-link-manager-premium'), esc_html($ctx['tag'])).'</div>';
                        }
                            $html_for_popup = $this->format_context_html_for_popup($ctx['html'], $ctx['tag'] ?? '');
                            if ($html_for_popup !== ''){
                                $popup_inner = '<div class="beeclear-ilm-context-fragment">'.$tag_label.'<div class="beeclear-ilm-context-html">'.wp_kses_post($html_for_popup).'</div></div>';
                            }

                            if ($popup_inner !== ''){
                                $base_popup_id = 'beeclear-ilm-context-'.$target['id'].'-'.$sid.'-'.md5($ph.'-'.$ctx_index);
                                $popup_id = $this->unique_popup_id($base_popup_id, $popup_registry);
                                $popup_attr = esc_attr($popup_id);
                                $part .= ' <button type="button" class="button-link beeclear-ilm-context-btn" data-target="'.$popup_attr.'" aria-expanded="false" aria-controls="'.$popup_attr.'" title="'.esc_attr__('Show source element', 'internal-external-link-manager-premium').'"><span class="dashicons dashicons-format-chat" aria-hidden="true"></span><span class="screen-reader-text">'.esc_html__('Show source element', 'internal-external-link-manager-premium').'</span></button>';
                                if ($has_manual){
                                    $manual_label = esc_attr__('Manual insertion link', 'internal-external-link-manager-premium');
                                    $part .= ' <span class="beeclear-ilm-manual-badge" title="'.$manual_label.'" aria-label="'.$manual_label.'">'
                                        .'<span class="dashicons dashicons-admin-tools" aria-hidden="true"></span>'
                                        .'<span class="screen-reader-text">'.esc_html__('Manual insertion link', 'internal-external-link-manager-premium').'</span>'
                                    .'</span>';
                                }
                                $popup_html = $popup_id !== '' ? '<div id="'.$popup_attr.'" class="beeclear-ilm-context-popup" hidden>'.$popup_inner.'</div>' : '';
                            }

                            $list_rows[] = array('details' => $part, 'popup' => $popup_html ?? '');
                        }
                    }
                } elseif (!empty($phrases)) {
                    $parts = array();
                    foreach($phrases as $ph => $cnt){
                        $parts[] = '<span class="beeclear-ilm-source-phrase">'.esc_html($ph).'</span>';
                    }
                    $list_rows[] = array('details' => implode(', ', $parts), 'popup' => '');
                } else {
                    $list_rows[] = array('details' => '', 'popup' => '');
                }

                foreach ($list_rows as $row_details){
                    $details = $row_details['details'];
                    $popup_html = $row_details['popup'];
                    echo '<li class="beeclear-ilm-source-item">'.wp_kses_post($title_html).wp_kses_post($edit_html).($details !== '' ? ' — '.wp_kses_post($details) : '').wp_kses_post($popup_html).'</li>';
                }
            }
            echo '</ul>';
        } else {
            $sources = isset($map[$id]['sources']) ? $map[$id]['sources'] : array();
            if(empty($sources)){ echo '<em>'.esc_html__('No sources recorded yet.', 'internal-external-link-manager-premium').'</em>'; wp_die(); }

            $popup_registry = array();
            echo '<ul class="ilm-list">';
            foreach($sources as $sid=>$info){
                $t = get_the_title($sid);
                $perma = get_permalink($sid);
                $edit  = get_edit_post_link($sid);

                // Back-compat: $info może być intem albo tablicą
                $phrases = is_array($info) ? (array)($info['phrases'] ?? array()) : array();
                $contexts = is_array($info) ? (array)($info['contexts'] ?? array()) : array();
                $contexts_by_phrase = $this->group_contexts_by_phrase($contexts);

                $title_html = $perma ? '<a href="'.esc_url($perma).'" target="_blank" rel="noopener">'.esc_html($t).'</a>' : esc_html($t);
                $edit_html  = $edit  ? ' <a class="beeclear-ilm-edit" href="'.esc_url($edit).'" title="'.esc_attr__('Edit', 'internal-external-link-manager-premium').'"><span class="dashicons dashicons-edit"></span></a>' : '';

                // POKAZUJEMY TYLKO KONKRETNE FRAZY (dopasowania), BEZ LICZNIKÓW
                $details = '';
                $popups  = '';
                if (!empty($phrases)){
                    $parts = array();
                    foreach($phrases as $ph => $pc){
                        $part = '<span class="beeclear-ilm-source-phrase">'.esc_html($ph).'</span>';
                        $has_manual = false;
                        $popup_inner = '';

                        if (!empty($contexts_by_phrase[$ph])){
                            foreach ($contexts_by_phrase[$ph] as $ctx){
                                if (!empty($ctx['manual'])){
                                    $has_manual = true;
                                }
                                $tag_label = '';
                                if (!empty($ctx['tag'])){
                                    /* translators: %s: HTML tag name where the phrase was found. */
                                    $tag_label = '<div class="beeclear-ilm-context-tag">'.sprintf(esc_html__('Element: %s', 'internal-external-link-manager-premium'), esc_html($ctx['tag'])).'</div>';
                                }
                                $html_for_popup = $this->format_context_html_for_popup($ctx['html'], $ctx['tag'] ?? '');
                                $popup_inner .= '<div class="beeclear-ilm-context-fragment">'.$tag_label.'<div class="beeclear-ilm-context-html">'.wp_kses_post($html_for_popup).'</div></div>';
                            }
                        }

                        if ($popup_inner !== ''){
                            $base_popup_id = 'beeclear-ilm-context-'.$id.'-'.$sid.'-'.md5($ph);
                            $popup_id = $this->unique_popup_id($base_popup_id, $popup_registry);
                            $popup_attr = esc_attr($popup_id);
                            $part .= ' <button type="button" class="button-link beeclear-ilm-context-btn" data-target="'.$popup_attr.'" aria-expanded="false" aria-controls="'.$popup_attr.'" title="'.esc_attr__('Show source element', 'internal-external-link-manager-premium').'"><span class="dashicons dashicons-format-chat" aria-hidden="true"></span><span class="screen-reader-text">'.esc_html__('Show source element', 'internal-external-link-manager-premium').'</span></button>';
                            if ($has_manual){
                                $manual_label = esc_attr__('Manual insertion link', 'internal-external-link-manager-premium');
                                $part .= ' <span class="beeclear-ilm-manual-badge" title="'.$manual_label.'" aria-label="'.$manual_label.'">'
                                    .'<span class="dashicons dashicons-admin-tools" aria-hidden="true"></span>'
                                    .'<span class="screen-reader-text">'.esc_html__('Manual insertion link', 'internal-external-link-manager-premium').'</span>'
                                .'</span>';
                            }
                            if ($popup_id !== ''){
                                $popups .= '<div id="'.$popup_attr.'" class="beeclear-ilm-context-popup" hidden>'.$popup_inner.'</div>';
                            }
                        }

                        $parts[] = $part;
                    }
                    $details = implode(', ', $parts);
                }

                echo '<li class="beeclear-ilm-source-item">'.wp_kses_post($title_html).wp_kses_post($edit_html).($details !== '' ? ' — '.wp_kses_post($details) : '').wp_kses_post($popups).'</li>';
            }
            echo '</ul>';
        }
        wp_die();
    }

    public function ajax_start_overview_scan(){
        check_ajax_referer(self::NONCE);
        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error(array('message' => __('Access denied.', 'internal-external-link-manager-premium')));
        }

        $settings = get_option(self::OPT_SETTINGS, array());
        $pts = !empty($settings['process_post_types']) ? (array)$settings['process_post_types'] : array('post','page');
        $ids = $this->collect_overview_scan_ids($pts);

        if ( empty($ids) ) {
            delete_option(self::OPT_OVERVIEW_SCAN);
            wp_send_json_error(array('message' => __('No public content found to scan.', 'internal-external-link-manager-premium')));
        }

        update_option(self::OPT_LINKMAP, array(), false);
        update_option(self::OPT_EXTERNAL_MAP, array(), false);

        $state = array(
            'ids'       => $ids,
            'processed' => 0,
            'total'     => count($ids),
            'started_at'=> current_time('timestamp'),
        );
        update_option(self::OPT_OVERVIEW_SCAN, $state, false);

        /* translators: %d: number of pages queued for the overview scan. */
        $this->log_activity(sprintf(__('Scan started: %d pages queued.', 'internal-external-link-manager-premium'), count($ids)));

        wp_send_json_success(array('total' => count($ids)));
    }

    public function ajax_step_overview_scan(){
        check_ajax_referer(self::NONCE);
        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error(array('message' => __('Access denied.', 'internal-external-link-manager-premium')));
        }

        $batch_raw = isset($_POST['batch']) ? sanitize_text_field(wp_unslash($_POST['batch'])) : 5;
        $batch = (int) $batch_raw;
        $result = $this->process_overview_scan_batch($batch);
        if ( ! empty($result['done']) ) {
            $result['summary_html'] = $this->render_scan_summary_html();
        }
        wp_send_json_success($result);
    }

    public function ajax_fetch_logs(){
        check_ajax_referer(self::NONCE);
        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error(array('message' => __('Access denied.', 'internal-external-link-manager-premium')));
        }

        $page_raw = isset($_POST['page']) ? sanitize_text_field(wp_unslash($_POST['page'])) : 1;
        $page = (int) $page_raw;
        $per_page = 50;
        $data = $this->get_activity_log_page($page, $per_page);
        $total_pages = max(1, (int) ceil(($data['total'] ?: 0) / $per_page));
        $page = min(max(1, $page), $total_pages);
        $original_page = isset($_POST['page']) ? (int) sanitize_text_field(wp_unslash($_POST['page'])) : 1;
        if ($page !== $original_page) {
            $data = $this->get_activity_log_page($page, $per_page);
        }

        wp_send_json_success(array(
            'entries_html'    => $this->render_activity_log_entries_html($data['entries']),
            'pagination_html' => $this->render_activity_log_pagination_html($page, $total_pages),
            'page'            => $page,
            'total_pages'     => $total_pages,
        ));
    }

    public function render_internal_overview(){
        if ( ! current_user_can('manage_options') ) return;

        $this->rebuild_index();

        if ( isset($_POST['beeclear_ilm_reindex_now']) && check_admin_referer(self::NONCE, self::NONCE) ){
            $index = $this->rebuild_index();
            if ( $this->last_rebuild_error !== '' ) {
                echo '<div class="notice notice-error"><p>'.esc_html($this->last_rebuild_error).'</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>'.esc_html__('Index rebuilt. Scan completed.', 'internal-external-link-manager-premium').'</p></div>';
                $this->log_activity(__('Index rebuilt from overview.', 'internal-external-link-manager-premium'));
            }
        }

        if(isset($_POST['beeclear_ilm_clear_data']) && check_admin_referer(self::NONCE, self::NONCE)){
            update_option(self::OPT_INDEX, array(), false);
            update_option(self::OPT_LINKMAP, array(), false);
            update_option(self::OPT_EXTERNAL_MAP, array(), false);
            delete_option(self::OPT_OVERVIEW_SCAN_SUMMARY);
            delete_option(self::OPT_OVERVIEW_SCAN);
            $this->rebuild_index();
            if ( $this->last_rebuild_error !== '' ) {
                echo '<div class="notice notice-error"><p>'.esc_html($this->last_rebuild_error).'</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>'.esc_html__('Data cleared and index rebuilt.', 'internal-external-link-manager-premium').'</p></div>';
                $this->log_activity(__('Data cleared and index rebuilt from overview.', 'internal-external-link-manager-premium'));
            }
        }

                if ( isset($_POST['beeclear_ilm_purge_db']) && check_admin_referer(self::NONCE, self::NONCE) ){
                        $this->purge_database();
                        delete_option(self::OPT_OVERVIEW_SCAN_SUMMARY);
                        delete_option(self::OPT_OVERVIEW_SCAN);
                        update_option(self::OPT_EXTERNAL_MAP, array(), false);
                        $this->rebuild_index();
                        if ( $this->last_rebuild_error !== '' ) {
                            echo '<div class="notice notice-error"><p>'.esc_html($this->last_rebuild_error).'</p></div>';
                        } else {
                            echo '<div class="notice notice-success"><p>'.esc_html__('Database purged. Index rebuilt.', 'internal-external-link-manager-premium').'</p></div>';
                            $this->log_activity(__('Database purged and index rebuilt from overview.', 'internal-external-link-manager-premium'));
                        }
                }

        echo '<div class="wrap"><h1>'.esc_html__('Linking overview — targets & sources', 'internal-external-link-manager-premium').'</h1>';
        echo wp_kses_post( $this->render_author_note() );

        echo '<div class="beeclear-grid">';

        echo '<div class="ilm-wrap">';

        echo '<div class="beeclear-card">';
        echo '<h2 class="ilm-section-title"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span>'.esc_html__('Linking overview', 'internal-external-link-manager-premium').'</h2>';
        echo '<p class="description">'.esc_html__('Review how phrases map to your targets, how many links point to them, and expand entries to inspect exact source snippets.', 'internal-external-link-manager-premium').'</p>';
        echo '</div>';

        $this->render_overview_table();
       
        echo '<div class="beeclear-card">';
        echo '<h2 class="ilm-section-title"><span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>'.esc_html__('How to use this panel', 'internal-external-link-manager-premium').'</h2>';
        echo '<ol class="ilm-list">';
        echo '<li>'.esc_html__('Scan your site from the Settings tab to refresh counters before reviewing results here.', 'internal-external-link-manager-premium').'</li>';
        echo '<li>'.esc_html__('Use the “Show” toggle in Sources to reveal the posts/pages where a phrase was auto-linked.', 'internal-external-link-manager-premium').'</li>';
        echo '<li>'.esc_html__('Click the Edit icon next to a target title to open it and adjust per-post rules or metadata.', 'internal-external-link-manager-premium').'</li>';
        echo '<li>'.esc_html__('If a phrase needs tweaking, edit it directly on the target post and rebuild the index to apply changes.', 'internal-external-link-manager-premium').'</li>';
        echo '</ol>';
        echo '</div>';

        echo '</div>';

        echo '</div>';
        echo '</div>';
    }

    public function render_external(){
        if ( ! current_user_can('manage_options') ) return;

        if( isset($_POST['beeclear_ilm_save_external']) && check_admin_referer(self::NONCE, self::NONCE) ){
            $raw_ext = isset($_POST['beeclear_ilm_ext']) ? wp_unslash( $_POST['beeclear_ilm_ext'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in sanitize_external_rules().
            $clean = $this->sanitize_external_rules( is_array($raw_ext) ? $raw_ext : array() );
            update_option(self::OPT_EXT_RULES, $clean, false);
            echo '<div class="notice notice-success"><p>'.esc_html__('External rules saved.', 'internal-external-link-manager-premium').'</p></div>';

            $settings = get_option(self::OPT_SETTINGS, array());
            if ( ! empty($settings['auto_scan_on_external']) ) {
                $pts = !empty($settings['process_post_types']) ? (array)$settings['process_post_types'] : array('post','page');
                /* translators: %d: number of pages queued for the overview scan. */
                $this->start_overview_scan_now($pts, __('Auto scan started after external rule save: %d pages queued.', 'internal-external-link-manager-premium'));
            }
        }

        $rules = get_option(self::OPT_EXT_RULES, array());
        $pts   = get_post_types(array('public'=>true),'objects');

        echo '<div class="wrap"><h1>'.esc_html__('External linking', 'internal-external-link-manager-premium').'</h1>';
        echo wp_kses_post( $this->render_author_note() );
        echo '<div class="beeclear-grid">';
        echo '<div class="ilm-wrap">';

        echo '<div class="beeclear-card">';
        echo '<h2 class="ilm-section-title"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span>'.esc_html__('External rules', 'internal-external-link-manager-premium').'</h2>';
        echo '<p class="description">'.esc_html__('Define phrases (or regex) that should be linked to external URLs. Control case-sensitivity (disabled if regex), rel/title/aria/class, per-page limits, and restrict rules to specific post types.', 'internal-external-link-manager-premium').'</p>';
        echo wp_kses_post( $this->render_token_tips_html() );
        echo '</div>';
        echo '</details>';
        echo '<form method="post"><table class="widefat striped" id="beeclear-ilm-ext-table"><thead><tr>'.
             '<th>'.esc_html__('Destination & Phrase / Flags', 'internal-external-link-manager-premium').'</th>'.
             '<th>'.esc_html__('Attributes', 'internal-external-link-manager-premium').'</th>'.
             '<th>'.esc_html__('Post types / Exclusions', 'internal-external-link-manager-premium').'</th>'.
             '<th>'.esc_html__('Actions', 'internal-external-link-manager-premium').'</th>'.
             '</tr></thead><tbody>';

        $i=0;
        foreach($rules as $r){
            $phrase = isset($r['phrase']) ? $r['phrase'] : '';
            $regex  = !empty($r['regex']);
            $case   = !empty($r['case']);
            $url    = isset($r['url']) ? $r['url'] : '';
            $rel    = isset($r['rel']) ? $r['rel'] : '';
            $context_raw = isset($r['context']) ? $r['context'] : array();
            $context_display = is_array($context_raw) ? implode(', ', array_map('trim', $context_raw)) : (string) $context_raw;
            $context_regex = !empty($r['context_regex']);
            $context_case  = !empty($r['context_case']);
            $allowed_tags_raw = isset($r['allowed_tags']) ? $r['allowed_tags'] : '';
            $title_mode   = isset($r['title_mode']) ? $r['title_mode'] : 'phrase';
            $title_custom = isset($r['title_custom']) ? $r['title_custom'] : '';
            $aria_mode    = isset($r['aria_mode']) ? $r['aria_mode'] : 'phrase';
            $aria_custom  = isset($r['aria_custom']) ? $r['aria_custom'] : '';
            $class        = isset($r['class']) ? $r['class'] : '';
            $max_per_page = isset($r['max_per_page']) ? (int)$r['max_per_page'] : 1;
            $types        = (array)($r['types'] ?? array());
            $exclude_ids  = implode(', ', array_map('intval', (array)($r['exclude_ids'] ?? array())));

            $i_attr = esc_attr((string) $i);

            $url_id          = 'beeclear-ilm-ext-'.$i_attr.'-url';
            $phrase_id       = 'beeclear-ilm-ext-'.$i_attr.'-phrase';
            $context_id      = 'beeclear-ilm-ext-'.$i_attr.'-context';
            $rel_id          = 'beeclear-ilm-ext-'.$i_attr.'-rel';
            $title_mode_id   = 'beeclear-ilm-ext-'.$i_attr.'-title-mode';
            $title_custom_id = 'beeclear-ilm-ext-'.$i_attr.'-title-custom';
            $aria_mode_id    = 'beeclear-ilm-ext-'.$i_attr.'-aria-mode';
            $aria_custom_id  = 'beeclear-ilm-ext-'.$i_attr.'-aria-custom';
            $class_id        = 'beeclear-ilm-ext-'.$i_attr.'-class';
            $max_id          = 'beeclear-ilm-ext-'.$i_attr.'-max';

            echo '<tr>';
            echo '<td class="cell-phrase">'.
                    '<div class="ext-field ext-destination">'.
                        '<label class="ext-destination-label" for="'.esc_attr($url_id).'">'.esc_html__('Destination URL', 'internal-external-link-manager-premium').'</label>'.
                        '<input type="url" id="'.esc_attr($url_id).'" name="beeclear_ilm_ext[' . intval($i_attr) . '][url]" class="regular-text" value="'.esc_attr($url).'" placeholder="https://example.com">'.
                    '</div>'.
                    '<div class="ext-field ext-phrase">'.
                        '<label class="ext-phrase-label" for="'.esc_attr($phrase_id).'">'.esc_html__('Phrase or regex', 'internal-external-link-manager-premium').'</label>'.
                        '<input type="text" id="'.esc_attr($phrase_id).'" name="beeclear_ilm_ext[' . intval($i_attr) . '][phrase]" class="regular-text" value="'.esc_attr($phrase).'" placeholder="'.esc_attr__('Phrase or regex', 'internal-external-link-manager-premium').'">'.
                    '</div>'.
                    '<div class="flags">'.
                        '<label><input type="checkbox" class="ext-regex" name="beeclear_ilm_ext[' . intval($i_attr) . '][regex]" value="1" '.checked($regex,true,false).'> '.esc_html__('Regex', 'internal-external-link-manager-premium').'</label> '.
                        '<label><input type="checkbox" class="ext-case" name="beeclear_ilm_ext[' . intval($i_attr) . '][case]" value="1" '.checked($case,true,false).($regex?' disabled':'').'> '.esc_html__('Case-sensitive', 'internal-external-link-manager-premium').'</label>'.
                    '</div>'.
                    '<div class="ext-field ext-context">'.
                        '<label class="ext-context-label" for="'.esc_attr($context_id).'">'.esc_html__('Context words or regex', 'internal-external-link-manager-premium').'</label>'.
                        '<input type="text" id="'.esc_attr($context_id).'" name="beeclear_ilm_ext[' . intval($i_attr) . '][context]" class="regular-text" value="'.esc_attr($context_display).'" placeholder="'.esc_attr__('Additional words required in the same element', 'internal-external-link-manager-premium').'">'.
                        '<div class="flags">'.
                            '<label><input type="checkbox" class="ext-context-regex" name="beeclear_ilm_ext[' . intval($i_attr) . '][context_regex]" value="1" '.checked($context_regex,true,false).'> '.esc_html__('Regex', 'internal-external-link-manager-premium').'</label> '.
                            '<label><input type="checkbox" class="ext-context-case" name="beeclear_ilm_ext[' . intval($i_attr) . '][context_case]" value="1" '.checked($context_case,true,false).($context_regex?' disabled':'').'> '.esc_html__('Case-sensitive', 'internal-external-link-manager-premium').'</label>'.
                        '</div>'.
                        '<p class="description">'.esc_html__('Supports token syntax (non-regex). Separate multiple entries with commas.', 'internal-external-link-manager-premium').'</p>'.
                    '</div>'.
                 '</td>';
            echo '<td class="cell-attrs"><div class="attr-rows">'.
                    '<div class="ar"><label class="ar-label" for="'.esc_attr($rel_id).'">rel</label><div class="ar-field"><input type="text" id="'.esc_attr($rel_id).'" name="beeclear_ilm_ext[' . intval($i_attr) . '][rel]" class="regular-text" value="'.esc_attr($rel).'" placeholder="nofollow noopener"></div></div>'.
                    '<div class="ar"><label class="ar-label" for="'.esc_attr($title_mode_id).'">'.esc_html__('Title', 'internal-external-link-manager-premium').'</label><div class="ar-field"><div class="inline-field"><select id="'.esc_attr($title_mode_id).'" name="beeclear_ilm_ext[' . intval($i_attr) . '][title_mode]">'.
                        wp_kses_post($this->options_html(array('none','phrase','custom'), $title_mode)).'</select></div>'.
                        '<div class="inline-field"><label class="screen-reader-text" for="'.esc_attr($title_custom_id).'">'.esc_html__('Custom title', 'internal-external-link-manager-premium').'</label><input type="text" id="'.esc_attr($title_custom_id).'" name="beeclear_ilm_ext[' . intval($i_attr) . '][title_custom]" class="regular-text" value="'.esc_attr($title_custom).'" placeholder="'.esc_attr__('Custom title', 'internal-external-link-manager-premium').'"></div></div></div>'.
                    '<div class="ar"><label class="ar-label" for="'.esc_attr($aria_mode_id).'">'.esc_html__('Aria-label', 'internal-external-link-manager-premium').'</label><div class="ar-field"><div class="inline-field"><select id="'.esc_attr($aria_mode_id).'" name="beeclear_ilm_ext[' . intval($i_attr) . '][aria_mode]">'.
                        wp_kses_post($this->options_html(array('none','phrase','custom'), $aria_mode)).'</select></div>'.
                        '<div class="inline-field"><label class="screen-reader-text" for="'.esc_attr($aria_custom_id).'">'.esc_html__('Custom aria-label', 'internal-external-link-manager-premium').'</label><input type="text" id="'.esc_attr($aria_custom_id).'" name="beeclear_ilm_ext[' . intval($i_attr) . '][aria_custom]" class="regular-text" value="'.esc_attr($aria_custom).'" placeholder="'.esc_attr__('Custom aria-label', 'internal-external-link-manager-premium').'"></div></div></div>'.
                    '<div class="ar"><label class="ar-label" for="'.esc_attr($class_id).'">CSS class</label><div class="ar-field"><input type="text" id="'.esc_attr($class_id).'" name="beeclear_ilm_ext[' . intval($i_attr) . '][class]" class="regular-text" value="'.esc_attr($class).'" placeholder="beeclear-ilm-link"></div></div>'.
                 '</div></td>';
            echo '<td class="cell-types">'.
                '<div class="types-stack">'.
                    '<div class="max-per-page-field"><label class="max-label" for="'.esc_attr($max_id).'">'.esc_html__('Max/page', 'internal-external-link-manager-premium').'</label><input type="number" min="0" id="'.esc_attr($max_id).'" name="beeclear_ilm_ext[' . intval($i_attr) . '][max_per_page]" value="'.esc_attr((int)$max_per_page).'"> <span class="desc">'.esc_html__('0 = unlimited', 'internal-external-link-manager-premium').'</span></div>'.
                    '<div class="types-checklist">'.wp_kses_post($this->post_types_checklist_html('beeclear_ilm_ext['.intval($i_attr).'][types][]', $types, $pts)).'</div>'.
                    '<div class="field-stack">'.
                        '<label class="field-label" for="beeclear-ilm-ext-'.intval($i_attr).'-exclude">'.esc_html__('Exclude by post ID', 'internal-external-link-manager-premium').'</label>'.
                        '<input type="text" id="beeclear-ilm-ext-'.intval($i_attr).'-exclude" name="beeclear_ilm_ext[' . intval($i_attr) . '][exclude_ids]" class="regular-text" value="'.esc_attr($exclude_ids).'" placeholder="e.g. 123, 456">'.
                    '</div>'.
                    '<div class="field-stack">'.
                        '<label class="field-label" for="beeclear-ilm-ext-'.intval($i_attr).'-allowed">'.esc_html__('Allowed elements (overrides global skip)', 'internal-external-link-manager-premium').'</label>'.
                        '<input type="text" id="beeclear-ilm-ext-'.intval($i_attr).'-allowed" name="beeclear_ilm_ext[' . intval($i_attr) . '][allowed_tags]" class="regular-text" value="'.esc_attr($allowed_tags_raw).'" placeholder="p, ul, ol">'.
                        '<p class="description">'.esc_html__('Comma-separated tag names. Leave empty to follow global “Skip elements (EXTERNAL)” setting.', 'internal-external-link-manager-premium').'</p>'.
                    '</div>'.
                '</div>'.
            '</td>';
            echo '<td class="cell-actions"><a href="#" class="button ext-delete">'.esc_html__('Remove', 'internal-external-link-manager-premium').'</a></td>';
            echo '</tr>';
            $i++;
        }

        echo '</tbody></table>';
        echo '<p><a href="#" class="button" id="beeclear-ilm-ext-add">'.esc_html__('Add external rule', 'internal-external-link-manager-premium').'</a></p>';
        wp_nonce_field(self::NONCE, self::NONCE);
        echo '<p><button class="button button-primary" name="beeclear_ilm_save_external" value="1">'.esc_html__('Save rules', 'internal-external-link-manager-premium').'</button></p>';
        echo '</form>';
        echo '</div>';

        echo '<div class="beeclear-card">';
        echo '<h2 class="ilm-section-title"><span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>'.esc_html__('How to use this panel', 'internal-external-link-manager-premium').'</h2>';
        echo '<ol class="ilm-list">';
        echo '<li>'.esc_html__('Add or edit rows to pair a Phrase with a destination URL. Use Phrase helpers like [words] or [string] to cover variable text fragments.', 'internal-external-link-manager-premium').'</li>';
        echo '<li>'.esc_html__('Toggle Regex or Case-sensitive when needed; regex disables case switching.', 'internal-external-link-manager-premium').'</li>';
        echo '<li>'.esc_html__('Adjust rel/title/aria/class attributes, per-page limits, and restrict rules to selected post types.', 'internal-external-link-manager-premium').'</li>';
        echo '<li>'.esc_html__('Click “Save rules” to store changes and apply them across your site.', 'internal-external-link-manager-premium').'</li>';
        echo '<li>'.esc_html__('Review the overview list below to confirm active rules and their targets.', 'internal-external-link-manager-premium').'</li>';
        echo '</ol>';
        echo '</div>';

        echo '<script type="text/html" id="beeclear-ilm-ext-types-template">'
            .'<div class="types-stack">'
                .'<div class="max-per-page-field"><label class="max-label" for="beeclear-ilm-ext-__IDX__-max">'.esc_html__('Max/page', 'internal-external-link-manager-premium').'</label><input type="number" min="0" id="beeclear-ilm-ext-__IDX__-max" name="beeclear_ilm_ext[__IDX__][max_per_page]" value="1"> <span class="desc">'.esc_html__('0 = unlimited', 'internal-external-link-manager-premium').'</span></div>'
                .'<div class="types-checklist">'.wp_kses_post($this->post_types_checklist_html('beeclear_ilm_ext[__IDX__][types][]', array(), $pts, true)).'</div>'
                .'<div class="field-stack">'
                    .'<label class="field-label" for="beeclear-ilm-ext-__IDX__-exclude">'.esc_html__('Exclude by post ID', 'internal-external-link-manager-premium').'</label>'
                    .'<input type="text" id="beeclear-ilm-ext-__IDX__-exclude" name="beeclear_ilm_ext[__IDX__][exclude_ids]" class="regular-text" placeholder="123, 456">'
                .'</div>'
                .'<div class="field-stack">'
                    .'<label class="field-label" for="beeclear-ilm-ext-__IDX__-allowed">'.esc_html__('Allowed elements (overrides global skip)', 'internal-external-link-manager-premium').'</label>'
                    .'<input type="text" id="beeclear-ilm-ext-__IDX__-allowed" name="beeclear_ilm_ext[__IDX__][allowed_tags]" class="regular-text" placeholder="p, ul, ol">'
                    .'<p class="description">'.esc_html__('Comma-separated tag names. Leave empty to follow global “Skip elements (EXTERNAL)” setting.', 'internal-external-link-manager-premium').'</p>'
                .'</div>'
            .'</div>'
            .'</script>';

        if (!empty($rules)){
            echo '<div class="beeclear-card">';
            echo '<h2 class="ilm-section-title"><span class="dashicons dashicons-visibility" aria-hidden="true"></span>'.esc_html__('External rules overview', 'internal-external-link-manager-premium').'</h2>';
            echo '<ol class="ilm-list">';
            foreach($rules as $r){
                $badge = !empty($r['regex']) ? 'regex' : (!empty($r['case']) ? 'case' : ($this->contains_tokens($r['phrase'] ?? '') ? 'tokens' : ''));
                $types_raw = empty($r['types']) ? __('all types', 'internal-external-link-manager-premium') : implode(', ', (array) $r['types']);
                $ph = isset($r['phrase']) ? $r['phrase'] : '';
                $url = isset($r['url']) ? $r['url'] : '';
                echo '<li><code>'.esc_html($ph).'</code> '.($badge? '['.esc_html($badge).'] ' : '').'→ <a href="'.esc_url($url).'" target="_blank" rel="noopener">'.esc_html($url).'</a> <span style="opacity:.7">('.esc_html($types_raw).')</span></li>';
            }
            echo '</ol>';
            echo '</div>';
        }

        echo '</div>';
        echo '</div>';
    }
	
        // Czyści tylko licznik/źródła (runtime)
        private function reset_linkmap(){
                update_option(self::OPT_LINKMAP, array(), false);
                update_option(self::OPT_EXTERNAL_MAP, array(), false);
        }

        // Pełne czyszczenie runtime + reguł
        private function purge_database(){
                update_option(self::OPT_LINKMAP, array(), false);
        update_option(self::OPT_EXTERNAL_MAP, array(), false);
        update_option(self::OPT_INDEX,   array(), false);
                update_option(self::OPT_EXT_RULES, array(), false);

		if ( class_exists('WP_Query') ) {
			$q = new WP_Query(array(
				'post_type'      => get_post_types(array('public'=>true),'names'),
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			));
			if ( ! is_wp_error($q) && ! empty($q->posts) ) {
                                foreach($q->posts as $pid){
                                        delete_post_meta($pid, self::META_RULES);
                                        delete_post_meta($pid, self::META_NO_OUT);
                                        delete_post_meta($pid, self::META_MAX_PER_TARGET);
                                        delete_post_meta($pid, self::META_TARGET_PRIORITY);
                                }
                        }
                }
        }

    private function options_html($options, $selected){
        $out='';
        foreach($options as $opt){ $out .= '<option value="'.esc_attr($opt).'"'.selected($selected,$opt,false).'>'.esc_html($opt).'</option>'; }
        return $out;
    }
    private function post_types_checklist_html($name, $selected, $pts, $template=false){
        $out='';
        foreach($pts as $pt){
            $ck = in_array($pt->name, (array)$selected, true);
            $checked_attr = $template ? '' : checked($ck,true,false);
            $out .= sprintf('<label class="ilm-types-checkbox"><input type="checkbox" name="%1$s" value="%2$s"%3$s> %4$s</label>',
                esc_attr($name), esc_attr($pt->name), $checked_attr, esc_html($pt->labels->singular_name)
            );
        }
        return $out;
    }
    public function render_impex(){
        if ( ! current_user_can('manage_options') ) return;

        if(isset($_POST['beeclear_ilm_import']) && check_admin_referer(self::NONCE, self::NONCE)){
            $json_raw = isset($_POST['beeclear_ilm_json']) ? sanitize_textarea_field( wp_unslash($_POST['beeclear_ilm_json']) ) : '';
            $json = $json_raw;
            $data = json_decode($json, true);
            if(is_array($data)){
                if(isset($data['settings'])) update_option(self::OPT_SETTINGS, $this->sanitize_settings($data['settings']), false);
                if(isset($data['external']) && is_array($data['external']))    update_option(self::OPT_EXT_RULES, $this->sanitize_external_rules($data['external']), false);
                if(isset($data['posts']) && is_array($data['posts'])){
                    foreach($data['posts'] as $pid=>$payload){
                        $pid = (int)$pid;
                        if($pid<=0) continue;

                        $rules = is_array($payload) && array_key_exists('rules', $payload) ? $payload['rules'] : $payload;
                        if(is_array($rules)) update_post_meta($pid, self::META_RULES, $rules);

                        $per_target_limit = is_array($payload) && array_key_exists('max_per_target', $payload) ? $payload['max_per_target'] : null;
                        if ($per_target_limit === null || $per_target_limit === '') {
                            delete_post_meta($pid, self::META_MAX_PER_TARGET);
                        } elseif (is_numeric($per_target_limit)) {
                            update_post_meta($pid, self::META_MAX_PER_TARGET, (string) max(0, (int) $per_target_limit));
                        }

                        $priority_val = is_array($payload) && array_key_exists('priority', $payload) ? $payload['priority'] : null;
                        if ($priority_val === null || $priority_val === '') {
                            delete_post_meta($pid, self::META_TARGET_PRIORITY);
                        } elseif (is_numeric($priority_val)) {
                            $priority_val = min(100, max(0, (int) $priority_val));
                            update_post_meta($pid, self::META_TARGET_PRIORITY, (string) $priority_val);
                        }
                    }
                }
                $idx = $this->rebuild_index();
                echo '<div class="notice notice-success"><p>'.esc_html__('Imported successfully and index rebuilt.', 'internal-external-link-manager-premium').'</p>';
                echo wp_kses_post( $this->render_index_summary_html( $this->summarize_index($idx) ) );
                echo '</div>';
                $this->log_activity(__('Import finished and index rebuilt.', 'internal-external-link-manager-premium'));
            } else {
                echo '<div class="notice notice-error"><p>'.esc_html__('Invalid JSON.', 'internal-external-link-manager-premium').'</p></div>';
            }
        }

        $export = array(
            'settings' => get_option(self::OPT_SETTINGS, array()),
            'external' => get_option(self::OPT_EXT_RULES, array()),
            'posts'    => array(),
        );
        if ( class_exists('WP_Query') ) {
            $q = new WP_Query(array('post_type'=>get_post_types(array('public'=>true),'names'), 'post_status'=>'any', 'posts_per_page'=>-1,'fields'=>'ids','no_found_rows'=>true));
            if ( ! is_wp_error($q) ) {
                foreach($q->posts as $pid){
                    $r = get_post_meta($pid, self::META_RULES, true);
                    if ( ! is_array($r) ) $r = array();
                    $limit = get_post_meta($pid, self::META_MAX_PER_TARGET, true);
                    $priority = get_post_meta($pid, self::META_TARGET_PRIORITY, true);
                    if($r || $limit !== '' || $priority !== ''){
                        $export['posts'][$pid] = array('rules' => $r);
                        if ($limit !== '') {
                            $export['posts'][$pid]['max_per_target'] = (int) $limit;
                        }
                        if ($priority !== '') {
                            $export['posts'][$pid]['priority'] = (int) $priority;
                        }
                    }
                }
            }
        }
        $json = wp_json_encode($export, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        ?>
        <div class="wrap">
            <div class="ilm-wrap">
                <h1><?php esc_html_e('Import/Export', 'internal-external-link-manager-premium'); ?></h1>
                <?php echo wp_kses_post( $this->render_author_note() ); ?>
                <div class="beeclear-card">
                    <h2><?php esc_html_e('Export', 'internal-external-link-manager-premium'); ?></h2>
                    <textarea rows="16" class="large-text code" readonly id="beeclear-ilm-export-json"><?php echo esc_textarea($json); ?></textarea>
                    <p><button type="button" class="button" id="beeclear-ilm-export-download"><?php esc_html_e('Download JSON file', 'internal-external-link-manager-premium'); ?></button></p>
                </div>

                <div class="beeclear-card">
                    <h2><?php esc_html_e('Import', 'internal-external-link-manager-premium'); ?></h2>
                    <form method="post">
                        <?php wp_nonce_field(self::NONCE, self::NONCE); ?>
                        <p><input type="file" id="beeclear-ilm-import-file" accept="application/json,.json"></p>
                        <textarea name="beeclear_ilm_json" rows="10" class="large-text code" id="beeclear-ilm-import-json" placeholder="<?php esc_attr_e('Paste JSON here…', 'internal-external-link-manager-premium'); ?>"></textarea>
                        <p><button class="button button-primary" name="beeclear_ilm_import" value="1"><?php esc_html_e('Import', 'internal-external-link-manager-premium'); ?></button></p>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    public function register_admin_columns(){
        $settings = get_option(self::OPT_SETTINGS, array());
        $pts = !empty($settings['process_post_types']) ? (array)$settings['process_post_types'] : array('post','page');
        foreach($pts as $pt){
            add_filter("manage_{$pt}_posts_columns", function($cols){
                if (!is_array($cols)) $cols = array();
                $cols['beeclear_ilm'] = __('Internal Links (BeeClear)', 'internal-external-link-manager-premium');
                return $cols;
            });
            add_action("manage_{$pt}_posts_custom_column", function($col, $post_id){
                if ($col !== 'beeclear_ilm') return;
                $rules = get_post_meta($post_id, self::META_RULES, true);
                if (empty($rules) || !is_array($rules)){
                    echo '<span style="opacity:.6">'.esc_html__('— none —', 'internal-external-link-manager-premium').'</span>';
                } else {
                    $count = count($rules);
                    $preview_items = array();
                    foreach(array_slice($rules, 0, 3) as $r){
                        $ph = isset($r['phrase']) ? $r['phrase'] : '';
                        $badge = !empty($r['regex']) ? 'regex' : (!empty($r['case']) ? 'case' : ($this->contains_tokens($ph) ? 'tokens' : ''));
                        $preview_items[] = esc_html($ph).($badge? ' <span style="opacity:.6">['.esc_html($badge).']</span>' : '');
                    }
                    echo '<strong>'.intval($count).'</strong>';
                    echo '<div\1>' . wp_kses_post( implode(', ', $preview_items) . ( $count>3 ? '…' : '' ) ) . '</div>';
                }
                if ( !empty(get_post_meta($post_id, self::META_NO_OUT, true)) ){
                    echo '<div style="margin-top:4px"><span class="dashicons dashicons-lock" style="vertical-align:middle"></span> <span style="opacity:.8">'.esc_html__('No outgoing links', 'internal-external-link-manager-premium').'</span></div>';
                }
            }, 10, 2);
        }
    }
}

endif;

new BeeClear_ILM();
