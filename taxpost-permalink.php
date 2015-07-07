<?php

/*
Plugin Name: Taxonomy > Post Hierarchical Permalinks
Description: Helper plugin to create a hierarchical category/post permliank structure for regsitered posts and taxonomies. Can create permalinks in the form /category-name/post/post-name. Useful for e-commerce-like sites where you want the full url to reflect the post/product's location in the category structure.
Author: Ben Huson
Version: 0.2
*/

/*
@todo:
1. Add canonical support?
*/

/*
@notes:
1. Call taxpostpermlink_register() to register your post type and taxonomy.
   Use plugins_loaded hook.
2. You must apply the taxpostpermlink_post_type_slug filter to your post type slug when
   you register it, passing the default slug and the post type you regsitered with
   taxpostpermlink_register() as the 2 paramaters.
*/

class TaxPostPermlink {
	
	var $plugin_dir = '';
	var $plugin_basename = '';
	var $post_types = array();

	/**
	 * Terms Exist
	 *
	 * Stores checks for wether a term exists to prevent multiple database requests
	 * per page load.
	 *
	 * @access  private
	 *
	 * @var  array  Terms that exist.
	 */
	public static $_terms_exist = array();

	/**
	 * Constructor
	 */
	public function TaxPostPermlink() {

		// Plugin settings
		$this->plugin_dir = dirname( __FILE__ );
		$this->plugin_basename = plugin_basename( __FILE__ );

		// Rewrite rules and slugs
		add_filter( 'taxpostpermlink_post_type_slug', array( $this, 'filter_post_type_slug' ), 8, 3 );
		add_filter( 'rewrite_rules_array', array( $this, 'rewrite_rules_array' ) );

		// Adjust permalinks
		add_filter( 'post_type_link', array( $this, 'post_type_link' ), 8, 3 );
		add_filter( 'attachment_link', array( $this, 'attachment_link' ), 8, 2 );

		// Activation / Deactivation
		register_activation_hook( __FILE__, array( &$this, 'activate_plugin' ) );
		register_deactivation_hook( __FILE__, array( &$this, 'deactivate_plugin' ) );

		// Next / Previous post links
		add_filter( 'get_next_post_join', array( $this, 'get_adjacent_post_join' ), 11, 3 );
		add_filter( 'get_previous_post_join', array( $this, 'get_adjacent_post_join' ), 11, 3 );
		add_filter( 'get_next_post_where', array( $this, 'get_next_post_where' ), 11, 3 );
		add_filter( 'get_previous_post_where', array( $this, 'get_previous_post_where' ), 11, 3 );

	}

	/**
	 * Filter taxpost adjacent nav link
	 *
	 * @param   string   $join_sql             The JOIN clause in the SQL.
	 * @param   boolean  $in_same_cat          Whether post should be in a same taxonomy term.
	 * @param   array    $excluded_categories  Array of excluded term IDs
	 * @return  string                         SQL JOIN clause.
	 */
	public function get_adjacent_post_join( $join_sql, $in_same_cat, $excluded_categories ) {

		global $wpdb, $post;

		if ( ! array_key_exists( $post->post_type, $this->post_types ) ) {
			return $join_sql;
		}

		if ( $in_same_cat || ! empty( $excluded_categories ) ) {
			$tax = $this->post_types[ $post->post_type ]['taxonomy'];
			$join = " INNER JOIN $wpdb->term_relationships AS tr ON p.ID = tr.object_id INNER JOIN $wpdb->term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";
			$current_cat = get_query_var( $tax );
			if ( $in_same_cat && ! empty( $current_cat ) ) {
				$term_data = get_term_by( 'slug', $current_cat, $tax );
				$cat_array = wp_get_object_terms( $post->ID, $tax, array( 'fields' => 'ids' ) );
				$join .= " AND tt.taxonomy = '" . $tax . "' AND tt.term_id = " . absint( $term_data->term_id );
			}

			return $join;

		}

		return $join_sql;

	}

	/**
	 * Filter taxpost next link
	 *
	 * @param   string   $where_sql            The WHERE clause in the SQL.
	 * @param   boolean  $in_same_cat          Whether post should be in a same taxonomy term.
	 * @param   array    $excluded_categories  Array of excluded term IDs
	 * @return  string                         SQL WHERE clause.
	 */
	public function get_next_post_where( $where_sql, $in_same_cat, $excluded_categories ) {

		return $this->get_adjacent_post_where( $where_sql, $in_same_cat, $excluded_categories );

	}

