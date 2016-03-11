<?php
/**
 * @package Mad Lab Brazil Memory Transient
 */
/*
Plugin Name: MadLab Transient Memory
Plugin URI: https://madlabbrazil.com/
Description:This plugin intent to overwrite the standart transient user in WordPress with more power.
Version: 1.0
Author: Mesaque Silva
Author URI: http://mesaquesoares.blogspot.com.br/
License: GPLv2 or later
Text Domain: MadLabTransientMemory
*/

/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.

Copyright 2015-2016 Mad Laboratories Brazil.
*/

class MadLabBrazil_Transient{

	/**
	 * Instance of this class.
	 *
	 * @since 1.0
	 * @var object
	 */
	private static $instance = null;
	const PATTERN = "#\(|\)|'|\,|\=|\.| |\*#";

	/**
	 * Adds needed actions to create submenu and page
	 *
	 * @since 1.0
	 * @return void
	 */
	private function __construct()
	{
	}

	/**
	 * Return an instance of this class.
	 *
	 * @since 1.0
	 * @return object A single instance of this class.
	 */
	public static function get_instance()
	{
		if ( null == self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	public static function create_table()
	{
		global $wpdb;


		$create_table = "
			CREATE TABLE IF NOT EXISTS {$wpdb->prefix}madlabbrazil_transient (
				id           INT(11)    	UNSIGNED	NOT NULL	           ,
				value          VARCHAR(500) 	    	NOT NULL               ,
				date      INT(20)   			        NOT NULL	           ,
				PRIMARY KEY (id)
			) DEFAULT CHARACTER SET utf8 ENGINE = MEMORY;";

		require_once ABSPATH . '/wp-admin/includes/upgrade.php';

		dbDelta( $create_table );
	}

	public static function get_transient( $query, $expiration_time = 7200 )
	{
		global $wpdb;
		$filtered       = preg_replace( self::PATTERN, '', $query );
		$identification = crc32( $filtered );
		$date           = current_time( 'timestamp' );

		$result = $wpdb->get_var( "SELECT value FROM {$wpdb->prefix}madlabbrazil_transient WHERE id = '{$identification}' AND date > {$date} " );
		if( null == $result ){
			self::set_transient( $query, $expiration_time );
			$result = $wpdb->get_var( "SELECT value FROM {$wpdb->prefix}madlabbrazil_transient WHERE id = '{$identification}'" );
			return unserialize( $result );
		}
		return unserialize( $result );
	}
	public static function set_transient( $query, $expiration_time = 7200 )
	{
		global $wpdb;
		$filtered       = preg_replace( self::PATTERN, '', $query );
		$identification = crc32( $filtered );

		$results = $wpdb->get_results( $query );

		//Delete some old register
		$wpdb->delete( "{$wpdb->prefix}madlabbrazil_transient", array( 'id' => $identification ) );

		$result = $wpdb->insert(
			 "{$wpdb->prefix}madlabbrazil_transient",
			 array(
					'id'    => $identification,
					'value' => serialize( $results ),
					'date'  => ( current_time( 'timestamp' ) + ( (int) $expiration_time ) )
			 	),
			 array(
			 	'%d',
			 	'%s',
			 	'%d'
			 	)
			);
	}

}
new MadLabBrazil_Transient();
register_activation_hook( __FILE__, array( 'MadLabBrazil_Transient', 'create_table' ) );