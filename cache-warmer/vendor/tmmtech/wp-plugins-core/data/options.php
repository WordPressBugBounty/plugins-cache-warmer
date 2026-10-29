<?php
/**
 * Defines plugins options.
 *
 * Format:
 *  'default':  Default option value.           If not set, equals false.
 *  'autoload': Whether to autoload the option. If not set, equals true.
 *  'type':     Option type. Optional. Can be: "image_id" (For reset/import/export of images).
 *
 * @package wp-plugins-core
 */

return [

	/*
	 * Durable per-consumer retry reservation. Retained across deactivation so
	 * reactivation cannot bypass the remote notification failure cooldown.
	 */
	'wp-plugins-core-notification-fetch-state' => array(
		'default'  => array(),
		'autoload' => false,
	),

    /*
     * The list of data of all fetched notifications.
     */
    'wp-plugins-core-notifications'                        => [
        'default' => [],
    ],

    /*
     * The list of IDs of all viewed notifications.
     */
    'wp-plugins-core-viewed-notifications'                 => [
        'default' => [],
    ],
];
