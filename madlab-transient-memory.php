<?php
/**
 * @package Mad Lab Brazil Transient Enhancement
 */
/*
Plugin Name: MadLab Brazil Transient Enhancement
Plugin URI: https://madlabbrazil.com/
Description:This plugin is intended to overwrite the standard transient used in WordPress with more power.
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

	protected static $instance = null;

	public $memcache = false;
	const PATTERN = "#\(|\)|'|\,|\=|\.| |\*#";

	//Memcache
	const PORT = 11211;
	const HOST = '127.0.0.1';

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

	public function __construct()
	{

		$this->memcache = $this->memcache_resource();
	}

	public static function create_table()
	{
		global $wpdb;

		$create_table = "
			CREATE TABLE IF NOT EXISTS {$wpdb->prefix}madlabbrazil_transient (
				id           INT(11)    	UNSIGNED	NOT NULL	            ,
				value        TEXT 	    				NOT NULL     			,
				date      	 INT(20)   			        NOT NULL	            ,
				PRIMARY KEY (id)
			) DEFAULT CHARACTER SET utf8 ENGINE = MyISAM;";

		require_once ABSPATH . '/wp-admin/includes/upgrade.php';

		dbDelta( $create_table );
	}
	public static function delete_table()
	{
		global $wpdb;
		$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}madlabbrazil_transient");
	}

	public function memcache_resource()
	{
		return @memcache_connect( self::HOST, self::PORT );
	}

	public function get_transient( $query, $expiration_time = 7200 )
	{
		global $wpdb;
		$_query = $query;
		if ( ! is_string( $query ) ):
			$_query = json_encode( $query );
		endif;
		$filtered       = preg_replace( self::PATTERN, '', $_query );
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
	public function set_transient( $query, $expiration_time = 7200 )
	{
		global $wpdb;

		$_query = $query;
		if ( ! is_string( $query ) ):
			$_query = json_encode( $query );
		endif;
		$filtered       = preg_replace( self::PATTERN, '', $_query );
		$identification = crc32( $filtered );
		$results = ( is_string( $query ) ) ? $wpdb->get_results( $query ): new WP_Query( $query );

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

	public function get_transient_memcache( $query, $expiration_time = 7200 )
	{
		$_query = $query;
		if ( ! is_string( $query ) ):
			$_query = json_encode( $query );
		endif;

		$filtered       = preg_replace( self::PATTERN, '', $_query );
		$identification = crc32( $filtered );
		$result = $this->memcache->get( $identification );
		if( false === $result ):
			$this->set_transient_memcache( $query, $expiration_time );
			return $this->get_transient_memcache( $query, $expiration_time );
		endif;
		return unserialize( $result );
	}

	public function set_transient_memcache( $query, $expiration_time = 7200 )
	{
		global $wpdb;

		$_query = $query;
		if ( ! is_string( $query ) ):
			$_query = json_encode( $query );
		endif;
		$filtered       = preg_replace( self::PATTERN, '', $_query );
		$identification = crc32( $filtered );
		$this->memcache->delete( $identification );
		$results = ( is_string( $query ) ) ? $wpdb->get_results( $query ): new WP_Query( $query );
		$this->memcache ->set( $identification, serialize( $results ), 0, (int) $expiration_time  );
	}

	public function delete_transient( $query )
	{
		global $wpdb;
		$_query = $query;
		if ( ! is_string( $query ) ):
			$_query = json_encode( $query );
		endif;
		$filtered       = preg_replace( self::PATTERN, '', $_query );
		$identification = crc32( $filtered );

		$wpdb->delete( "{$wpdb->prefix}madlabbrazil_transient", array( 'id' => $identification ) );

	}
	public function delete_transient_memcache( $query )
	{
		global $wpdb;
		$_query = $query;
		if ( ! is_string( $query ) ):
			$_query = json_encode( $query );
		endif;
		$filtered       = preg_replace( self::PATTERN, '', $_query );
		$identification = crc32( $filtered );

		$this->memcache->delete( $identification );

	}
	public static function handle_get_transient( $query, $expiration_time = 7200 )
	{
		$madlabbrazil = MadLabBrazil_Transient::get_instance();
		if( false !== $madlabbrazil->memcache )	return $madlabbrazil->get_transient_memcache( $query, $expiration_time );

		return $madlabbrazil->get_transient( $query, $expiration_time );
	}
	public static function handle_set_transient( $query, $expiration_time = 7200 )
	{
		$madlabbrazil = MadLabBrazil_Transient::get_instance();
		if( false !== $madlabbrazil->memcache ):
			$madlabbrazil->set_transient_memcache( $query, $expiration_time );
			return;
		endif;

		$madlabbrazil->set_transient( $query, $expiration_time );
	}

}
register_activation_hook( __FILE__, array( 'MadLabBrazil_Transient', 'create_table' ) );
register_deactivation_hook( __FILE__, array( 'MadLabBrazil_Transient', 'delete_table' ) );