<?php
/**
 * A class for links tree manipulation.
 *
 * @package Cache-Warmer
 */

namespace Cache_Warmer;

use ActionScheduler_DBStore;
use ActionScheduler_Store;
use Exception;
use WP_Error;

/**
 * Contains tree modification methods.
 */
final class Tree {

	/**
	 * Read the first terminal entry along the tree's first-child path.
	 *
	 * Follows insertion order, descending only into non-empty arrays. Does not
	 * mutate the tree or persist queue rows. Keys contain a URL and optional
	 * serialized metadata; missing or non-array metadata becomes an empty array.
	 * Callers must supply a non-empty tree: empty input reaches the end without
	 * returning and violates the existing array return type.
	 *
	 * @param array $array Non-empty nested tree keyed by URL and serialized metadata.
	 * @param int   $depth Depth assigned to entries at the current nesting level.
	 * @return array{link:string,depth:int,meta:array} First leaf's URL, depth and metadata.
	 * @throws \TypeError When empty input cannot satisfy the array return type.
	 */
	public static function get_the_first_leaf_data( $array, $depth = 0 ): array {
		foreach ( $array as $key => $value ) { // Only the first entry is inspected at each level.
			if ( is_array( $value ) && count( $value ) ) {
				return self::get_the_first_leaf_data( $value, ++$depth );
			} else {
				list( $url, $meta ) = self::parse_leaf_key( $key );

				return array(
					'link'  => $url,
					'depth' => $depth,
					'meta'  => maybe_unserialize( $meta ),
				);
			}
		}
	}

	/**
	 * Persist a nested child tree beneath an existing warm-up log row.
	 *
	 * Decodes each URL/metadata key and delegates idempotent row admission to DB.
	 * Descendants use the returned child ID, not the original parent ID. Processes
	 * entries in array order; no transaction or rollback is added here, so an
	 * exception can leave earlier admissions persisted. Empty input is a no-op.
	 *
	 * @param int   $warmup_id Warm-up run ID owning the queue rows.
	 * @param int   $log_id    Existing parent log row ID.
	 * @param int   $depth     Parent depth; immediate children use depth plus one.
	 * @param array $children Nested tree keyed by URL and serialized metadata.
	 * @return void
	 * @throws \RuntimeException When queue admission or its process fence fails.
	 */
	public static function add_the_first_leaf_children( int $warmup_id, int $log_id, int $depth, array $children ) {
		foreach ( $children as $child => $child_array ) {
			list( $url, $meta ) = self::parse_leaf_key( $child );

			$new_child_id = DB::create_link_to_warm(
				$warmup_id,
				$url,
				$depth + 1,
				0,
				$meta,
				$log_id
			);

			if ( $new_child_id && ! empty( $child_array ) ) {
				self::add_the_first_leaf_children( $warmup_id, $new_child_id, $depth + 1, $child_array );
			}
		}
	}

