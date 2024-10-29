<?php

/*
Plugin Name: Advanced Caching
Plugin URI: http://foo.bar/
Description: Cache post queries.
Version: 0.1
Author: Automattic
Author URI: http://automattic.com/
*/

function query_cache_key ( $limits ) {
//	var_dump($limits);
	$GLOBALS['query_cache_key'] = md5( $limits );
}
add_filter( 'posts_selection', 'query_cache_key' );

function blank_if_cached( $v ) {
	$query_id_cache = wp_cache_get( "query_where", 'adv_post_cache' );
	if ( !$query_id_cache )
		return $v;

	if ( !isset( $query_id_cache[ $GLOBALS['query_cache_key'] ] ) )
		return $v;

	return '';
}

function lame_hack_if_cached( $v ) {
	if ( $v && ( !$v = blank_if_cached( $v ) ) )
		return ' /* */ ';
	return $v;
}
	
add_filter( 'posts_join_request', 'blank_if_cached' );
add_filter( 'posts_groupby_request', 'blank_if_cached' );
add_filter( 'posts_orderby_request', 'blank_if_cached' );
add_filter( 'post_limits_request', 'lame_hack_if_cached' ); // we still need the FOUND_ROWS stuff to run
add_filter( 'found_posts_query', 'blank_if_cached' );

function make_ids_if_cached( $where ) {
	global $wpdb;

	$cached = wp_cache_get( "query_where", 'adv_post_cache' );

//	var_dump($query_cache_key, $cached );

	if ( !isset( $cached[ $GLOBALS['query_cache_key'] ] ) )
		return $where;

	// but if we have the IDs
	// remove IDs we can cache
	$to_grab = array();
	foreach ( $cached[ $GLOBALS['query_cache_key'] ] as $post_id ) {
		$value = wp_cache_get( $post_id, 'posts' );
		if ( !is_object( $value ) )
			$to_grab[] = $post_id;
	}

	$new = '';
	if ( $to_grab ) {
		$new = " AND $wpdb->posts.ID IN ( " . join( ',', $to_grab ) . ' ) ';
	} else { // if all the posts are cached, kill the query later
		add_filter( 'posts_request', 'blank_posts_request_once' );
	}
	return $new;
}
add_filter( 'posts_where_request', 'make_ids_if_cached' );

function blank_posts_request_once() {
	remove_filter( 'posts_request', 'blank_posts_request_once' );
	return '';
}

function reorder_posts_from_cache( $posts ) {
	// the final problem is that mysql returns the posts in the order of their ID, asc, rather than the order we cached them in
	// so here we juggle the array back how it started
	$cached = wp_cache_get( "query_where", 'adv_post_cache' );
//	var_dump($cached[ $query_cache_key ] );
	if ( !isset( $cached[ $GLOBALS['query_cache_key'] ] ) )
		return $posts;
	if ( !$posts )
		$posts = array();

	$post_ids = $cached[ $GLOBALS['query_cache_key'] ];

	$got_ids = $to_get = array();
	foreach ( $posts as $p )
		$got_ids[] = $p->ID;
	$to_get = array_diff( $post_ids, $got_ids );
	foreach ( $to_get as $post_id ) {
		$post = wp_cache_get( $post_id, 'posts' );
		if ( $post )
			$posts[] = $post;
	}

	if ( 1 == count( $post_ids ) ) // no needto reorder if there's just one
		return $posts;

	
	foreach ( $posts as $p ) {
		$loc = array_search( $p->ID, $post_ids );
		$new_posts[ $loc ] = $p;
	}
	ksort( $new_posts );

	return $new_posts;
}
add_filter( 'posts_results', 'reorder_posts_from_cache' );

function dumpit( $v ) {
	var_dump($v);
	return $v;
}
//add_filter( 'posts_request', 'dumpit' );

function cached_posts_found( $v ) {
	$found = wp_cache_get( 'posts_found', 'adv_post_cache' );

	// "it must have been" = "we think it probably was"
	// If it's set, then it must have been an IN query and $v is wrong
	if ( isset( $found[ $GLOBALS['query_cache_key'] ] ) )
		return $found[ $GLOBALS['query_cache_key'] ];

	// If it's not set, it must have been fresh query, so $v must be right.
	$found[ $GLOBALS['query_cache_key'] ] = $v;
	wp_cache_set( 'posts_found', $found, 'adv_post_cache' );
	return $v;
}
add_filter( 'found_posts', 'cached_posts_found' );

function prime_post_cache( $posts ) {
	$post_id_array = array();
	foreach ( $posts as $p )
		$post_id_array[] = $p->ID;

	if ( !$post_id_array )
		return array();

	// Set post ID / where cache
	$cached = wp_cache_get( "query_where", 'adv_post_cache' );
	if ( !isset( $cached[ $GLOBALS['query_cache_key'] ] ) ) {
		$cached[ $GLOBALS['query_cache_key'] ] = $post_id_array;
		wp_cache_set( "query_where", $cached, 'adv_post_cache' );
	}

	return $posts;
}
add_filter( 'the_posts', 'prime_post_cache' );

function clear_advanced_cache($arg = '') {
	wp_cache_delete( 'query_where', 'adv_post_cache' );
	wp_cache_delete( 'posts_found', 'adv_post_cache' );
}

add_action( 'clean_term_cache', 'clear_advanced_cache' );
add_action( 'clean_post_cache', 'clear_advanced_cache' );

?>
