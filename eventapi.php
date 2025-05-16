<?php 
/**
 * Plugin Name: Event API
 * Description: Provides a CRUD API for managing events.
 * Version: 1.1
 * Author: Sushant Khadilkar
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class EventManagerAPI {
    public function __construct() {
        add_action('init', [$this, 'registerTaxonomy']);
        add_action('init', [$this, 'registerPostType']);
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
    }

    public function registerTaxonomy() {
        register_taxonomy('em_event_category', 'em_event', [
            'labels' => [
                'name' => 'Event Categories',
                'singular_name' => 'Event Category',
            ],
            'public' => true,
            'hierarchical' => true,
            'show_in_rest' => true,
        ]);
    }

    public function registerPostType() {
        register_post_type('em_event', [
            'labels' => [
                'name' => 'Events',
                'singular_name' => 'Event',
            ],
            'public' => false,
            'show_ui' => true,
            'supports' => ['title', 'editor', 'custom-fields'],
            'show_in_rest' => true,
            'taxonomies' => ['em_event_category'],
            'capability_type' => 'post',
            'capabilities' => [
                'create_posts' => 'do_not_allow',
            ],
            'map_meta_cap' => true,
        ]);
    }

    public function registerRestRoutes() {
        register_rest_route('events', '/create', [
            'methods' => 'POST',
            'callback' => [$this, 'createEvent'],
            'permission_callback' => [$this, 'isAdmin'],
        ]);

        register_rest_route('events', '/update', [
            'methods' => 'POST',
            'callback' => [$this, 'updateEvent'],
            'permission_callback' => [$this, 'isAdmin'],
        ]);

        register_rest_route('events', '/delete', [
            'methods' => 'POST',
            'callback' => [$this, 'deleteEvent'],
            'permission_callback' => [$this, 'isAdmin'],
        ]);

        register_rest_route('events', '/show', [
            'methods' => 'GET',
            'callback' => [$this, 'getEvent'],
            'permission_callback' => [$this, 'isAdmin'],
        ]);

        register_rest_route('events', '/list', [
            'methods' => 'GET',
            'callback' => [$this, 'listEvents'],
            'permission_callback' => [$this, 'isAdmin'],
        ]);
    }

    public function isAdmin() {
        return current_user_can('manage_options');
    }

    public function validateEventData($data) {
        $required = ['title', 'event_start_time', 'event_end_time', 'description'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new WP_Error('missing_field', "Missing field: $field", ['status' => 400]);
            }
        }
        return true;
    }

    public function createEvent($request) {
        $data = $request->get_json_params();
        $valid = $this->validateEventData($data);
        if (is_wp_error($valid)) return $valid;

        $post_id = wp_insert_post([
            'post_type' => 'em_event',
            'post_title' => sanitize_text_field($data['title']),
            'post_content' => sanitize_textarea_field($data['description']),
            'post_status' => 'publish',
        ]);

        if (is_wp_error($post_id)) return $post_id;

        update_post_meta($post_id, 'event_start_time', sanitize_text_field($data['event_start_time']));
        update_post_meta($post_id, 'event_end_time', sanitize_text_field($data['event_end_time']));

        if (!empty($data['category'])) {
            wp_set_object_terms($post_id, sanitize_text_field($data['category']), 'em_event_category');
        }

        return ['success' => true, 'id' => $post_id];
    }

    public function updateEvent($request) {
        $data = $request->get_json_params();
        if (empty($data['id'])) return new WP_Error('missing_id', 'Missing event ID', ['status' => 400]);

        $post_id = intval($data['id']);
        if (get_post_type($post_id) !== 'em_event') return new WP_Error('invalid_id', 'Invalid Event ID', ['status' => 404]);

        $post_data = ['ID' => $post_id];
        if (!empty($data['title'])) $post_data['post_title'] = sanitize_text_field($data['title']);
        if (!empty($data['description'])) $post_data['post_content'] = sanitize_textarea_field($data['description']);

        wp_update_post($post_data);

        if (!empty($data['event_start_time'])) update_post_meta($post_id, 'event_start_time', sanitize_text_field($data['event_start_time']));
        if (!empty($data['event_end_time'])) update_post_meta($post_id, 'event_end_time', sanitize_text_field($data['event_end_time']));
        if (!empty($data['category'])) wp_set_object_terms($post_id, sanitize_text_field($data['category']), 'em_event_category');

        return ['success' => true];
    }

    public function deleteEvent($request) {
        $data = $request->get_json_params();
        if (empty($data['id'])) return new WP_Error('missing_id', 'Missing event ID', ['status' => 400]);

        $post_id = intval($data['id']);
        if (get_post_type($post_id) !== 'em_event') return new WP_Error('invalid_id', 'Invalid Event ID', ['status' => 404]);

        wp_delete_post($post_id, true);
        return ['success' => true];
    }

    public function getEvent($request) {
        $id = intval($request->get_param('id'));
        $post = get_post($id);
        if (!$post || $post->post_type !== 'em_event') return new WP_Error('not_found', 'Event not found', ['status' => 404]);

        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'description' => $post->post_content,
            'event_start_time' => get_post_meta($post->ID, 'event_start_time', true),
            'event_end_time' => get_post_meta($post->ID, 'event_end_time', true),
            'category' => wp_get_post_terms($post->ID, 'em_event_category', ['fields' => 'names']),
        ];
    }

    public function listEvents($request) {
        $date = sanitize_text_field($request->get_param('date'));
        $args = [
            'post_type' => 'em_event',
            'posts_per_page' => -1,
            'meta_query' => [],
        ];

        if ($date) {
            $args['meta_query'][] = [
                'key' => 'event_start_time',
                'value' => $date,
                'compare' => 'LIKE',
            ];
        }

        $query = new WP_Query($args);
        $results = [];

        foreach ($query->posts as $post) {
            $results[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'description' => $post->post_content,
                'event_start_time' => get_post_meta($post->ID, 'event_start_time', true),
                'event_end_time' => get_post_meta($post->ID, 'event_end_time', true),
                'category' => wp_get_post_terms($post->ID, 'em_event_category', ['fields' => 'names']),
            ];
        }

        return $results;
    }
}

// Initialize the plugin
new EventManagerAPI();