	/**
	 * Persist sibling entries and descendants beneath a warm-up log row.
	 *
	 * Reverses insertion order at each level when requested. At the recursion
	 * limit, admits that level's entries but ignores their descendants. Otherwise,
	 * recurses only when admission returns a nonzero child ID. Delegates duplicate
	 * handling and process fencing to DB; no transaction or rollback is provided,
	 * so a later failure can leave earlier rows persisted. Empty input is a no-op.
	 *
	 * @param int   $warmup_id     Warm-up run ID owning the admitted rows.
	 * @param int   $log_id        Parent log row ID for this level's siblings.
	 * @param int   $depth         Queue depth assigned to this level's entries.
	 * @param array $siblings      Nested tree keyed by URL and serialized metadata.
	 * @param bool  $to_the_end    Whether to retain rather than reverse array order.
	 * @param int   $max_depth     Last recursion level to admit, including that level.
	 * @param int   $current_depth Current recursion level, initially zero.
	 * @return void
	 * @throws \RuntimeException When queue admission or its process fence fails.
	 */
	public static function add_the_first_leaf_siblings(
		int $warmup_id,
		int $log_id,
		int $depth,
		array $siblings,
		bool $to_the_end = true,
		int $max_depth = PHP_INT_MAX,
		int $current_depth = 0
	) {
		// If we want to prepend rather than append, reverse the order of $siblings.
		if ( ! $to_the_end ) {
			$siblings = array_reverse( $siblings, true );
		}

		// If we're already at max depth, just insert siblings here.
		if ( $current_depth >= $max_depth ) {
			foreach ( $siblings as $sibling_key => $child_array ) {
				list( $url, $meta ) = self::parse_leaf_key( $sibling_key );

				DB::create_link_to_warm(
					$warmup_id,
					$url,
					$depth,
					0,
					$meta,
					$log_id
				);
			}
			return;
		}

		// Otherwise, we have room to recurse deeper.
		foreach ( $siblings as $sibling_key => $child_array ) {
			list( $url, $meta ) = self::parse_leaf_key( $sibling_key );

			// Create the sibling row, child of $log_id.
			$new_sibling_id = DB::create_link_to_warm(
				$warmup_id,
				$url,
				$depth,
				0,
				$meta,
				$log_id
			);

			// If it has children in the array, recurse.
			// We pass $new_sibling_id as the parent for these children.
			if ( $new_sibling_id && ! empty( $child_array ) ) {
				self::add_the_first_leaf_siblings(
					$warmup_id,
					$new_sibling_id,
					$depth + 1,
					$child_array,
					$to_the_end,
					$max_depth,
					$current_depth + 1
				);
			}
		}
	}

	/**
	 * Decode one trusted internal URL/serialized-metadata key.
	 *
	 * Splits only on the first pipe, preserving pipes inside the metadata suffix.
	 * Missing, invalid or non-array metadata becomes an empty array. This is not
	 * a URL validator or an untrusted-input decoder: WordPress unserialization
	 * runs before the array check and can invoke serialized objects' magic methods.
	 *
	 * @param string $key Tree key generated by Leaf_Only_Subtree.
	 * @return array{0:string,1:array} Unmodified URL prefix and decoded array metadata.
	 */
	private static function parse_leaf_key( string $key ): array {
		$parts = explode( '|', $key, 2 );
		$meta  = isset( $parts[1] ) ? maybe_unserialize( $parts[1] ) : array();

		return array(
			$parts[0],
			is_array( $meta ) ? $meta : array(),
		);
	}

    /**
     * Deletes the first leaf of the tree.
     *
     * @param array $tree Tree.
     *
     * @return void
     */
    public static function delete_the_first_leaf( array &$tree ) {
        foreach ( $tree as $key => &$value ) { // We don't use iteration here of course but just get the first array item.
            if ( is_array( $value ) && count( $value ) ) {
                self::delete_the_first_leaf( $value );
            } else {
                unset( $tree[ $key ] );
            }
            return;
        }
    }

    /**
     * Make a tree of arbitrary length.
     *
     * With placeholders added, and links pre-populated to it.
     *
     * @param array  $array                      Array.
     * @param string $placeholder                Placeholder.
     * @param int    $depths_left                How many depths left.
     * @param array  $links_to_populate_the_tree Links to populate the tree (array where key is link and value is depth of the link).
     * @param int    $current_depth              Current depth. Should not be passed.
     */
    public static function make_a_tree_of_arbitrary_depth( &$array, $placeholder, $depths_left, $links_to_populate_the_tree, $current_depth = 0 ) {
        if ( ! $depths_left ) {
            return;
        }

        $array[ $placeholder ] = [];

        ++ $current_depth;

        foreach ( $links_to_populate_the_tree as $link => $link_depth ) {
            if ( $current_depth === $link_depth ) {
                $array[ $placeholder ][ $link ] = [];
            }
        }

        if ( -- $depths_left ) {
            self::make_a_tree_of_arbitrary_depth( $array[ $placeholder ], $placeholder, $depths_left, $links_to_populate_the_tree, $current_depth );
        }
    }
}