	/**
	 * Filter taxpost previous link
	 *
	 * @param   string   $where_sql            The WHERE clause in the SQL.
	 * @param   boolean  $in_same_cat          Whether post should be in a same taxonomy term.
	 * @param   array    $excluded_categories  Array of excluded term IDs
	 * @return  string                         SQL WHERE clause.
	 */
	public function get_previous_post_where( $where_sql, $in_same_cat, $excluded_categories ) {

		return $this->get_adjacent_post_where( $where_sql, $in_same_cat, $excluded_categories, true );

	}

	/**
	 * Filter taxpost adjacent link
	 *
	 * @param   string   $where_sql            The WHERE clause in the SQL.
	 * @param   boolean  $in_same_cat          Whether post should be in a same taxonomy term.
	 * @param   array    $excluded_categories  Array of excluded term IDs
	 * @param   boolean  $previous             Is previous link?
	 * @return  string                         SQL WHERE clause.
	 */
	public function get_adjacent_post_where( $where_sql, $in_same_cat, $excluded_categories, $previous = false ) {

		global $wpdb, $post;

		if ( ! array_key_exists( $post->post_type, $this->post_types ) ) {
			return $where_sql;
		}

		if ( $in_same_cat || ! empty( $excluded_categories ) ) {

			$tax = $this->post_types[ $post->post_type ]['taxonomy'];
			$cat_array = wp_get_object_terms( $post->ID, $tax, array( 'fields' => 'ids' ) );
			$posts_in_ex_cats_sql = "AND tt.taxonomy = '" . $tax . "'";
			if ( ! empty( $excluded_categories ) ) {
				$excluded_categories = array_map( 'intval', explode( ' and ', $excluded_categories ) );
				if ( ! empty( $cat_array ) ) {
					$excluded_categories = array_diff( $excluded_categories, $cat_array );
					$posts_in_ex_cats_sql = '';
				}
				if ( ! empty( $excluded_categories ) ) {
					$posts_in_ex_cats_sql = " AND tt.taxonomy = '" . $tax . "' AND tt.term_id NOT IN (" . implode( $excluded_categories, ',' ) . ')';
				}
			}
			$op = $previous ? '<' : '>';

			return $wpdb->prepare( "WHERE p.post_date $op %s AND p.post_type = %s AND p.post_status = 'publish' $posts_in_ex_cats_sql", $post->post_date, $post->post_type );

		}

		return $where_sql;

	}

	/**
	 * Activate Plugin
	 */
	public function activate_plugin() {

		flush_rewrite_rules();

	}

	/**
	 * Deactivate Plugin
	 */
	public function deactivate_plugin() {

		flush_rewrite_rules();

	}

	/**
	 * Register a post type and taxonomy
	 *
	 * @param   string  $post_type  Post type.
	 * @param   string  $taxonomy   Taxonomy.
	 * @param   string  $sep        Seperator.
	 */
	public function register( $post_type, $taxonomy, $sep = null ) {

		if ( ! $sep ) {
			$sep = $post_type;
		}

		$this->post_types[ $post_type ] = array(
			'taxonomy' => $taxonomy,
			'sep'      => $sep
		);

	}

	/**
	 * Create format for post type slug with placeholder
	 *
	 * @todo More checks and simplify this function
	 *
	 * @param   string  $slug       Slug.
	 * @param   string  $post_type  Post type.
	 * @return  string              Altered slug.
	 */
	public function filter_post_type_slug( $slug, $post_type ) {

		global $taxpostpermlink_post_types;

		if ( isset( $this->post_types[ $post_type ] ) ) {

			$taxonomy = $this->post_types[ $post_type ]['taxonomy'];
			$t = get_taxonomy( $taxonomy );

			if ( $t && isset( $t->rewrite['slug'] ) ) {
				$taxpostpermlink_post_types[ $post_type ] = $taxonomy;
				$slug = trailingslashit( $t->rewrite['slug'] ) . '%' . $taxonomy . '%';
			}

		}

		return $slug;

	}

