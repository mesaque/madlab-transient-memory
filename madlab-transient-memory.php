<?php
/**
 * @package Mad Lab Brazil Transient Enhancement
 */
/*
 * Plugin Name: MadLab Brazil Transient Enhancement
 * Plugin URI:  https://madlabbrazil.com/
 * Description: This plugin is intended to overwrite the standard transient used in WordPress with more power.
 * Version:     1.1.0
 * Author:      Mesaque Silva
 * Author URI:  http://mesaquesoares.blogspot.com.br/
 * License:     GPLv2 or later
 * Text Domain: MadLabTransientMemory
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

if ( ! function_exists( 'add_action' ) )
	exit;

class MadLabBrazil_Transient
{
	protected static $instance = null;

	/**
	 * PHP Extension are not installed
	 * http://php.net/manual/pt_BR/book.memcache.php
	 */
	public $memcache = null;

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
		if ( null == self::$instance )
			self::$instance = new self;

		return self::$instance;
	}

	public function __construct()
	{
		$this->set_memcache();
	}

	public function set_memcache()
	{
		if ( function_exists( 'memcache_connect' ) )
			$this->memcache = $this->memcache_resource();
	}

	public static function create_table()
	{
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$queries = "
			CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}madlabbrazil_transient` (
				`id`    INT(11)  UNSIGNED NOT NULL,
				`value` TEXT 		      NOT NULL,
				`date`  INT(20)   	      NOT NULL,
				PRIMARY KEY (`id`)
			) {$charset} ENGINE = MyISAM;
		";

		require_once ABSPATH . '/wp-admin/includes/upgrade.php';

		dbDelta( $queries );
	}

	public static function delete_table()
	{
		global $wpdb;

		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}madlabbrazil_transient`" );
	}

	public function memcache_resource()
	{
		return @memcache_connect( self::HOST, self::PORT );
	}

	public function get_transient( $query, $expiration_time = 7200 )
	{
		global $wpdb;

		$_query = $query;

		if ( ! is_string( $query ) )
			$_query = json_encode( $query );

		$identification = $this->get_identification( $_query );
		$date           = current_time( 'timestamp' );

		$sql_prepare = $wpdb->prepare(
			"SELECT `value`
			 FROM `{$wpdb->prefix}madlabbrazil_transient`
			 WHERE `id`    = %d
			 	AND `date` > %d
			",
			$identification,
			$date
		);
		$result = $wpdb->get_var( $sql_prepare );

		unset( $sql_prepare );

		if ( null === $result ) {
			self::set_transient( $query, $expiration_time );
			$sql_prepare = $wpdb->prepare(
				"SELECT `value`
				 FROM `{$wpdb->prefix}madlabbrazil_transient`
				 WHERE `id` = %d
				",
				$identification
			);
			$result = $wpdb->get_var( $sql_prepare );

			return unserialize( $result );
		}

		return unserialize( $result );
	}

	public function set_transient( $query, $expiration_time = 7200 )
	{
		global $wpdb;

		$_query = $query;
		$string = true;

		if ( ! is_string( $query ) ):
			$string = false;
			$_query = json_encode( $query );
		endif;

		$identification = $this->get_identification( $_query );
		$results        = ( $string ) ? $wpdb->get_results( $query ) : new WP_Query( $query );

		//Delete some old register
		$wpdb->delete( "{$wpdb->prefix}madlabbrazil_transient", array( 'id' => $identification ) );

		$result = $wpdb->insert(
			"{$wpdb->prefix}madlabbrazil_transient",
			array(
				'id'    => $identification,
				'value' => serialize( $results ),
				'date'  => ( current_time( 'timestamp' ) + ( (int) $expiration_time ) ),
			),
			array(
			 	'%d',
			 	'%s',
			 	'%d',
			)
		);
	}

	public function get_transient_memcache( $query, $expiration_time = 7200 )
	{
		$_query = $query;

		//fallback on normal dba cache
		if ( null === $this->memcache )
			return $this->get_transient( $query, $expiration_time );

		if ( ! is_string( $query ) )
			$_query = json_encode( $query );

		$identification = $this->get_identification( $_query );
		$result         = $this->memcache->get( $identification );

		if ( false === $result ) :
			$this->set_transient_memcache( $query, $expiration_time );
			return $this->get_transient_memcache( $query, $expiration_time );
		endif;

		return unserialize( $result );
	}

	public function set_transient_memcache( $query, $expiration_time = 7200 )
	{
		global $wpdb;

		//fallback on normal dba cache
		if ( null === $this->memcache )
			return $this->set_transient( $query, $expiration_time );

		$_query = $query;
		$string = true;

		if ( ! is_string( $query ) ) :
			$string = false;
			$_query = json_encode( $query );
		endif;

		$identification = $this->get_identification( $_query );
		$this->memcache->delete( $identification );
		$results        = ( $string ) ? $wpdb->get_results( $query ) : new WP_Query( $query );

		$this->memcache->set( $identification, serialize( $results ), 0, (int) $expiration_time  );
	}

	public function delete_transient( $query )
	{
		global $wpdb;

		$_query = $query;

		if ( ! is_string( $query ) )
			$_query = json_encode( $query );

		$identification = $this->get_identification( $_query );
		$wpdb->delete( "{$wpdb->prefix}madlabbrazil_transient", array( 'id' => $identification ) );
	}

	public function delete_transient_memcache( $query )
	{
		global $wpdb;

		$_query = $query;

		if ( ! is_string( $query ) )
			$_query = json_encode( $query );

		$identification = $this->get_identification( $_query );
		$this->memcache->delete( $identification );
	}

	public function get_identification( $query )
	{
		$filtered       = preg_replace( self::PATTERN, '', $query );
		$checksum       = crc32( $filtered );
		$identification = sprintf( '%u', $checksum );

		return intval( $identification );
	}

	public static function handle_get_transient( $query, $expiration_time = 7200 )
	{
		$madlabbrazil = MadLabBrazil_Transient::get_instance();

		if ( null !== $madlabbrazil->memcache )
			return $madlabbrazil->get_transient_memcache( $query, $expiration_time );

		return $madlabbrazil->get_transient( $query, $expiration_time );
	}

	public static function handle_set_transient( $query, $expiration_time = 7200 )
	{
		$madlabbrazil = MadLabBrazil_Transient::get_instance();

		if ( null !== $madlabbrazil->memcache )  :
			$madlabbrazil->set_transient_memcache( $query, $expiration_time );
			return;
		endif;

		$madlabbrazil->set_transient( $query, $expiration_time );
	}
}
register_activation_hook( __FILE__, array( 'MadLabBrazil_Transient', 'create_table' ) );
register_deactivation_hook( __FILE__, array( 'MadLabBrazil_Transient', 'delete_table' ) );