	/**
	 * Post Type Link
	 *
	 * Alters a post type link to include the taxonomy URl structure.
	 * Handles flat or hierarchical taxonomy URLs.
	 *
	 * @param   string   $permalink  Post permalink.
	 * @param   WP_Post  $post       Instance of a post object.
	 * @param   boolean  $leavename  Optional. Whether to keep the post name.
	 * @return  string               Updated permalink.
	 */
	public function post_type_link( $permalink, $post, $leavename = false ) {

		global $wp_query;

		$post_id = $post->ID;
		$post = get_post( $post_id );

		// Only for taxpost registered post types
		if ( ! array_key_exists( $post->post_type, $this->post_types ) ) {
			return $permalink;
		}

		$permalink_structure = get_option( 'permalink_structure' );

		// The following is mostly the same conditions used for posts,
		// but restricted to taxpost registered items.
		if ( '' != $permalink_structure && ! in_array( $post->post_status, array( 'draft', 'pending' ) ) ) {
			$taxonomy = $this->post_types[ $post->post_type ]['taxonomy'];
			$t = get_taxonomy( $taxonomy );

			if ( $t ) {

				// Get post's category slugs
				$product_categories = wp_get_object_terms( $post_id, $taxonomy );

				if ( ! is_wp_error( $product_categories ) ) {
					$product_category_slugs = array( );
					foreach ( $product_categories as $product_category ) {
						$product_category_slugs[] = $product_category->slug;
					}

					// If the product is associated with multiple categories, determine which one to pick
					$current_cat = get_query_var( $t->query_var );
					$current_cat = TaxPostPermlink::get_queried_term();
					$current_cat_arr = explode( '/', $current_cat );
					$current_cat = $current_cat_arr[ count( $current_cat_arr ) - 1 ];
					if ( count( $product_categories ) > 1 ) {
						if ( in_array( $current_cat, $product_category_slugs ) ) {
							$category_slug = $current_cat;
						} else {
							$category_slug = $product_categories[0]->slug;
						}
					} elseif ( count( $product_categories ) == 1 ) {

						// If the product is associated with only one category, we only have one choice
						$category_slug = $product_categories[0]->slug;

					}
				}

				// Handle hierarchical taxonomy structure
				if ( isset( $category_slug ) || ! empty( $category_slug ) ) {
					$cat_term = get_term_by( 'slug', $category_slug, $taxonomy );
					$cat_anc = get_ancestors( $cat_term->term_id, $taxonomy );
					foreach ( $cat_anc as $anc ) {
						$cat_t = get_term( $anc, $taxonomy );
						$category_slug = $cat_t->slug . '/' . $category_slug;
					}
				}

				// We need a default category slug if none set
				if ( ! isset( $category_slug ) || empty( $category_slug ) ) {
					$category_slug = 'uncategorized';
				}

				// Strings to replace
				$rewritecode = array(
					'%' . $taxonomy . '%',
					$leavename ? '' : '%postname%',
				);

				// Replacement strings
				$rewritereplace = array(
					$category_slug,
					$post->post_name
				);

				// Build are permalink structure
				$our_permalink_structure = trailingslashit( $t->rewrite['slug'] ) . '%' . $taxonomy . '%/' . $this->post_types[$post->post_type]['sep'] . '/%postname%/';

				$permalink = str_replace( $rewritecode, $rewritereplace, $our_permalink_structure );
				$permalink = user_trailingslashit( $permalink, 'single' );
				$permalink = home_url( '/' ) . ltrim( $permalink, '/' );
			}

		}

		return apply_filters( 'get_permalink', $permalink, $post->ID );

	}

	/**
	 * Add rewrite rules for taxpost post type URLs
	 *
	 * @param   array  $rewrite_rules  Rewrite rules.
	 * @return  array                  Rewrite rules.
	 */
	public function rewrite_rules_array( $rewrite_rules ) {

		foreach ( $this->post_types as $key => $pt ) {
			$t = get_taxonomy( $pt['taxonomy'] );
			$new_rewrite_rules[ $t->rewrite['slug'] . '/(.+?)/' . $pt['sep'] . '/([^/]+)?$' ] = 'index.php?post_type=' . $key . '&' . $pt['taxonomy'] . '=$matches[1]&' . $key . '=$matches[2]';
			$rewrite_rules = array_merge( $new_rewrite_rules, $rewrite_rules );
		}

		return $rewrite_rules;

	}

	/**
	 * Attachment Link
	 *
	 * Fix attachment links.
	 *
	 * @param   string  $link  Attachment URL.
	 * @param   int     $id    Attachment ID.
	 * @return  string         Attachment URL.
	 */
	public function attachment_link( $link, $id ) {

		$p = get_post( $id );

		if ( absint( $p->post_parent ) > 0 ) {
			$pp = get_post( $p->post_parent );

			foreach( $this->post_types as $key => $pt ) {
				if ( $pp->post_type == $key ) {
					return trailingslashit( get_permalink( $p->post_parent ) ) . 'attachment/' . $p->post_name;
				}
			}

		}

		return $link;

	}

	/**
	 * Get Supported Post Types
	 * 
	 * Returns post types that have been registered with a hierarchical taxonomy permalink.
	 *
	 * @return  array  Post types.
	 */
	public static function get_supported_post_types() {

		global $TaxPostPermlink;

		return array_keys( $TaxPostPermlink->post_types );

	}

	/**
	 * Get Supported Post Type
	 * 
	 * Returns supported post type settings.
	 *
	 * @return  array  Post type settings.
	 */
	public static function get_supported_post_type( $post_type ) {

		global $TaxPostPermlink;

		return $TaxPostPermlink->post_types[ $post_type ];

	}

	/**
	 * Get Supported Post Type Taxonomies
	 * 
	 * Returns taxonomies that are supported by a post type.
	 *
	 * @return  array  Taxonomies.
	 */
	public static function get_supported_post_type_taxonomies( $post_type ) {

		$post_type_settings = TaxPostPermlink::get_supported_post_type( $post_type );

		return (array) $post_type_settings['taxonomy'];

	}

	/**
	 * Get Queried Taxonomy
	 *
	 * Tried to get the taxonomy when viewing a single post type page for a supported post type.
	 * Falls back to get_query_var( 'taxonomy' ) if not a supported post type page.
	 *
	 * @return  string  Taxonomy query var.
	 */
	public static function get_queried_taxonomy() {

		global $TaxPostPermlink;

		$post_types = TaxPostPermlink::get_supported_post_types();

		// If viewing a supported single post type page...
		if ( is_singular( $post_types ) ) {

			$taxonomies = TaxPostPermlink::get_supported_post_type_taxonomies( get_post_type( get_queried_object() ) );

			// Loop through possible taxonomies until we find a URL that matches
			foreach ( $taxonomies as $t ) {
				$taxonomy = get_taxonomy( $t );
				$base_url = home_url( '/' ) . trailingslashit( $taxonomy->rewrite['slug'] );
				$current_url = TaxPostPermlink::get_current_url();

				// If the taxonomy URL stem matches the current URL, we have a taxonomy.
				if ( $base_url == substr( $current_url, 0, strlen( $base_url ) ) ) {
					return $t;
				}

			}

		}

		return get_query_var( 'taxonomy' );

	}

	/**
	 * Get Queried Term
	 *
	 * Tried to get the current term when viewing a single post type page for a supported post type.
	 * Falls back to get_query_var( $taxonomy ) if not a supported post type page.
	 *
	 * @return  string  Term query var.
	 */
	public static function get_queried_term() {

		// Taxonomy pages should just return current term.
		if ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();
			return $term->slug;
		}

		$taxonomy = TaxPostPermlink::get_queried_taxonomy();
		$post_types = TaxPostPermlink::get_supported_post_types();

		// If viewing a supported single post type page...
		if ( is_singular( $post_types ) && ! empty( $taxonomy ) ) {

			$tax_object = get_taxonomy( $taxonomy );

			$post = get_queried_object();
			$post_type_settings = TaxPostPermlink::get_supported_post_type( get_post_type( $post ) );

			$base_url = home_url( '/' ) . trailingslashit( $tax_object->rewrite['slug'] );
			$base_sep = '/' . $post_type_settings['sep'] . '/' . $post->post_name . '/';
			$current_url = TaxPostPermlink::get_current_url();

			$term_url = substr( $current_url, strlen( $base_url ) );
			$term_url = explode( $base_sep, $term_url );
			if ( count( $term_url ) > 0 ) {
				$term = explode( '/', $term_url[0] );
				if ( count( $term ) > 0 ) {
					$term = array_reverse( $term );
					foreach ( $term as $t ) {
						if ( TaxPostPermlink::term_exists( $t, $taxonomy ) ) {
							return $t;
						}	
					}
				}
			}

		}

		return '';

	}

	/**
	 * Term Exists
	 *
	 * Proxy for the WordPress term_exists() function but caching this for each public page load
	 * so multiple calls do not add extra database requests.
	 *
	 * @param   string   $term      Term slug.
	 * @param   string   $taxonomy  Taxonomy slug.
	 * @return  boolean             Tem exists?
	 */
	public static function term_exists( $term, $taxonomy ) {

		// Don't cache admin calls.
		if ( is_admin() ) {
			return term_exists( $term, $taxonomy );
		}

		// If cached for this request save another DB query.
		if ( isset( TaxPostPermlink::$_terms_exist[ $taxonomy ] ) && isset( TaxPostPermlink::$_terms_exist[ $taxonomy ][ $term ] ) ) {
			return TaxPostPermlink::$_terms_exist[ $taxonomy ][ $term ];
		}

		$exists = term_exists( $term, $taxonomy );
		TaxPostPermlink::$_terms_exist[ $taxonomy ][ $term ] = $exists;

		return $exists;

	}

	/**
	 * Get Current URL
	 *
	 * @return  string  Current URL.
	 */
	public static function get_current_url() {

		return trim( home_url( '/' ), '/' ) . add_query_arg();

	}

}

function taxpostpermlink_register( $post_type, $taxonomy, $sep = null ) {
	global $TaxPostPermlink;
	$TaxPostPermlink->register( $post_type, $taxonomy, $sep );
}

global $TaxPostPermlink;
$TaxPostPermlink = new TaxPostPermlink();

?>